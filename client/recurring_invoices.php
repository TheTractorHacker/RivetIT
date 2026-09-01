<?php
/*
 * Client Portal
 * Invoices for PTC
 */

// header("Content-Security-Policy: default-src 'self'"); -- JQ 2025-07-09 - BREAKS onchange(submit)

require_once "includes/inc_all.php";


if ($session_contact_primary == 0 && !$session_contact_is_billing_contact) {
    header("Location: post.php?logout");
    exit();
}

$recurring_invoices_sql = mysqli_query($mysqli, "SELECT * FROM recurring_invoices
    LEFT JOIN recurring_payments ON recurring_payment_recurring_invoice_id = recurring_invoice_id
    WHERE recurring_invoice_client_id = $session_client_id
    AND recurring_invoice_status = 1
    ORDER BY recurring_invoice_next_date DESC"
);

// Get Payment Provide Details
$payment_provider_sql = mysqli_query($mysqli, "SELECT * FROM payment_providers WHERE payment_provider_active = 1 LIMIT 1");
$row = mysqli_fetch_assoc($payment_provider_sql);
$payment_provider_id = intval($row['payment_provider_id']);
$payment_provider_name = nullable_htmlentities($row['payment_provider_name']);
$payment_provider_threshold = floatval($row['payment_provider_threshold']);

?>

<div class="row mb-4">
    <div class="col">
        <h3><i class="fas fa-fw fa-sync-alt me-2"></i>Recurring Invoices</h3>
    </div>
</div>
<div class="row">

    <div class="col-md-12">

        <div class="card card-outline card-primary">
            <div class="card-body p-0">
                <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
            <tr>
                <th>Scope</th>
                <th>Amount</th>
                <th>Next Bill Date</th>
                <th>Frequency</th>
                <?php if ($payment_provider_id) { ?>
                <th>Auto Pay</th>
                <?php } ?>
            </tr>
            </thead>
            <tbody>

            <?php
            if (mysqli_num_rows($recurring_invoices_sql) == 0) { ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No recurring invoices found.</td>
                </tr>
            <?php }
            while ($row = mysqli_fetch_assoc($recurring_invoices_sql)) {
                $recurring_invoice_id = intval($row['recurring_invoice_id']);
                $recurring_invoice_prefix = nullable_htmlentities($row['recurring_invoice_prefix']);
                $recurring_invoice_number = intval($row['recurring_invoice_number']);
                $recurring_invoice_scope = nullable_htmlentities($row['recurring_invoice_scope']);
                $recurring_invoice_status = nullable_htmlentities($row['recurring_invoice_status']);
                $recurring_invoice_next_date = nullable_htmlentities($row['recurring_invoice_next_date']);
                $recurring_invoice_frequency = nullable_htmlentities($row['recurring_invoice_frequency']);
                $recurring_invoice_amount = floatval($row['recurring_invoice_amount']);
                $recurring_payment_id = intval($row['recurring_payment_id']);
                $recurring_payment_recurring_invoice_id = intval($row['recurring_payment_recurring_invoice_id']);
                $recurring_payment_saved_payment_id = intval($row['recurring_payment_saved_payment_id']);

                if (empty($recurring_invoice_scope)) {
                    $recurring_invoice_scope_display = "-";
                } else {
                    $recurring_invoice_scope_display = $recurring_invoice_scope;
                }
                ?>

                <tr>
                    <td><?php echo $recurring_invoice_scope_display; ?></td>
                    <td><?php echo numfmt_format_currency($currency_format, $recurring_invoice_amount, $session_company_currency); ?></td>
                    <td><?php echo $recurring_invoice_next_date; ?></td>
                    <td><?php echo ucwords($recurring_invoice_frequency); ?>ly</td>
                    <?php if ($payment_provider_id) { ?>
                    <td>
                        <?php $sql = mysqli_query($mysqli, "SELECT * FROM client_saved_payment_methods WHERE saved_payment_client_id = $session_client_id");
                        if (mysqli_num_rows($sql) > 0) { ?>
                            <form class="form" action="post.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="set_recurring_payment" value="1">
                                <input type="hidden" name="recurring_invoice_id" value="<?php echo $recurring_invoice_id; ?>">
                                <select class="form-control select2 auto-submit-select" name="saved_payment_id">
                                    <option value="0">Disabled</option>
                                    <?php
                                        while ($row = mysqli_fetch_assoc($sql)) {
                                            $saved_payment_id = intval($row['saved_payment_id']);
                                            $saved_payment_description = nullable_htmlentities($row['saved_payment_description']);

                                        ?>
                                        <option <?php if ($recurring_payment_saved_payment_id == $saved_payment_id) { echo "selected"; } ?> value="<?php echo $saved_payment_id; ?>"><?php echo $saved_payment_description; ?></option>
                                    <?php } ?>
                                </select>
                            </form>
                        <?php } else { ?>
                            <a href="saved_payment_methods.php">Add a Payment Method</a>
                        <?php } ?>
                    </td>
                    <?php } ?>
                </tr>
            <?php } ?>

            </tbody>
        </table>
                </div>
            </div>
        </div>

    </div>

</div>

<?php
require_once "includes/footer.php";
