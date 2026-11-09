<?php

require_once "includes/inc_all_reports.php";
require_once __DIR__ . "/includes/report_headline.php";

enforceUserPermission('module_support');

if (isset($_GET['year'])) {
    $year = intval($_GET['year']);
} else {
    $year = date('Y');
}

if (isset($_GET['month'])) {
    $month = intval($_GET['month']);
} else {
    // Default to the latest month that actually has tickets, so the monthly table is never an empty shell on the 1st.
    $month = intval(date('m'));
    $rs_lm = mysqli_query($mysqli, "SELECT MAX(MONTH(ticket_created_at)) AS m FROM tickets WHERE YEAR(ticket_created_at) = " . intval($year) . \ITFlow\Reports\ReportScope::clause('ticket_client_id'));
    $lm = $rs_lm ? mysqli_fetch_assoc($rs_lm) : null;
    if ($lm && $lm['m']) { $month = intval($lm['m']); }
}

$sql_ticket_years = mysqli_query($mysqli, "SELECT DISTINCT YEAR(ticket_created_at) AS ticket_year FROM tickets ORDER BY ticket_year DESC");

$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . \ITFlow\Reports\ReportScope::clause('client_id') . " ORDER BY client_name ASC");

// Headline: which department drives the load, and who waits longest for a fix (one aggregate query, scoped like the table).
$hl_rs = mysqli_query($mysqli, "SELECT c.client_name, COUNT(t.ticket_id) AS raised, SUM(t.ticket_priority = 'High') AS high,
        AVG(CASE WHEN t.ticket_resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, t.ticket_created_at, t.ticket_resolved_at) END) AS avg_resolve
    FROM tickets t JOIN clients c ON c.client_id = t.ticket_client_id
    WHERE YEAR(t.ticket_created_at) = " . intval($year) . " AND c.client_archived_at IS NULL" . \ITFlow\Reports\ReportScope::clause('c.client_id') . "
    GROUP BY c.client_id, c.client_name HAVING raised > 0 ORDER BY raised DESC");
