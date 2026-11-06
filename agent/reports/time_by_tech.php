<?php

require_once "includes/inc_all_reports.php";
require_once __DIR__ . "/includes/report_headline.php";

enforceUserPermission('module_support');

// NOTE: secondsToTime() is now provided centrally by functions.php (added in the
// reporting Wave 1). The local copy that used to live here was dead code (this page
// renders time via SQL SEC_TO_TIME, never via secondsToTime) and its redeclaration
// fataled the page once functions.php gained the shared helper — removed.

if (isset($_GET['year'])) {
    $year = intval($_GET['year']);
} else {
    $year = date('Y');
}

if (isset($_GET['month'])) {
    $month = intval($_GET['month']);
} else {
    $month = date('m');
}

$sql_ticket_years = mysqli_query($mysqli, "SELECT DISTINCT YEAR(ticket_created_at) AS ticket_year FROM tickets ORDER BY ticket_year DESC");

// Department restriction: only tickets of the user's departments count toward every figure below.
$scope_ticket = \ITFlow\Reports\ReportScope::clause('ticket_client_id');

$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");

$sql_users = mysqli_query($mysqli, "
                SELECT users.user_id, user_name FROM users
                LEFT JOIN user_settings on users.user_id = user_settings.user_id
                WHERE user_type = 1
                    AND user_status = 1
                    AND user_archived_at IS NULL
                ORDER BY user_name DESC"
);
// TODO: Maybe try and filter this to just users with the support module perm

