<?php
/*
 * Client Portal
 * Quotes for PTC / billing contacts
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

if ($session_contact_primary == 0 && !$session_contact_is_billing_contact) {
    header("Location: post.php?logout");
    exit();
}

$quotes_sql = mysqli_query($mysqli, "SELECT * FROM quotes WHERE quote_client_id = $session_client_id AND quote_status != 'Draft' ORDER BY quote_date DESC");
?>

<div class="row mb-4">
    <div class="col">
        <h3><i class="fas fa-fw fa-file-signature me-2"></i>Quotes</h3>
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
                <th>#</th>
                <th>Scope</th>
                <th>Amount</th>
                <th>Date</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>

            <?php
            if (mysqli_num_rows($quotes_sql) == 0) { ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No quotes found.</td>
                </tr>
            <?php }
            while ($row = mysqli_fetch_assoc($quotes_sql)) {
                $quote_id = intval($row['quote_id']);
                $quote_prefix = nullable_htmlentities($row['quote_prefix']);
                $quote_number = intval($row['quote_number']);
                $quote_scope = nullable_htmlentities($row['quote_scope']);
                $quote_status = nullable_htmlentities($row['quote_status']);
                $quote_date = nullable_htmlentities($row['quote_date']);
                $quote_amount = floatval($row['quote_amount']);
                $quote_url_key = nullable_htmlentities($row['quote_url_key']);

                if (empty($quote_scope)) {
                    $quote_scope_display = "-";
                } else {
                    $quote_scope_display = $quote_scope;
                }

                if ($quote_status == "Sent") {
                    $quote_badge_color = "warning text-white";
                } elseif ($quote_status == "Viewed") {
                    $quote_badge_color = "primary";
                } elseif ($quote_status == "Accepted") {
                    $quote_badge_color = "success";
                } elseif ($quote_status == "Declined") {
                    $quote_badge_color = "danger";
                } elseif ($quote_status == "Invoiced") {
                    $quote_badge_color = "info";
                } else {
                    $quote_badge_color = "secondary";
                }

                ?>

                <tr>
                    <td><a target="_blank" href="//<?php echo $config_base_url ?>/guest/guest_view_quote.php?quote_id=<?php echo "$quote_id&url_key=$quote_url_key"?>"> <?php echo "$quote_prefix$quote_number"; ?></a></td>
                    <td><?php echo $quote_scope_display; ?></td>
                    <td><?php echo numfmt_format_currency($currency_format, $quote_amount, $session_company_currency); ?></td>
                    <td><?php echo $quote_date; ?></td>
                    <td>
                        <span class="p-2 badge badge-<?php echo $quote_badge_color; ?>">
                            <?php echo $quote_status; ?>
                        </span>
                    </td>

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
