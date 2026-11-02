<?php

require_once "includes/inc_all_reports.php";
require_once __DIR__ . "/includes/report_table.php";

enforceUserPermission('module_support');

use ITFlow\Reports\Queries\TicketBacklogReport;

$weeks = isset($_GET['weeks']) ? max(2, min(intval($_GET['weeks']), 52)) : 12;
$bl = TicketBacklogReport::data($mysqli, $weeks);
$tot = $bl['totals'];

render_page_header('Ticket Backlog', 'Everything open right now, how old it is, who holds it, and how the backlog moved week by week.');
?>

<form class="mb-3 d-print-none row g-2 align-items-end">
    <div class="col-auto">
        <label class="mb-1 d-block" for="bl-weeks">Trend length</label>
        <select class="form-control form-control-sm auto-submit-select" id="bl-weeks" name="weeks">
            <?php foreach ([4, 8, 12, 26, 52] as $w) { ?>
                <option value="<?php echo $w; ?>"<?php echo $w === $weeks ? ' selected' : ''; ?>><?php echo $w; ?> weeks</option>
            <?php } ?>
        </select>
    </div>
</form>

<div class="it-stat-grid mb-3">
    <?php render_stat_card('Open Tickets', (string) $tot['open'], 'fas fa-life-ring', 'info'); ?>
    <?php render_stat_card('Unassigned', (string) $tot['unassigned'], 'fas fa-user-slash', $tot['unassigned'] > 0 ? 'danger' : 'slate'); ?>
    <?php render_stat_card('Older than 7 days', (string) $tot['older_7d'], 'fas fa-hourglass-half', 'warning'); ?>
    <?php render_stat_card('Older than 30 days', (string) $tot['older_30d'], 'fas fa-calendar-times', $tot['older_30d'] > 0 ? 'danger' : 'slate'); ?>
</div>

<?php
rpt_table('Summary', ['Measure', 'Value'], [
    ['Open tickets', $tot['open']],
    ['Unassigned', $tot['unassigned']],
    ['Older than 7 days', $tot['older_7d']],
    ['Older than 30 days', $tot['older_30d']],
    ['Average age (days)', $tot['avg_age_days'] ?? '-'],
]);
?>
<div class="card mb-3"><div class="card-header py-2"><h3 class="card-title mb-0">Open tickets by age</h3></div><div class="card-body px-0 pb-2"><?php rpt_bars($bl['age']); ?></div></div>
<?php
rpt_table('Age of open tickets', ['Age', 'Tickets'], array_map(static fn ($b) => [$b['label'], $b['count']], $bl['age']));
$g = static fn (array $rows) => array_map(static fn ($r) => [$r['label'], $r['count'], $r['oldest_days']], $rows);
rpt_table('Backlog by status', ['Status', 'Open tickets', 'Oldest (days)'], $g($bl['by_status']));
rpt_table('Backlog by priority', ['Priority', 'Open tickets', 'Oldest (days)'], $g($bl['by_priority']));
rpt_table('Backlog by technician', ['Technician', 'Open tickets', 'Oldest (days)'], $g($bl['by_tech']));
rpt_table('Backlog by department', ['Department', 'Open tickets', 'Oldest (days)'], $g($bl['by_client']));
rpt_table('Weekly backlog trend', ['Week ending', 'Opened', 'Closed', 'Backlog at week end'], array_map(static fn ($w) => [$w['week_ending'], $w['opened'], $w['closed'], $w['backlog']], $bl['trend']));
rpt_table('Oldest open tickets', ['Ticket', 'Subject', 'Department', 'Technician', 'Status', 'Priority', 'Created', 'Age (days)'],
    array_map(static fn ($t) => [rpt_ticket_cell($t), $t['ticket_subject'], $t['client_name'] ?? '', $t['user_name'] ?? 'Unassigned', $t['ticket_status_name'] ?? '', $t['ticket_priority'] ?? '', substr((string) $t['ticket_created_at'], 0, 10), $t['age_days']], $bl['oldest']),
    'No open tickets.');

require_once "../../includes/footer.php";
