<?php
/*
 * StripePaymentProvider — Stripe implementation of PaymentProviderInterface.
 *
 * This class LIFTS the existing Stripe SDK interactions out of the former
 * coupling sites (agent/post/payment.php, guest/guest_pay_invoice_stripe.php,
 * guest/guest_ajax.php, cron/cron.php, admin/post/saved_payment_method.php,
 * client/post.php) with ZERO logic change: it makes the same Stripe API calls
 * with the same parameters and returns the raw Stripe SDK objects so callers can
 * read the exact same fields they read before.
 *
 * Secrets are read from the payment_providers row through decryptSetting() so the
 * private key / webhook secret can live encrypted at rest (decryptSetting is
 * backward compatible: plaintext values are returned as-is).
 */

require_once __DIR__ . '/interface_payment_provider.php';

class StripePaymentProvider implements PaymentProviderInterface
{
    private $id;
    private $row;
    private $secretKey;
    private $webhookSecret;
    private $providerName;

    public function __construct(int $id)
    {
        global $mysqli;
        $this->id = intval($id);

        $this->row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT * FROM payment_providers WHERE payment_provider_id = {$this->id} LIMIT 1"
        ));
        if (!$this->row) {
            throw new RuntimeException("Stripe payment provider {$this->id} not found");
        }

        // Decrypt secrets (decryptSetting transparently returns legacy plaintext).
        $this->secretKey     = $this->decrypt($this->row['payment_provider_private_key'] ?? '');
        $this->webhookSecret = $this->decrypt($this->row['payment_provider_webhook_secret'] ?? '');
        $this->providerName  = $this->row['payment_provider_name'] ?? 'Stripe';

        // Load the Stripe PHP SDK (same include the old sites used).
        require_once __DIR__ . '/../plugins/stripe-php/init.php';
    }

    // Backward-compatible decrypt: use functions.php's decryptSetting when
    // available, otherwise pass the value through unchanged (plaintext).
    private function decrypt($value): string
    {
        $value = (string) $value;
        if (function_exists('decryptSetting')) {
            return decryptSetting($value);
        }
        return $value;
    }

    // Fresh Stripe client bound to this provider's secret key.
    private function client()
    {
        return new \Stripe\StripeClient($this->secretKey);
    }

    // Expose the provider id (used by the webhook endpoint for de-dup keying).
    public function getProviderId(): int
    {
        return $this->id;
    }

    /*
     * Guest one-off checkout — creates a PaymentIntent and returns it.
     * Mirrors guest/guest_ajax.php (stripe_create_pi). Exceptions propagate so
     * the caller's try/catch handles them exactly as before.
     */
    public function createCheckout(array $params)
    {
        return $this->client()->paymentIntents->create($params);
    }

    /*
     * Retrieve a PaymentIntent by id for result verification.
     * Mirrors guest/guest_pay_invoice_stripe.php (\Stripe\PaymentIntent::retrieve).
     * The caller performs the client_secret / status / amount validation.
     */
    public function verifyCheckoutResult(string $reference)
    {
        return $this->client()->paymentIntents->retrieve($reference, []);
    }

    /*
     * Create a setup-mode Checkout Session (or SetupIntent) used to capture and
     * store a payment method for future off-session charges.
     * Mirrors client/post.php (create_stripe_checkout).
     */
    public function createSaveMethodIntent(array $params)
    {
        return $this->client()->checkout->sessions->create($params);
    }

    /*
     * Resolve the stored payment method from a completed setup Checkout Session.
     * Mirrors client/post.php (stripe_save_card): retrieve the session, its setup
     * intent, and finally the payment method object (brand/last4/bank details).
     */
    public function saveMethod(string $reference)
    {
        $stripe          = $this->client();
        $checkoutSession = $stripe->checkout->sessions->retrieve($reference, []);
        $setupIntent     = $stripe->setupIntents->retrieve($checkoutSession->setup_intent, []);
        return $stripe->paymentMethods->retrieve($setupIntent->payment_method, []);
    }

    /*
     * Attach a stored payment method to a customer (helper for the save flow).
     */
    public function attachMethod(string $methodReference, string $customerReference)
    {
        return $this->client()->paymentMethods->attach($methodReference, ['customer' => $customerReference]);
    }

    /*
     * Off-session charge of a saved payment method (autopay / pay with saved card).
     * Mirrors agent/post/payment.php (add_payment_stripe) and cron/cron.php autopay.
     * Exceptions propagate to the caller's try/catch.
     */
    public function chargeSavedMethod(array $params, ?string $idempotencyKey = null)
    {
        $opts = $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : [];
        return $this->client()->paymentIntents->create($params, $opts);
    }

    /*
     * Detach a saved payment method at Stripe.
     * Mirrors admin/post/saved_payment_method.php (paymentMethods->detach).
     */
    public function removeSavedMethod(string $methodReference)
    {
        return $this->client()->paymentMethods->detach($methodReference, []);
    }

    /*
     * Refund a completed PaymentIntent (full or partial). No prior call site —
     * new capability exposed by the interface.
     */
    public function refund(string $reference, ?float $amount = null)
    {
        $params = ['payment_intent' => $reference];
        if ($amount !== null) {
            $params['amount'] = intval(round($amount * 100)); // Stripe expects cents
        }
        return $this->client()->refunds->create($params);
    }

    /*
     * Verify an inbound Stripe webhook against the configured signing secret.
     * Returns the verified \Stripe\Event or throws on failure / missing secret.
     */
    public function verifyWebhook(string $payload, string $signatureHeader)
    {
        if (empty($this->webhookSecret)) {
            throw new \Exception('Stripe webhook secret is not configured for provider ' . $this->id);
        }
        return \Stripe\Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, [
            'checkout',
            'charge_saved_method',
            'save_method',
            'remove_saved_method',
            'refund',
            'webhook',
            'card',
            'ach',
        ], true);
    }

    public function displayName(): string
    {
        return $this->providerName;
    }
}
