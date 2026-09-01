<?php

/*
 * guest_ajax.php
 * Similar to post.php/ajax.php, but for unauthenticated requests using Asynchronous JavaScript
 * Always returns data in JSON format, unless otherwise specified
 */

require_once "../config.php";

// Set Timezone
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../plugins/totp/totp.php";


/*
 * Creates & Returns a Stripe Payment Intent for a particular invoice ID
 */

if (isset($_GET['stripe_create_pi'])) {

    header('Content-Type: application/json');

    // Params from POST (guest_pay_invoice_stripe.js)
    $jsonStr = file_get_contents('php://input');
    $jsonObj = json_decode($jsonStr, true);
    $invoice_id = intval($jsonObj['invoice_id']);
    $url_key = sanitizeInput($jsonObj['url_key']);

    // Query invoice details
    $invoice_sql = mysqli_query(
        $mysqli,
        "SELECT * FROM invoices
        LEFT JOIN clients ON invoice_client_id = client_id
        WHERE invoice_id = $invoice_id
        AND invoice_url_key = '$url_key'
        AND invoice_status NOT IN ('Draft','Paid','Cancelled')
        LIMIT 1"
    );
    if (!$invoice_sql || mysqli_num_rows($invoice_sql) !== 1) {
        exit("Invalid Invoice ID/SQL query");
    }

    $row = mysqli_fetch_assoc($invoice_sql);
    $invoice_prefix = nullable_htmlentities($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $invoice_amount = floatval($row['invoice_amount']);
    $invoice_currency_code = nullable_htmlentities($row['invoice_currency_code']);
    $client_id = intval($row['client_id']);
    $client_name = nullable_htmlentities($row['client_name']);

    // Add up all the payments for the invoice and get the total amount paid to the invoice
    $sql_amount_paid = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS amount_paid FROM payments WHERE payment_invoice_id = $invoice_id");
    $row_amt = mysqli_fetch_assoc($sql_amount_paid);
    $amount_paid = floatval($row_amt['amount_paid']);
    $balance_to_pay = $invoice_amount - $amount_paid;

    $balance_to_pay = round($balance_to_pay, 2);

    if (intval($balance_to_pay) == 0) {
        exit("No balance outstanding");
    }

    // Setup payment provider (Stripe) from payment_providers via the factory
    $stripe_provider = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payment_provider_id FROM payment_providers WHERE payment_provider_name = 'Stripe' LIMIT 1"));
    if (!$stripe_provider) {
        exit("Stripe not enabled / configured");
    }
    $provider_id = intval($stripe_provider['payment_provider_id']);

    require_once '../includes/payment_provider_factory.php';
    $provider = getPaymentProvider($provider_id);

    $pi_description = "ITFlow: $client_name payment of $invoice_currency_code $balance_to_pay for $invoice_prefix$invoice_number";

    // Same intent arguments as before (card-only preserves exact prior behaviour).
    $pi_args = [
        'amount' => intval(round($balance_to_pay * 100)), // Stripe expects cents - round before truncating so float representation error (e.g. 19.99*100 = 1998.9999...) can't undercharge by a cent
        'currency' => $invoice_currency_code,
        'description' => $pi_description,
        'metadata' => [
            'itflow_client_id' => $client_id,
            'itflow_client_name' => $client_name,
            'itflow_invoice_number' => $invoice_prefix . $invoice_number,
            'itflow_invoice_id' => $invoice_id,
        ],
        'payment_method_types' => ['card'],
    ];

    try {
        // A3 (Stripe ACH): offer bank/ACH alongside cards when the account
        // supports it. Only an intent is created here (no charge yet), so if the
        // Stripe account has not enabled us_bank_account we safely retry card-only.
        try {
            $ach_args = $pi_args;
            $ach_args['payment_method_types'] = ['card', 'us_bank_account'];
            $paymentIntent = $provider->createCheckout($ach_args);
        } catch (Exception $eAch) {
            $paymentIntent = $provider->createCheckout($pi_args);
        }

        $output = [
            'clientSecret' => $paymentIntent->client_secret,
        ];

        echo json_encode($output);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
}
