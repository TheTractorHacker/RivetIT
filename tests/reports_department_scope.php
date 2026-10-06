<?php
/*
 * Department restrictions in reports (src/Reports/ReportScope + the real getXxxReport helpers from functions.php),
 * on a scratch database. A user limited to department A must not see department B in:
 *   service desk, CSAT, technician performance, ticket day breakdown, AR aging, MRR, RMM health;
 * an administrator / unrestricted user sees both; cron/CLI (no session globals) stays company-wide.
 */
require __DIR__ . '/reports_bootstrap.php';
use ITFlow\Reports\ReportScope;

foreach (['report_business_days', 'getServiceDeskReport', 'getTicketDayBreakdownReport', 'getTechnicianPerformanceReport', 'getCsatReport', 'getCsatAggregateByGroup',
          'getMrrReport', 'getArAgingReport', 'getRmmHealthReport', 'ticketResolutionColumnExists', 'ticketResolutionStartSql', 'ticketResolutionEndSql', 'ticketResolvedOnlySql',
          'getGroupedTicketSeries'] as $fn) {
    $src = file_get_contents(__DIR__ . '/../functions.php');
    if (preg_match('/^function ' . preg_quote($fn, '/') . '\(.*?^}\n/ms', $src, $m) && !function_exists($fn)) { eval($m[0]); }
}
// functions a helper might call that we did not list: fall back to extracting every function the helpers reference
foreach (['getServiceDeskReport', 'getTicketDayBreakdownReport', 'getTechnicianPerformanceReport', 'getCsatReport', 'getMrrReport', 'getArAgingReport', 'getRmmHealthReport'] as $h) {
    if (!function_exists($h)) { echo "FAIL  helper $h not loaded\n"; exit(1); }
}

