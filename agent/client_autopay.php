<?php

require_once "includes/inc_all_client.php";

// Perms
enforceUserPermission('module_sales');

/*
 * AutoPay overview for a client (agent side).
 *
 * Rewritten off the payment_providers / client_payment_provider /
 * client_saved_payment_methods model and the payment provider factory. The
 * legacy client_stripe table and config_stripe_* settings have been dropped and
 * are no longer referenced. Saving/removing methods is performed by the client
 * from their portal (client/saved_payment_methods.php); this agent view is
 * read-only.
 */

// Load the active payment provider (Stripe) from payment_providers
$provider_row = mysqli_fetch_assoc(mysqli_query($mysqli, "
    SELECT * FROM payment_providers
    WHERE payment_provider_name = 'Stripe'
      AND payment_provider_active = 1
    LIMIT 1
"));

// Guard: no provider configured — the page must still load cleanly
if (!$provider_row) {
    ?>
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fa fa-redo-alt me-2"></i>AutoPay</h3>
        </div>
        <div class="card-body">
            No payment provider is configured. Configure one under Admin &gt; Settings &gt; Online Payment before using AutoPay.
        </div>
    </div>
    <?php
    require_once "../includes/footer.php";
    exit();
}

$provider_id   = intval($provider_row['payment_provider_id']);

// Instantiate the provider through the factory (multi-provider ready)
require_once "../includes/payment_provider_factory.php";
$provider = getPaymentProvider($provider_id);
$provider_display = nullable_htmlentities($provider->displayName());

// Get this client's provider customer record (customer id / autopay consent)
$client_provider = mysqli_fetch_assoc(mysqli_query($mysqli, "
    SELECT * FROM client_payment_provider
    WHERE client_id = $client_id
      AND payment_provider_id = $provider_id
    LIMIT 1
"));
$provider_customer_id = $client_provider ? nullable_htmlentities($client_provider['payment_provider_client']) : '';

// Get this client's saved payment methods
$saved_methods_query = mysqli_query($mysqli, "
    SELECT * FROM client_saved_payment_methods
    WHERE saved_payment_client_id = $client_id
      AND saved_payment_provider_id = $provider_id
    ORDER BY saved_payment_description ASC
");

?>

<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title"><i class="fa fa-redo-alt me-2"></i>AutoPay (<?php echo $provider_display; ?>)</h3>
    </div>

    <div class="card-body">

        <?php if (!$provider_customer_id) { ?>

            <p>This client has not authorized automatic payments yet. The client can grant consent and save a payment method from their client portal.</p>

        <?php } else { ?>

            <b>Saved payment methods</b>

            <?php if (mysqli_num_rows($saved_methods_query) === 0) { ?>

                <p>No saved payment methods on file.</p>

            <?php } else { ?>

                <ul class="list-unstyled">
                    <?php
                    while ($method = mysqli_fetch_assoc($saved_methods_query)) {
                        $description = nullable_htmlentities($method['saved_payment_description']);
                        $saved_type  = nullable_htmlentities($method['saved_payment_type'] ?? 'card');
                        $desc_lower  = strtolower($description);

                        $payment_icon = "fas fa-credit-card"; // default
                        if ($saved_type === 'us_bank_account' || strpos($desc_lower, 'bank') !== false || strpos($desc_lower, 'ach') !== false) {
                            $payment_icon = "fas fa-university";
                        } elseif (strpos($desc_lower, "visa") !== false) {
                            $payment_icon = "fab fa-cc-visa";
                        } elseif (strpos($desc_lower, "mastercard") !== false) {
                            $payment_icon = "fab fa-cc-mastercard";
                        } elseif (strpos($desc_lower, "american express") !== false || strpos($desc_lower, "amex") !== false) {
                            $payment_icon = "fab fa-cc-amex";
                        } elseif (strpos($desc_lower, "discover") !== false) {
                            $payment_icon = "fab fa-cc-discover";
                        }

                        echo "<li><i class='$payment_icon fa-2x me-2'></i>$description</li>";
                    }
                    ?>
                </ul>

            <?php } ?>

        <?php } ?>

    </div>
</div>

<?php

require_once "../includes/footer.php";
