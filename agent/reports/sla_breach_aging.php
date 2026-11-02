<?php

require_once "includes/inc_all_reports.php";
require_once __DIR__ . "/includes/report_table.php";

enforceUserPermission('module_support');

use ITFlow\Reports\Queries\SlaBreachReport;

// Date range: defaults to this month; "all time" is bounded to the oldest ticket.
if (!$date_range_explicit) {
    $date_range = dateRangeFromRequest(['canned_date' => 'thismonth']);
    $dtf = $date_range->from();
    $dtt = $date_range->to();
}
$report_from = $dtf;
$report_to   = $dtt;
if ($report_from === '1970-01-01') {
    $earliest = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT DATE(MIN(ticket_created_at)) AS d FROM tickets WHERE ticket_archived_at IS NULL"));
    $report_from = !empty($earliest['d']) ? $earliest['d'] : date('Y-01-01');
}
if ($report_to === '2099-12-31') {
    $report_to = date('Y-m-d');
}

$sla = SlaBreachReport::data($mysqli, $report_from, $report_to);
$tot = $sla['totals'];
$pct = static fn ($v) => $v === null ? '-' : $v . '%';

render_page_header('SLA Breach and Aging', 'Tickets created in the range that missed a response or resolution target, and how long the open ones have been overdue.');
?>

<form class="mb-3 d-print-none">
    <label class="mb-1 d-block">Date range</label>
    <?php echo dateRangePickerField($date_range, 'canned_date', ['default' => 'thismonth', 'dates' => [$report_from, $report_to]]); ?>
</form>
<p class="text-muted small">Showing tickets created <strong><?php echo nullable_htmlentities($report_from); ?></strong> to <strong><?php echo nullable_htmlentities($report_to); ?></strong> that carry an SLA target. SLA-exempt categories and open tickets with a paused clock are not counted as overdue.</p>

<div class="it-stat-grid mb-3">
    <?php render_stat_card('SLA Compliance', $pct($tot['compliance_pct']), 'fas fa-stopwatch', ($tot['compliance_pct'] !== null && $tot['compliance_pct'] < 90) ? 'danger' : 'info'); ?>
    <?php render_stat_card('Tickets with an SLA', (string) $tot['tickets'], 'fas fa-life-ring', 'slate'); ?>
    <?php render_stat_card('Response breaches', (string) $tot['response_breached'], 'fas fa-reply', 'warning'); ?>
    <?php render_stat_card('Resolution breaches', (string) $tot['resolution_breached'], 'fas fa-check-double', 'danger'); ?>
</div>

<?php
rpt_table('Summary', ['Measure', 'Value'], [
    ['Tickets with an SLA target', $tot['tickets']],
    ['Response targets set', $tot['response_total']],
    ['Response breaches', $tot['response_breached']],
    ['Resolution targets set', $tot['resolution_total']],
    ['Resolution breaches', $tot['resolution_breached']],
    ['Tickets with any breach', $tot['any_breached']],
    ['SLA compliance', $pct($tot['compliance_pct'])],
]);
?>
<div class="card mb-3"><div class="card-header py-2"><h3 class="card-title mb-0">Open tickets by time overdue</h3></div><div class="card-body px-0 pb-2"><?php rpt_bars($sla['aging']); ?></div></div>
<?php
rpt_table('Overdue aging (open tickets past their SLA)', ['Overdue for', 'Tickets'], array_map(static fn ($b) => [$b['label'], $b['count']], $sla['aging']));
$grp = static fn (array $rows) => array_map(static fn ($r) => [$r['label'], $r['tickets'], $r['response_breached'], $r['resolution_breached'], $r['breached'], $r['compliance_pct'] === null ? '-' : $r['compliance_pct'] . '%'], $rows);
$gh = ['Name', 'Tickets', 'Response breaches', 'Resolution breaches', 'Tickets breached', 'Compliance'];
rpt_table('Breaches by priority', $gh, $grp($sla['by_priority']));
rpt_table('Breaches by technician', $gh, $grp($sla['by_tech']));
rpt_table('Breaches by department', $gh, $grp($sla['by_client']));

$rows = [];
foreach ($sla['tickets'] as $t) {
    $kind = [];
    if ($t['resp_b']) { $kind[] = 'Response'; }
    if ($t['reso_b']) { $kind[] = 'Resolution'; }
    $rows[] = [rpt_ticket_cell($t), $t['ticket_subject'], $t['client_name'] ?? '', $t['user_name'] ?? 'Unassigned', $t['ticket_priority'] ?? '',
        implode(' + ', $kind), $t['open'] ? 'Open' : 'Closed late', $t['open'] ? rpt_hours_text($t['overdue_hours']) : ''];
}
rpt_table('Breached tickets' . (count($sla['tickets']) >= $sla['limit'] ? ' (first ' . $sla['limit'] . ')' : ''), ['Ticket', 'Subject', 'Department', 'Technician', 'Priority', 'Missed', 'State', 'Overdue'], $rows, 'No SLA breaches in this range.');

require_once "../../includes/footer.php";
