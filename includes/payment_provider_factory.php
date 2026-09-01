<?php
/*
 * getPaymentProvider(payment_provider_id) — returns the correct billing gateway
 * instance for a row in the payment_providers table.
 *
 * Mirrors includes/rmm_client_factory.php: switch on the provider's identifying
 * column (payment_provider_name), require the implementing class, return a new
 * instance seeded with the provider id. Callers require this file and never need
 * to know which concrete class handles the provider.
 *
 * Currently supports:
 *   Stripe → StripePaymentProvider
 */

require_once __DIR__ . '/interface_payment_provider.php';

function getPaymentProvider(int $id): PaymentProviderInterface
{
    global $mysqli;
    $id  = intval($id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT payment_provider_name FROM payment_providers WHERE payment_provider_id = $id LIMIT 1"
    ));
    if (!$row) {
        throw new RuntimeException("Payment provider $id not found");
    }
    switch ($row['payment_provider_name']) {
        case 'Stripe':
        default:
            require_once __DIR__ . '/class_stripe_payment_provider.php';
            return new StripePaymentProvider($id);
    }
}
