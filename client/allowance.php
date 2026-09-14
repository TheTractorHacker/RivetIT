<?php
/*
 * Client Portal
 * Monthly included-support-hours allowance: how much of each active
 * contract's remote/onsite allowance has been used this month, and which
 * specific tickets consumed it. Read-only - the allowance itself is set on
 * the contract by staff (agent/modals/contract/contract_edit.php).
 */

// inc_all.php streams the page chrome (header.php) before control returns
// here, so the redirect below needs output buffered - otherwise header()
// silently fails ("headers already sent") and the page just dies with no
// content and no redirect. Same pattern as contracts.php.
ob_start();
require_once "includes/inc_all.php";

if ($session_contact_primary == 0 && !$session_contact_is_technical_contact) {
    ob_end_clean();
    header("Location: post.php?logout");
    exit();
}

$month_names = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
$year  = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
if ($month < 1 || $month > 12) {
    $month = intval(date('n'));
}

$period_start = new DateTime(sprintf('%04d-%02d-01', $year, $month));
$period_end   = (clone $period_start)->modify('last day of this month')->setTime(23, 59, 59);
$from_dt = $period_start->format('Y-m-d H:i:s');
$to_dt   = $period_end->format('Y-m-d H:i:s');

// Only active, non-archived contracts for this department that actually have
// an allowance configured - same qualifying condition the agent-side report
// (agent/reports/included_issues.php) uses.
$contracts_sql = mysqli_query($mysqli,
    "SELECT contract_id, contract_name, contract_support_hours_included_remote, contract_support_hours_included_onsite
     FROM contracts
     WHERE contract_client_id = $session_client_id
       AND contract_status = 'Active' AND contract_archived_at IS NULL
       AND (contract_support_hours_included_remote IS NOT NULL OR contract_support_hours_included_onsite IS NOT NULL)
     ORDER BY contract_name ASC"
);

$contracts = [];
while ($c = mysqli_fetch_assoc($contracts_sql)) {
    $contract_id = intval($c['contract_id']);
    $usage = getContractIncludedIssuesUsage($mysqli, $contract_id, $month, $year);

    // The tickets that actually count toward this contract's allowance for
    // the selected month - same linking rule getContractIncludedIssuesUsage()
    // itself uses (ticket_contract_id + ticket_created_at in-month), just
    // fetching the rows instead of only their count, so each one can be shown
    // with what it charged.
    $tickets_sql = mysqli_query($mysqli,
        "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_delivery_method, ticket_created_at
         FROM tickets
         WHERE ticket_contract_id = $contract_id
           AND ticket_created_at BETWEEN '$from_dt' AND '$to_dt'
           AND ticket_delivery_method IN ('Remote', 'Onsite')
         ORDER BY ticket_created_at DESC"
    );
    $tickets = [];
    while ($t = mysqli_fetch_assoc($tickets_sql)) {
        $t['hours_charged'] = $t['ticket_delivery_method'] === 'Onsite'
            ? INCLUDED_HOURS_PER_TICKET_ONSITE
            : INCLUDED_HOURS_PER_TICKET_REMOTE;
        $tickets[] = $t;
    }

    $contracts[] = [
        'contract_id'   => $contract_id,
        'contract_name' => $c['contract_name'],
        'usage'         => $usage,
        'tickets'       => $tickets,
    ];
}