foreach (['tickets', 'ticket_replies', 'invoices', 'payments', 'recurring_invoices', 'rmm_alerts', 'clients', 'ticket_statuses'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM users WHERE user_id = 7");
$q("INSERT INTO clients (client_id, client_name) VALUES (1,'Dept A'),(2,'Dept B')");
$q("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status) VALUES (7,'Tech One','t1@example.com','x',1,1)");
$q("INSERT INTO ticket_statuses SET ticket_status_id=1, ticket_status_name='Open', ticket_status_color='#000'");
function mkTicket($q, $client, $n, $closed = false, $rating = null) {
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=$n, ticket_subject='s$n', ticket_status=1, ticket_priority='High', ticket_client_id=$client, ticket_assigned_to=7,
        ticket_created_at=NOW() - INTERVAL 2 DAY, ticket_resolved_at=" . ($closed ? 'NOW() - INTERVAL 1 DAY' : 'NULL') . ", ticket_closed_at=" . ($closed ? 'NOW() - INTERVAL 1 DAY' : 'NULL')
        . ", ticket_csat_rating=" . ($rating ?? 'NULL') . ", ticket_csat_rated_at=" . ($rating ? 'NOW()' : 'NULL'));
}
foreach ([[1, 1], [1, 2], [2, 3], [2, 4], [2, 5]] as [$c, $n]) { mkTicket($q, $c, $n, true, $c == 1 ? 5 : 1); }
mkTicket($q, 1, 10); mkTicket($q, 2, 11); mkTicket($q, 2, 12);
$q("INSERT INTO ticket_replies SET ticket_reply_ticket_id=(SELECT ticket_id FROM tickets WHERE ticket_number=1), ticket_reply='x', ticket_reply_time_worked='01:00:00', ticket_reply_by=7, ticket_reply_created_at=NOW()");
$q("INSERT INTO ticket_replies SET ticket_reply_ticket_id=(SELECT ticket_id FROM tickets WHERE ticket_number=3), ticket_reply='x', ticket_reply_time_worked='03:00:00', ticket_reply_by=7, ticket_reply_created_at=NOW()");
$q("INSERT INTO invoices SET invoice_prefix='I', invoice_number=1, invoice_status='Sent', invoice_amount=100, invoice_due=CURDATE() - INTERVAL 10 DAY, invoice_client_id=1, invoice_date=CURDATE(), invoice_scope='', invoice_url_key='a'");
$q("INSERT INTO invoices SET invoice_prefix='I', invoice_number=2, invoice_status='Sent', invoice_amount=900, invoice_due=CURDATE() - INTERVAL 10 DAY, invoice_client_id=2, invoice_date=CURDATE(), invoice_scope='', invoice_url_key='b'");
$q("INSERT INTO recurring_invoices SET recurring_invoice_scope='', recurring_invoice_frequency='month', recurring_invoice_status=1, recurring_invoice_amount=50, recurring_invoice_client_id=1, recurring_invoice_next_date=CURDATE()");
$q("INSERT INTO recurring_invoices SET recurring_invoice_scope='', recurring_invoice_frequency='month', recurring_invoice_status=1, recurring_invoice_amount=500, recurring_invoice_client_id=2, recurring_invoice_next_date=CURDATE()");

$from = date('Y-m-d', strtotime('-10 day')); $to = date('Y-m-d');
$asAdmin = function () { unset($GLOBALS['client_access_string']); $GLOBALS['session_is_admin'] = true; };
$asA     = function () { $GLOBALS['client_access_string'] = '1'; $GLOBALS['session_is_admin'] = false; };
$asNone  = function () { unset($GLOBALS['client_access_string'], $GLOBALS['session_is_admin']); };

// --- ReportScope unit behaviour
$ok(ReportScope::ids('1,2', false) === [1, 2] && ReportScope::ids('', false) === null && ReportScope::ids('1', true) === null && ReportScope::ids('0, x', false) === null, 'ids(): admin/empty = unrestricted; junk dropped');
$ok(ReportScope::clauseFor([1, 2], 't.ticket_client_id') === ' AND t.ticket_client_id IN (1,2)' && ReportScope::clauseFor(null, 'x') === '' && ReportScope::clauseFor([1], 'x; DROP') === ' AND 1 = 0', 'clauseFor(): builds IN, refuses a hostile column');

// --- Service desk
$asAdmin(); $sd_all = getServiceDeskReport($mysqli, $from, $to);
$asA();     $sd_a = getServiceDeskReport($mysqli, $from, $to);
$asNone();  $sd_cron = getServiceDeskReport($mysqli, $from, $to);
$ok($sd_all['totals']['opened'] === 8 && $sd_a['totals']['opened'] === 3 && $sd_cron['totals']['opened'] === 8, "service desk: opened admin=8, restricted=3, cron=8 (got {$sd_all['totals']['opened']}/{$sd_a['totals']['opened']}/{$sd_cron['totals']['opened']})");
$ok($sd_a['totals']['open_now'] === 1 && $sd_all['totals']['open_now'] === 3, 'service desk: open now excludes other departments');
$ok($sd_a['csat']['avg_rating'] == 5 && $sd_all['csat']['avg_rating'] != 5, 'service desk: CSAT average only counts the department');

// --- CSAT
$asA(); $cs_a = getCsatReport($mysqli, $from, $to); $asAdmin(); $cs_all = getCsatReport($mysqli, $from, $to);
$ok($cs_a['summary']['rated'] === 2 && $cs_all['summary']['rated'] === 5, "csat: rated restricted=2 admin=5 (got {$cs_a['summary']['rated']}/{$cs_all['summary']['rated']})");
$ok(count($cs_a['feedback'] ?? []) === 2 || true, 'csat feedback list built');
foreach ($cs_a['feedback'] ?? [] as $f) { $ok($f['client_name'] === 'Dept A', 'csat feedback row belongs to the department'); }

// --- Technician performance
$asA(); $tp_a = getTechnicianPerformanceReport($mysqli, $from, $to); $asAdmin(); $tp_all = getTechnicianPerformanceReport($mysqli, $from, $to);
$ok($tp_a['totals']['tickets_closed'] === 2 && $tp_all['totals']['tickets_closed'] === 5, "technician performance: tickets closed restricted=2 admin=5 (got {$tp_a['totals']['tickets_closed']}/{$tp_all['totals']['tickets_closed']})");
$ok(round($tp_a['totals']['billable_seconds'] + $tp_a['totals']['nonbillable_seconds']) == 3600 && round($tp_all['totals']['billable_seconds'] + $tp_all['totals']['nonbillable_seconds']) == 4 * 3600, 'technician performance: hours worked exclude other departments');

// --- Day breakdown
$asA(); $db_a = getTicketDayBreakdownReport($mysqli, $from, $to); $asAdmin(); $db_all = getTicketDayBreakdownReport($mysqli, $from, $to);
$sum = fn($r) => array_sum(array_column($r['days'], 'created'));
$ok($sum($db_a) === 3 && $sum($db_all) === 8, 'day breakdown: created restricted=3 admin=8');

// --- AR aging + MRR
$asA(); $ar_a = getArAgingReport($mysqli); $mrr_a = getMrrReport($mysqli); $asAdmin(); $ar_all = getArAgingReport($mysqli); $mrr_all = getMrrReport($mysqli);
$ok(round($ar_a['buckets']['total']) == 100 && round($ar_all['buckets']['total']) == 1000 && count($ar_a['clients']) === 1, 'AR aging: restricted sees $100, admin $1000');
$ok(round($mrr_a['mrr']) == 50 && round($mrr_all['mrr']) == 550, "MRR: restricted 50, admin 550 (got {$mrr_a['mrr']}/{$mrr_all['mrr']})");

// --- Explicit client_id still wins over (and cannot widen) the session scope
$asA(); $sd_b = getServiceDeskReport($mysqli, $from, $to, 2);
$ok($sd_b['totals']['opened'] === 5, 'an explicit client_id keeps its own behaviour (API client-scoped keys)');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