$tbt_rows = [];
while ($agent_row = mysqli_fetch_assoc($sql_users)) {
    $user_id = intval($agent_row['user_id']);
    $user_name = nullable_htmlentities($agent_row['user_name']);

    // Get tickets in period that are still assigned to the technician/agent
    $sql_ticket_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS ticket_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND ticket_assigned_to = $user_id$scope_ticket");
    $row = mysqli_fetch_assoc($sql_ticket_count);
    $ticket_raised_count = intval($row['ticket_count']);

    // Get unique tickets in period that the agent replied to/touched
    $sql_tickets_touched = mysqli_query($mysqli, "
        SELECT COUNT(DISTINCT ticket_id) AS tickets_touched
        FROM (
            -- Tickets the agent replied to
            SELECT ticket_reply_ticket_id AS ticket_id
            FROM ticket_replies
            WHERE YEAR(ticket_reply_created_at) = $year AND ticket_reply_by = $user_id
              AND ticket_reply_ticket_id IN (SELECT ticket_id FROM tickets WHERE 1 = 1 $scope_ticket)

            UNION

            -- Tickets the agent opened
            SELECT ticket_id
            FROM tickets
            WHERE YEAR(ticket_created_at) = $year AND ticket_created_by = $user_id$scope_ticket

            UNION

            -- Tickets the agent closed
            SELECT ticket_id
            FROM tickets
            WHERE YEAR(ticket_created_at) = $year AND ticket_closed_by = $user_id$scope_ticket
        )
        AS tickets_touched
    ");

    $row = mysqli_fetch_assoc($sql_tickets_touched);
    $tickets_touched = intval($row['tickets_touched']);


    // Calculate total time tracked towards tickets in the period (for this agent)
    $sql_time = mysqli_query($mysqli, "SELECT SUM(TIME_TO_SEC(ticket_reply_time_worked)) AS total_secs, SEC_TO_TIME(SUM(TIME_TO_SEC(ticket_reply_time_worked))) as total_time FROM ticket_replies LEFT JOIN tickets ON tickets.ticket_id = ticket_replies.ticket_reply_ticket_id WHERE YEAR(ticket_created_at) = $year AND ticket_reply_by = $user_id AND ticket_reply_time_worked IS NOT NULL$scope_ticket");
    $row = mysqli_fetch_assoc($sql_time);
    $ticket_total_time_worked = nullable_htmlentities($row['total_time']);

    $tbt_rows[] = ['name' => $user_name, 'assigned' => $ticket_raised_count, 'touched' => $tickets_touched, 'secs' => intval($row['total_secs'] ?? 0), 'time' => $ticket_total_time_worked];
}
usort($tbt_rows, fn($x, $y) => $y['secs'] <=> $x['secs'] ?: strcmp($x['name'], $y['name']));
$tbt_total = array_sum(array_column($tbt_rows, 'secs'));

if ($tbt_rows && $tbt_total > 0) {
    $topr = $tbt_rows[0]; $share0 = round($topr['secs'] / $tbt_total * 100);
    $active = count(array_filter($tbt_rows, fn($r) => $r['secs'] > 0));
    $idle = array_map(fn($r) => $r['name'], array_filter($tbt_rows, fn($r) => $r['secs'] === 0));
    reportHeadline('fa-user-clock', $topr['name'] . " logged the most time in $year: " . round($topr['secs'] / 3600, 1) . "h ($share0% of the team)",
        $active . ' of ' . count($tbt_rows) . ' technicians logged time.' . ($idle ? ' No time from: ' . implode(', ', $idle) . '.' : ''),
        $share0 >= 60 ? 'warning' : 'primary', ['Team hours' => round($tbt_total / 3600, 1), 'Technicians' => $active]);
} else {
    reportHeadline('fa-info-circle', "No time was logged in $year", 'Time appears here once technicians add time to ticket replies.', 'secondary');
}

?>

    <style nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
  /* Nested report cards: no second inner padding, and headers may wrap so wide tables fit without sideways scroll. */
  .report-nested .card .card .card-body { padding: 0; }
  .report-nested .card .card .card-header { border-bottom: 1px solid var(--if-border, rgba(0,0,0,.08)); }
  .report-nested table th { white-space: normal; vertical-align: bottom; }
</style>
<div class="report-nested"><div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-life-ring me-2"></i>Time Logged By Technician</h3>
        </div>
        <div class="card-body">
            <form class="mb-3 d-flex align-items-center gap-2">
                <label class="form-label mb-0 text-muted small" for="tbt-year">Year</label>
                <select class="form-select form-select-sm auto-submit-select" name="year" id="tbt-year" style="max-width:9rem">
                    <?php
                    while ($row = mysqli_fetch_assoc($sql_ticket_years)) {
                        $ticket_year = intval($row['ticket_year']); ?>
                        <option <?php if ($year == $ticket_year) { ?> selected <?php } ?> > <?php echo $ticket_year; ?></option>
                    <?php } ?>
                </select>
            </form>

            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-chart-area me-2"></i>Yearly (<?php echo $year; ?>)</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive-sm">
                        <table class="table table-striped">
                            <thead>
                            <tr>
                                <th>Technician</th>
                                <th class="text-end">Tickets assigned</th>
                                <th class="text-end">Tickets touched</th>
                                <th class="text-end">Total time worked <i>(H:M:S)</i></th>
                                <th>Share of team time</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            foreach ($tbt_rows as $tr) {
                                $share = $tbt_total > 0 ? round($tr['secs'] / $tbt_total * 100) : 0;
                                ?>
                                <tr>
                                    <td><?php echo $tr['name']; ?></td>
                                    <td class="text-end"><?php echo $tr['assigned']; ?></td>
                                    <td class="text-end"><?php echo $tr['touched']; ?></td>
                                    <td class="text-end"><?php echo $tr['time'] ?: '00:00:00'; ?></td>
                                    <td style="min-width:11rem">
                                        <?php if ($tr['secs'] > 0) { ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height:8px" role="progressbar" aria-label="Share of logged time" aria-valuenow="<?php echo $share; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?php echo $share; ?>%"></div></div>
                                            <span class="small text-secondary text-nowrap" style="width:2.5rem;text-align:right"><?php echo $share; ?>%</span>
                                        </div>
                                        <?php } else { ?><span class="small text-secondary">no time logged</span><?php } ?>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>


        </div>
    </div>

</div>
<?php
require_once "../../includes/footer.php";
