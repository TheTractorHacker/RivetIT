<?php
/*
 * PaymentProviderInterface — the contract every billing gateway implements.
 *
 * ITFlow talks to payment gateways (Stripe today, others later) only through
 * this interface, obtained via getPaymentProvider() in payment_provider_factory.php.
 * Call sites keep all of their ITFlow business logic (recording payments,
 * emailing receipts, creating expenses, logging) and delegate ONLY the gateway
 * API interaction to the provider object. Implementations return the raw gateway
 * SDK objects so callers can read the same fields they read today (zero behaviour
 * change during the refactor).
 *
 * Secrets (private key, webhook secret) are read through decryptSetting() inside
 * the implementation — callers never handle raw keys.
 */

interface PaymentProviderInterface
{
    /*
     * Create a one-off payment/checkout for a guest-pay flow.
     * $params mirrors the gateway's create-intent arguments (amount in the
     * gateway's smallest currency unit, currency, description, metadata,
     * payment_method_types, ...). Returns the gateway intent object; the caller
     * reads e.g. ->client_secret. Throws on gateway error (caller catches).
     */
    public function createCheckout(array $params);

    /*
     * Retrieve/verify the result of a checkout by its gateway reference (e.g. a
     * PaymentIntent id). Returns the gateway object; the caller performs the
     * amount/status/secret validation exactly as before.
     */
    public function verifyCheckoutResult(string $reference);

    /*
     * Create the intent/session used to securely capture & store a payment
     * method for later off-session charges (e.g. a Stripe setup-mode Checkout
     * Session or SetupIntent). $params mirrors the gateway arguments. Returns
     * the gateway object; the caller reads ->client_secret.
     */
    public function createSaveMethodIntent(array $params);

    /*
     * Given the reference returned when a save-method flow completes (e.g. a
     * checkout session id), resolve and return the stored payment method object
     * (containing the method id, brand/last4/bank details, etc.).
     */
    public function saveMethod(string $reference);

    /*
     * Charge a previously-saved payment method off-session (autopay / pay with
     * saved card). $params mirrors the gateway create-intent arguments incl.
     * customer, payment_method, off_session, confirm. $idempotencyKey, when given,
     * is passed to the gateway so a double-submit/retry within the same window is
     * deduped into a single charge instead of billing the card twice. Returns the
     * gateway intent object; the caller reads ->status, ->amount_received, etc.
     * Throws on error.
     */
    public function chargeSavedMethod(array $params, ?string $idempotencyKey = null);

    /*
     * Remove/detach a saved payment method at the gateway by its reference.
     */
    public function removeSavedMethod(string $methodReference);

    /*
     * Refund a completed payment by its gateway reference. $amount null = full
     * refund; otherwise a partial amount in major currency units. Returns the
     * gateway refund object.
     */
    public function refund(string $reference, ?float $amount = null);

    /*
     * Verify an inbound webhook against the provider's configured signing secret.
     * Returns the verified gateway event object, or throws if verification fails.
     */
    public function verifyWebhook(string $payload, string $signatureHeader);

    /*
     * Whether this provider supports a given capability, e.g. 'ach', 'card',
     * 'refund', 'webhook', 'save_method', 'charge_saved_method'.
     */
    public function supports(string $feature): bool;

    /*
     * Human-readable provider name written into payments.payment_method
     * (e.g. "Stripe").
     */
    public function displayName(): string;
}
