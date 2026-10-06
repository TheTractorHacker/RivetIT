<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_sales');

$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
$year  = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
if ($month < 1 || $month > 12) {
    $month = intval(date('n'));
}

$month_names = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

$sql_clients = mysqli_query($mysqli,
    "SELECT DISTINCT client_id, client_name FROM clients
     JOIN contracts ON contract_client_id = client_id
     WHERE contract_status = 'Active' AND contract_archived_at IS NULL
       AND (contract_support_hours_included_remote IS NOT NULL OR contract_support_hours_included_onsite IS NOT NULL)
       AND client_archived_at IS NULL" . \ITFlow\Reports\ReportScope::clause('client_id') . "
     ORDER BY client_name ASC");

$rows = [];
while ($c = mysqli_fetch_assoc($sql_clients)) {
    $usage = getClientIncludedIssuesUsage($mysqli, intval($c['client_id']), $month, $year);
    $rows[] = [
        'client_id'   => intval($c['client_id']),
        'client_name' => $c['client_name'],
        'usage'       => $usage,
    ];
}

// Renders one Included/Used/Remaining/% cell group for remote or onsite.
// 'included'/'used'/'remaining' are hours (fractional - each ticket charges
// a flat 30 min remote / 1 hr onsite), so display with 2 decimal places.
function renderIssuesUsageCells(array $u): void {
    if ($u['included'] === null) {
        echo '<td class="text-end text-muted" colspan="4">&mdash;</td>';
        return;
    }
    $over = $u['remaining'] !== null && $u['remaining'] < 0;
    echo '<td class="text-end">' . number_format($u['included'], 2) . '</td>';
    echo '<td class="text-end">' . number_format($u['used'], 2) . '</td>';
    echo '<td class="text-end ' . ($over ? 'text-danger fw-bold' : '') . '">' . ($over ? '(' . number_format(abs($u['remaining']), 2) . ' over)' : number_format($u['remaining'], 2)) . '</td>';
    echo '<td class="text-end">';
    if ($u['pct'] !== null) {
        echo '<span class="badge ' . ($u['pct'] >= 100 ? 'text-bg-danger' : ($u['pct'] >= 80 ? 'text-bg-warning' : 'text-bg-success')) . '">' . $u['pct'] . '%</span>';
    } else {
        echo '&mdash;';
    }
    echo '</td>';
}

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-house-user me-2"></i>Included Support Hours</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
    </div>
    <div class="card-body">
        <form class="mb-3 d-flex" style="gap:.5rem">
            <select class="form-control auto-submit-select" name="month" style="max-width:180px">
                <?php foreach ($month_names as $m => $mname) { ?>
                    <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= $mname ?></option>
                <?php } ?>
            </select>
            <select class="form-control auto-submit-select" name="year" style="max-width:120px">
                <?php for ($y = intval(date('Y')); $y >= intval(date('Y')) - 4; $y--) { ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php } ?>
            </select>
        </form>

        <div class="table-responsive-sm">
            <table class="table table-striped table-sm">
                <thead>
                    <tr>
                        <th rowspan="2" class="align-bottom">Department</th>
                        <th colspan="4" class="text-center"><i class="fas fa-fw fa-laptop me-1"></i>Remote <small class="text-muted">(hrs, 30 min/ticket)</small></th>
                        <th colspan="4" class="text-center"><i class="fas fa-fw fa-house-user me-1"></i>Onsite <small class="text-muted">(hrs, 1 hr/ticket)</small></th>
                    </tr>
                    <tr>
                        <th class="text-end">Included</th>
                        <th class="text-end">Used</th>
                        <th class="text-end">Remaining</th>
                        <th class="text-end">%</th>
                        <th class="text-end">Included</th>
                        <th class="text-end">Used</th>
                        <th class="text-end">Remaining</th>
                        <th class="text-end">%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) { ?>
                        <tr><td colspan="9" class="text-center text-muted">No departments have an included-hours plan configured. Set one on an active contract.</td></tr>
                    <?php } else { foreach ($rows as $r) { ?>
                        <tr>
                            <td><a href="../client_overview.php?client_id=<?= $r['client_id'] ?>"><?= nullable_htmlentities($r['client_name']) ?></a></td>
                            <?php renderIssuesUsageCells($r['usage']['remote']); ?>
                            <?php renderIssuesUsageCells($r['usage']['onsite']); ?>
                        </tr>
                    <?php } } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>