$hl = [];
while ($hr = mysqli_fetch_assoc($hl_rs)) { $hl[] = $hr; }
if ($hl) {
    $hl_total = array_sum(array_column($hl, 'raised'));
    $top = $hl[0];
    $slow = null;
    foreach ($hl as $hr) { if ($hr['avg_resolve'] !== null && ($slow === null || $hr['avg_resolve'] > $slow['avg_resolve'])) { $slow = $hr; } }
    $pct = $hl_total ? round($top['raised'] / $hl_total * 100) : 0;
    reportHeadline('fa-building', $top['client_name'] . " raised the most tickets in $year: {$top['raised']} ($pct% of $hl_total)",
        ($slow ? 'Slowest to resolve on average: ' . $slow['client_name'] . ' (' . secondsToTime($slow['avg_resolve']) . '). ' : '') . count($hl) . ' departments raised tickets.',
        'primary', ['Departments' => count($hl), 'High priority' => array_sum(array_column($hl, 'high'))]);
} else {
    reportHeadline('fa-info-circle', "No tickets were raised in $year", 'Pick another year.', 'secondary');
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
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-life-ring me-2"></i>Tickets By Department</h3>
        </div>
        <div class="card-body">
            <form class="mb-3 d-flex flex-wrap align-items-center gap-2">
                <label class="form-label mb-0 text-muted small" for="tbd-year">Year</label>
                <select class="form-select form-select-sm auto-submit-select" name="year" id="tbd-year" style="max-width:9rem">
                    <?php
                    while ($row = mysqli_fetch_assoc($sql_ticket_years)) {
                        $ticket_year = intval($row['ticket_year']); ?>
                        <option <?php if ($year == $ticket_year) { ?> selected <?php } ?> > <?php echo $ticket_year; ?></option>
                    <?php } ?>
                </select>
                <label class="form-label mb-0 text-muted small ms-2" for="tbd-month">Month</label>
                <select class="form-select form-select-sm auto-submit-select" name="month" id="tbd-month" style="max-width:11rem">
                    <option <?php if ($month == 1) { echo 'selected'; } ?> value="1">January</option>
                    <option <?php if ($month == 2) { echo 'selected'; } ?> value="2">February</option>
                    <option <?php if ($month == 3) { echo 'selected'; } ?> value="3">March</option>
                    <option <?php if ($month == 4) { echo 'selected'; } ?> value="4">April</option>
                    <option <?php if ($month == 5) { echo 'selected'; } ?> value="5">May</option>
                    <option <?php if ($month == 6) { echo 'selected'; } ?> value="6">June</option>
                    <option <?php if ($month == 7) { echo 'selected'; } ?> value="7">July</option>
                    <option <?php if ($month == 8) { echo 'selected'; } ?> value="8">August</option>
                    <option <?php if ($month == 9) { echo 'selected'; } ?> value="9">September</option>
                    <option <?php if ($month == 10) { echo 'selected'; } ?> value="10">October</option>
                    <option <?php if ($month == 11) { echo 'selected'; } ?> value="11">November</option>
                    <option <?php if ($month == 12) { echo 'selected'; } ?> value="12">December</option>
                </select>
            </form>

            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-chart-area me-2"></i>Yearly (<?php echo $year; ?>)</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                            <tr>
                                <th>Department</th>
                                <th class="text-end">Raised</th>
                                <th class="text-end">Priority: Low</th>
                                <th class="text-end">Priority: Med</th>
                                <th class="text-end">Priority: High</th>
                                <th class="text-end">Resolved</th>
                                <th class="text-end">Total Time worked <i>(H:M:S)</i></th>
                                <th class="text-end">Avg time to respond</th>
                                <th class="text-end">Avg time to resolve</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_clients)) {
                                $client_id = intval($row['client_id']);
                                $client_name = nullable_htmlentities($row['client_name']);

                                // Calculate total tickets raised in period
                                $sql_ticket_raised_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS ticket_raised_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND ticket_client_id = $client_id");
                                $row = mysqli_fetch_assoc($sql_ticket_raised_count);
                                $ticket_raised_count = intval($row['ticket_raised_count']);

                                // Calculate total tickets raised in period that are resolved
                                $sql_ticket_resolved_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS ticket_resolved_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND ticket_client_id = $client_id AND ticket_resolved_at IS NOT NULL");
                                $row = mysqli_fetch_assoc($sql_ticket_resolved_count);
                                $ticket_resolved_count = intval($row['ticket_resolved_count']);

                                // Breakdown tickets for each priority - Low
                                $sql_low_ticket_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS low_ticket_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND ticket_client_id = $client_id AND ticket_priority = 'Low'");
                                $row = mysqli_fetch_assoc($sql_low_ticket_count);
                                $low_ticket_count = intval($row['low_ticket_count']);

                                // Breakdown tickets for each priority - Low
                                $sql_med_ticket_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS med_ticket_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND ticket_client_id = $client_id AND ticket_priority = 'Medium'");
                                $row = mysqli_fetch_assoc($sql_med_ticket_count);
                                $med_ticket_count = intval($row['med_ticket_count']);

                                // Breakdown tickets for each priority - Low
                                $sql_high_ticket_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS high_ticket_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND ticket_client_id = $client_id AND ticket_priority = 'High'");
                                $row = mysqli_fetch_assoc($sql_high_ticket_count);
                                $high_ticket_count = intval($row['high_ticket_count']);

                                // Used to calculate average time to respond to tickets that were raised in period specified
                                $sql_tickets_respond = mysqli_query($mysqli, "SELECT ticket_created_at, ticket_first_response_at FROM tickets WHERE YEAR(ticket_created_at) = $year AND ticket_client_id = $client_id");

                                // Used to calculate average time to resolve tickets that were raised in period specified
                                $sql_tickets_resolved = mysqli_query($mysqli, "SELECT ticket_created_at, ticket_resolved_at FROM tickets WHERE YEAR(ticket_created_at) = $year AND ticket_client_id = $client_id AND ticket_resolved_at IS NOT NULL");

                                // Calculate total time tracked towards tickets in the period
                                $sql_time = mysqli_query($mysqli, "SELECT SEC_TO_TIME(SUM(TIME_TO_SEC(ticket_reply_time_worked))) as total_time FROM ticket_replies LEFT JOIN tickets ON tickets.ticket_id = ticket_replies.ticket_reply_ticket_id WHERE YEAR(ticket_created_at) = $year AND ticket_client_id = $client_id AND ticket_reply_time_worked IS NOT NULL");
                                $row = mysqli_fetch_assoc($sql_time);
                                $ticket_total_time_worked = nullable_htmlentities($row['total_time']);

                                if ($ticket_raised_count > 0 || $ticket_resolved_count > 0) {

                                    // Calculate average time to respond
                                    $avg_time_to_respond = '-';
                                    $count = 0;
                                    $total = 0;
                                    while ($row = mysqli_fetch_assoc($sql_tickets_respond)) {
                                        if (!empty($row['ticket_first_response_at'])) {
                                            $openedTime = new DateTime($row['ticket_created_at']);
                                            $respondTime = new DateTime($row['ticket_first_response_at']);
                                            $total += ($respondTime->getTimestamp() - $openedTime->getTimestamp());
                                            $count++;
                                        }
                                    }
                                    if ($count > 0) {
                                        $avg_time_to_respond = secondsToTime($total / $count); // Avoids DivisionByZeroError
                                    }

                                    // Calculate average time to solve
                                    $avg_time_to_resolve = '-';
                                    if ($ticket_resolved_count > 0) {
                                        $count = 0;
                                        $total = 0;
                                        while ($row = mysqli_fetch_assoc($sql_tickets_resolved)) {
                                            $openedTime = new DateTime($row['ticket_created_at']);
                                            $resolvedTime = new DateTime($row['ticket_resolved_at']);

                                            $total += ($resolvedTime->getTimestamp() - $openedTime->getTimestamp());
                                            $count++;
                                        }
                                        $avg_time_to_resolve = secondsToTime($total / $count);

                                    }

                                    ?>

                                    <tr>
                                        <td><?php echo $client_name; ?></td>
                                        <td class="text-end"><?php echo $ticket_raised_count; ?></td>
                                        <td class="text-end"><?php echo $low_ticket_count; ?></td>
                                        <td class="text-end"><?php echo $med_ticket_count; ?></td>
                                        <td class="text-end"><?php echo $high_ticket_count; ?></td>
                                        <td class="text-end"><?php echo $ticket_resolved_count; ?></td>
                                        <td class="text-end"><?php echo $ticket_total_time_worked; ?></td>
                                        <td class="text-end"><?php echo $avg_time_to_respond; ?></td>
                                        <td class="text-end"><?php echo $avg_time_to_resolve; ?></td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>



            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-chart-area me-2"></i>Monthly (<?php echo date("F", mktime(1, 1, 1, $month, 1)) . ' ' . $year; ?>)</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                            <tr>
                                <th>Department</th>
                                <th class="text-end">Raised</th>
                                <th class="text-end">Priority: Low</th>
                                <th class="text-end">Priority: Med</th>
                                <th class="text-end">Priority: High</th>
                                <th class="text-end">Resolved</th>
                                <th class="text-end">Total Time worked <i>(H:M:S)</i></th>
                                <th class="text-end">Avg time to respond</th>
                                <th class="text-end">Avg time to resolve</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            mysqli_data_seek($sql_clients, 0); // Reset
                            $m_rows = 0;
                            while ($row = mysqli_fetch_assoc($sql_clients)) {
                                $client_id = intval($row['client_id']);
                                $client_name = nullable_htmlentities($row['client_name']);

                                // Calculate total tickets raised in period
                                $sql_ticket_raised_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS ticket_raised_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month AND ticket_client_id = $client_id");
                                $row = mysqli_fetch_assoc($sql_ticket_raised_count);
                                $ticket_raised_count = intval($row['ticket_raised_count']);

                                // Calculate total tickets raised in period that are resolved
                                $sql_ticket_resolved_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS ticket_resolved_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month AND ticket_client_id = $client_id AND ticket_resolved_at IS NOT NULL");
                                $row = mysqli_fetch_assoc($sql_ticket_resolved_count);
                                $ticket_resolved_count = intval($row['ticket_resolved_count']);

                                // Breakdown tickets for each priority - Low
                                $sql_low_ticket_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS low_ticket_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month AND ticket_client_id = $client_id AND ticket_priority = 'Low'");
                                $row = mysqli_fetch_assoc($sql_low_ticket_count);
                                $low_ticket_count = intval($row['low_ticket_count']);

                                // Breakdown tickets for each priority - Low
                                $sql_med_ticket_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS med_ticket_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month AND ticket_client_id = $client_id AND ticket_priority = 'Medium'");
                                $row = mysqli_fetch_assoc($sql_med_ticket_count);
                                $med_ticket_count = intval($row['med_ticket_count']);

                                // Breakdown tickets for each priority - Low
                                $sql_high_ticket_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS high_ticket_count FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month AND ticket_client_id = $client_id AND ticket_priority = 'High'");
                                $row = mysqli_fetch_assoc($sql_high_ticket_count);
                                $high_ticket_count = intval($row['high_ticket_count']);

                                // Used to calculate average time to respond to tickets that were raised in period specified
                                $sql_tickets_respond = mysqli_query($mysqli, "SELECT ticket_created_at, ticket_first_response_at FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month AND ticket_client_id = $client_id");

                                // Used to calculate average time to resolve tickets that were raised in period specified
                                $sql_tickets_resolved = mysqli_query($mysqli, "SELECT ticket_created_at, ticket_resolved_at FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month AND ticket_client_id = $client_id AND ticket_resolved_at IS NOT NULL");

                                // Calculate total time tracked towards tickets in the period
                                $sql_time = mysqli_query($mysqli, "SELECT SEC_TO_TIME(SUM(TIME_TO_SEC(ticket_reply_time_worked))) as total_time FROM ticket_replies LEFT JOIN tickets ON tickets.ticket_id = ticket_replies.ticket_reply_ticket_id WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month AND ticket_client_id = $client_id AND ticket_reply_time_worked IS NOT NULL");
                                $row = mysqli_fetch_assoc($sql_time);
                                $ticket_total_time_worked = nullable_htmlentities($row['total_time']);

                                if ($ticket_raised_count > 0 || $ticket_resolved_count > 0) {

                                    // Calculate average time to respond
                                    $avg_time_to_respond = '-';
                                    $count = 0;
                                    $total = 0;
                                    while ($row = mysqli_fetch_assoc($sql_tickets_respond)) {
                                        if (!empty($row['ticket_first_response_at'])) {
                                            $openedTime = new DateTime($row['ticket_created_at']);
                                            $respondTime = new DateTime($row['ticket_first_response_at']);
                                            $total += ($respondTime->getTimestamp() - $openedTime->getTimestamp());
                                            $count++;
                                        }
                                    }
                                    if ($count > 0) {
                                        $avg_time_to_respond = secondsToTime($total / $count); // Avoids DivisionByZeroError
                                    }

                                    // Calculate average time to solve
                                    $avg_time_to_resolve = '-';
                                    if ($ticket_resolved_count > 0) {
                                        $count = 0;
                                        $total = 0;
                                        while ($row = mysqli_fetch_assoc($sql_tickets_resolved)) {
                                            $openedTime = new DateTime($row['ticket_created_at']);
                                            $resolvedTime = new DateTime($row['ticket_resolved_at']);

                                            $total += ($resolvedTime->getTimestamp() - $openedTime->getTimestamp());
                                            $count++;
                                        }
                                        $avg_time_to_resolve = secondsToTime($total / $count);
                                    }

                                    ?>

                                    <?php $m_rows++; ?>
                                    <tr>
                                        <td><?php echo $client_name; ?></td>
                                        <td class="text-end"><?php echo $ticket_raised_count; ?></td>
                                        <td class="text-end"><?php echo $low_ticket_count; ?></td>
                                        <td class="text-end"><?php echo $med_ticket_count; ?></td>
                                        <td class="text-end"><?php echo $high_ticket_count; ?></td>
                                        <td class="text-end"><?php echo $ticket_resolved_count; ?></td>
                                        <td class="text-end"><?php echo $ticket_total_time_worked; ?></td>
                                        <td class="text-end"><?php echo $avg_time_to_respond; ?></td>
                                        <td class="text-end"><?php echo $avg_time_to_resolve; ?></td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>
                            <?php if ($m_rows === 0) { ?>
                                <tr><td colspan="9" class="text-center text-secondary py-4">No tickets were raised in <?php echo date("F", mktime(1, 1, 1, $month, 1)) . ' ' . $year; ?>. Pick another month above.</td></tr>
                            <?php } ?>
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
