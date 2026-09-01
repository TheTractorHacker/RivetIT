<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_support');

if (isset($_GET['year'])) {
    $year = intval($_GET['year']);
} else {
    $year = date('Y');
}

$show_uninvoiced_only = isset($_GET['uninvoiced']);

$sql_charge_years = mysqli_query($mysqli, "SELECT DISTINCT YEAR(charge_created_at) AS charge_year FROM ticket_charges ORDER BY charge_year DESC");

$having = $show_uninvoiced_only ? "HAVING uninvoiced_amount > 0" : "";

$sql_tickets = mysqli_query($mysqli, "
    SELECT
        t.ticket_id, t.ticket_number, t.ticket_subject,
        c.client_name,
        COUNT(tc.charge_id) AS charge_count,
        SUM(tc.charge_total) AS total_amount,
        SUM(CASE WHEN tc.charge_invoiced_at IS NULL THEN tc.charge_total ELSE 0 END) AS uninvoiced_amount,
        MAX(tc.charge_created_at) AS last_charge_at
    FROM ticket_charges tc
    JOIN tickets t ON tc.charge_ticket_id = t.ticket_id
    LEFT JOIN clients c ON t.ticket_client_id = c.client_id
    WHERE tc.charge_archived_at IS NULL
    AND YEAR(tc.charge_created_at) = $year
    GROUP BY t.ticket_id
    $having
    ORDER BY last_charge_at DESC
");

$grand_total = 0;
$grand_uninvoiced = 0;
$rows = [];
while ($row = mysqli_fetch_assoc($sql_tickets)) {
    $rows[] = $row;
    $grand_total += floatval($row['total_amount']);
    $grand_uninvoiced += floatval($row['uninvoiced_amount']);
}

?>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-dollar-sign me-2"></i>Ticket Charges</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
            </div>
        </div>
        <div class="card-body">
            <form class="form-row align-items-center mb-3">
                <div class="col-auto">
                    <select class="form-control auto-submit-select" name="year">
                        <?php
                        while ($row = mysqli_fetch_assoc($sql_charge_years)) {
                            $charge_year = intval($row['charge_year']); ?>
                            <option <?php if ($year == $charge_year) { ?> selected <?php } ?> value="<?= $charge_year ?>"><?= $charge_year ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-auto">
                    <div class="form-check form-check">
                        <input type="checkbox" class="form-check-input auto-submit-select" id="uninvoiced" name="uninvoiced" value="1" <?= $show_uninvoiced_only ? 'checked' : '' ?>>
                        <label class="form-check-label" for="uninvoiced">Uninvoiced charges only</label>
                    </div>
                </div>
            </form>

            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Client</th>
                        <th class="text-end">Charges</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Uninvoiced</th>
                        <th>Last Charge</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows) { ?>
                        <tr>
                            <td colspan="6">No ticket charges found for <?= $year ?><?= $show_uninvoiced_only ? ' (uninvoiced only)' : '' ?>.</td>
                        </tr>
                    <?php } ?>
                    <?php foreach ($rows as $row) {
                        $ticket_id = intval($row['ticket_id']);
                        $ticket_number = intval($row['ticket_number']);
                        $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                        $client_name = nullable_htmlentities($row['client_name']);
                        $charge_count = intval($row['charge_count']);
                        $total_amount = floatval($row['total_amount']);
                        $uninvoiced_amount = floatval($row['uninvoiced_amount']);
                        $last_charge_at = $row['last_charge_at'];
                    ?>
                        <tr>
                            <td><a href="../../agent/ticket.php?ticket_id=<?= $ticket_id ?>">#<?= $ticket_number ?> - <?= $ticket_subject ?></a></td>
                            <td><?= $client_name ?></td>
                            <td class="text-end"><?= $charge_count ?></td>
                            <td class="text-end"><?= numfmt_format_currency($currency_format, $total_amount, $session_company_currency) ?></td>
                            <td class="text-end">
                                <?php if ($uninvoiced_amount > 0) { ?>
                                    <span class="badge text-bg-warning"><?= numfmt_format_currency($currency_format, $uninvoiced_amount, $session_company_currency) ?></span>
                                <?php } else { ?>
                                    <?= numfmt_format_currency($currency_format, 0, $session_company_currency) ?>
                                <?php } ?>
                            </td>
                            <td><?= nullable_htmlentities($last_charge_at) ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                    <?php if ($rows) { ?>
                    <tfoot>
                    <tr class="fw-bold">
                        <td colspan="3" class="text-end">Grand Total</td>
                        <td class="text-end"><?= numfmt_format_currency($currency_format, $grand_total, $session_company_currency) ?></td>
                        <td class="text-end"><?= numfmt_format_currency($currency_format, $grand_uninvoiced, $session_company_currency) ?></td>
                        <td></td>
                    </tr>
                    </tfoot>
                    <?php } ?>
                </table>
            </div>
        </div>
    </div>

<?php
require_once "../../includes/footer.php";