// One Included / Used / Remaining / % row for a remote-or-onsite usage group.
// Mirrors agent/reports/included_issues.php's renderIssuesUsageCells(), kept
// as a separate copy since that one lives under agent/reports and isn't
// reachable from the portal.
function portalRenderAllowanceRow(string $label, string $icon, array $u): void {
    if ($u['included'] === null) {
        return;
    }
    $over = $u['remaining'] !== null && $u['remaining'] < 0;
    $pct_badge = $u['pct'] === null ? '' : ($u['pct'] >= 100 ? 'text-bg-danger' : ($u['pct'] >= 80 ? 'text-bg-warning' : 'text-bg-success'));
    ?>
    <div class="d-flex align-items-center flex-wrap py-2 border-bottom">
        <div class="me-3" style="min-width:110px"><i class="fas fa-fw <?= $icon ?> me-1 text-muted"></i><?= $label ?></div>
        <div class="me-3"><strong><?= number_format($u['used'], 2) ?></strong> / <?= number_format($u['included'], 2) ?> hrs used</div>
        <div class="me-3 <?= $over ? 'text-danger fw-bold' : 'text-muted' ?>">
            <?= $over ? number_format(abs($u['remaining']), 2) . ' hrs over' : number_format($u['remaining'], 2) . ' hrs remaining' ?>
        </div>
        <?php if ($pct_badge) { ?>
            <span class="badge <?= $pct_badge ?>"><?= $u['pct'] ?>%</span>
        <?php } ?>
    </div>
    <?php
}
?>
<div class="container-fluid mt-3">
<div class="card">
    <div class="card-header d-flex align-items-center flex-wrap">
        <h4 class="mb-0 me-auto"><i class="fas fa-hourglass-half me-2"></i>Monthly Support Allowance</h4>
        <form method="get" class="d-flex" style="gap:.5rem">
            <select class="form-control form-control-sm" name="month">
                <?php foreach ($month_names as $m => $mname) { ?>
                    <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= $mname ?></option>
                <?php } ?>
            </select>
            <select class="form-control form-control-sm" name="year">
                <?php for ($y = intval(date('Y')); $y >= intval(date('Y')) - 2; $y--) { ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php } ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary">View</button>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (empty($contracts)): ?>
            <p class="text-muted text-center py-4 mb-0">No active contract has an included-hours allowance configured.</p>
        <?php else: ?>
        <div class="accordion" id="allowanceAccordion">
        <?php foreach ($contracts as $i => $c):
            $cid = $c['contract_id'];
            $cname = nullable_htmlentities($c['contract_name']);
            $ticket_count = count($c['tickets']);
        ?>
            <div class="card mb-2 border">
                <div class="card-header py-2 cursor-pointer" id="ah<?= $cid ?>"
                     data-bs-toggle="collapse" data-bs-target="#ac<?= $cid ?>" aria-expanded="<?= $i===0?'true':'false' ?>">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-file-contract me-3 text-primary"></i>
                        <strong class="flex-grow-1"><?= $cname ?></strong>
                        <span class="badge text-bg-secondary me-3"><?= $ticket_count ?> ticket<?= $ticket_count!==1?'s':'' ?> this month</span>
                        <i class="fas fa-chevron-down ms-3 text-muted portal-chevron-sm"></i>
                    </div>
                </div>
                <div id="ac<?= $cid ?>" class="collapse <?= $i===0?'show':'' ?>" data-bs-parent="#allowanceAccordion">
                    <div class="card-body">
                        <?php
                        portalRenderAllowanceRow('Remote', 'fa-laptop', $c['usage']['remote']);
                        portalRenderAllowanceRow('Onsite', 'fa-house-user', $c['usage']['onsite']);
                        ?>

                        <?php if (empty($c['tickets'])): ?>
                            <p class="text-muted text-center py-3 mb-0 small">No tickets counted against this contract's allowance in <?= $month_names[$month] ?> <?= $year ?>.</p>
                        <?php else: ?>
                        <div class="table-responsive mt-2">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Ticket</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th class="text-end">Hours Charged</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($c['tickets'] as $t):
                                    $ticket_id = intval($t['ticket_id']);
                                    $ticket_number = nullable_htmlentities($t['ticket_prefix'] . $t['ticket_number']);
                                    $ticket_subject = nullable_htmlentities($t['ticket_subject']);
                                    $method = nullable_htmlentities($t['ticket_delivery_method']);
                                    $method_badge = $method === 'Onsite' ? 'text-bg-primary' : 'text-bg-info';
                                    $date = date('M j, Y', strtotime($t['ticket_created_at']));
                                ?>
                                <tr>
                                    <td class="text-nowrap"><a href="ticket.php?id=<?= $ticket_id ?>">#<?= $ticket_number ?></a> <?= $ticket_subject ?></td>
                                    <td><span class="badge <?= $method_badge ?>"><?= $method ?></span></td>
                                    <td class="text-muted small"><?= $date ?></td>
                                    <td class="text-end"><?= number_format($t['hours_charged'], 2) ?> hrs</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php require_once "includes/footer.php"; ?>
