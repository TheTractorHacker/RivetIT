<?php
/*
 * payment_webhook.php
 *
 * Public, unauthenticated inbound payment-gateway webhook endpoint (no session).
 * The request is verified by the provider's signature via the provider's
 * verifyWebhook() (Stripe: signed with the provider's webhook signing secret).
 *
 * Purpose: reliably record online payments even when the customer drops the
 * post-payment browser redirect (the current paid-but-unrecorded gap in
 * guest/guest_pay_invoice_stripe.php). Events are de-duplicated on the unique
 * key (event_provider_id, event_provider_ref) and payments are recorded
 * idempotently (guarded on payment_reference), so it is safe for the redirect
 * handler, autopay, and this webhook to all fire for the same PaymentIntent.
 *
 * Configure in Stripe: Developers > Webhooks > add endpoint
 *   https://<your-itflow>/guest/payment_webhook.php
 * listening for payment_intent.succeeded, and store the signing secret in
 * payment_providers.payment_provider_webhook_secret (via encryptSetting()).
 */

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../includes/payment_provider_factory.php";

// --- Read raw request body + signature header ---
$payload    = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$ip         = sanitizeInput(getIP());

if ($payload === '' || $payload === false) {
    http_response_code(400);
    exit('Empty payload');
}

// --- Resolve the active payment provider (single active Stripe row, as elsewhere) ---
$provider_row = mysqli_fetch_assoc(mysqli_query($mysqli, "
    SELECT payment_provider_id FROM payment_providers
    WHERE payment_provider_name = 'Stripe' AND payment_provider_active = 1
    LIMIT 1
"));
if (!$provider_row) {
    http_response_code(503);
    exit('No payment provider configured');
}
$provider_id = intval($provider_row['payment_provider_id']);

// --- Verify the signature via the provider ---
try {
    $provider = getPaymentProvider($provider_id);
    $event    = $provider->verifyWebhook($payload, $sig_header);
} catch (Exception $e) {
    error_log("Payment webhook error - verification failed: " . $e->getMessage());
    http_response_code(400);
    exit('Signature verification failed');
}

$event_ref       = sanitizeInput($event->id);
$event_type      = sanitizeInput($event->type);
$event_ref_safe  = mysqli_real_escape_string($mysqli, $event_ref);
$event_type_safe = mysqli_real_escape_string($mysqli, $event_type);
$payload_safe    = mysqli_real_escape_string($mysqli, $payload);
$provider_display = $provider->displayName();
$provider_display_safe = mysqli_real_escape_string($mysqli, $provider_display);

// --- Idempotency / dedup on the unique key (event_provider_id, event_provider_ref) ---
mysqli_query($mysqli, "
    INSERT IGNORE INTO payment_webhook_events
    SET event_provider_id = $provider_id,
        event_provider_ref = '$event_ref_safe',
        event_type = '$event_type_safe',
        event_status = 'received',
        event_payload = '$payload_safe',
        event_created_at = NOW()
");
if (mysqli_affected_rows($mysqli) === 0) {
    // Already received this event — acknowledge without reprocessing
    http_response_code(200);
    exit('Duplicate event ignored');
}
$event_row_id = intval(mysqli_insert_id($mysqli));

// --- Only successful one-off/autopay payments create a payment record ---
if ($event_type !== 'payment_intent.succeeded') {
    mysqli_query($mysqli, "UPDATE payment_webhook_events SET event_status = 'ignored' WHERE event_id = $event_row_id");
    http_response_code(200);
    exit('OK');
}

$pi = $event->data->object;

$pi_id          = sanitizeInput($pi->id);
$pi_date        = date('Y-m-d', intval($pi->created));
$pi_amount_paid = floatval($pi->amount_received / 100);
$pi_currency    = strtoupper(sanitizeInput($pi->currency));
$pi_livemode    = $pi->livemode;
$pi_invoice_id  = intval($pi->metadata->itflow_invoice_id ?? 0);

$extended_log_desc = $pi_livemode ? '' : '(DEV MODE)';

if ($pi_invoice_id <= 0) {
    mysqli_query($mysqli, "UPDATE payment_webhook_events SET event_status = 'ignored' WHERE event_id = $event_row_id");
    http_response_code(200);
    exit('OK');
}

// Idempotency: has this PaymentIntent already been recorded (redirect / autopay / prior webhook)?
$existing = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT payment_id FROM payments WHERE payment_reference = 'Stripe - $pi_id' LIMIT 1"
));
if ($existing) {
    mysqli_query($mysqli, "UPDATE payment_webhook_events SET event_status = 'ignored' WHERE event_id = $event_row_id");
    http_response_code(200);
    exit('Already recorded');
}

// Load the invoice (must still be payable)
$inv = mysqli_fetch_assoc(mysqli_query($mysqli, "
    SELECT * FROM invoices
    LEFT JOIN clients ON invoice_client_id = client_id
    LEFT JOIN contacts ON clients.client_id = contacts.contact_client_id AND contact_primary = 1
    WHERE invoice_id = $pi_invoice_id
    AND invoice_status NOT IN ('Draft','Paid','Cancelled')
    LIMIT 1
"));
if (!$inv) {
    // Invoice unknown or no longer payable — nothing to record
    mysqli_query($mysqli, "UPDATE payment_webhook_events SET event_status = 'ignored' WHERE event_id = $event_row_id");
    http_response_code(200);
    exit('OK');
}

$invoice_id            = intval($inv['invoice_id']);
$invoice_prefix        = sanitizeInput($inv['invoice_prefix']);
$invoice_number        = intval($inv['invoice_number']);
$invoice_amount        = floatval($inv['invoice_amount']);
$invoice_currency_code = sanitizeInput($inv['invoice_currency_code']);
$invoice_url_key       = sanitizeInput($inv['invoice_url_key']);
$client_id             = intval($inv['client_id']);
$client_name           = sanitizeInput($inv['client_name']);
$contact_name          = sanitizeInput($inv['contact_name']);
$contact_email         = sanitizeInput($inv['contact_email']);

// Provider fee / account config
$prow = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payment_providers WHERE payment_provider_id = $provider_id LIMIT 1"));
$provider_account_id       = intval($prow['payment_provider_account']);
$provider_expense_vendor   = intval($prow['payment_provider_expense_vendor']);
$provider_expense_category = intval($prow['payment_provider_expense_category']);
$provider_percentage_fee   = floatval($prow['payment_provider_expense_percentage_fee']);
$provider_flat_fee         = floatval($prow['payment_provider_expense_flat_fee']);

// Company info (currency formatting, base url, receipt email)
$company_row = mysqli_fetch_assoc(mysqli_query($mysqli, "
    SELECT companies.*, settings.config_smtp_host, settings.config_invoice_from_name,
           settings.config_invoice_from_email, settings.config_invoice_paid_notification_email
    FROM companies
    LEFT JOIN settings ON settings.company_id = companies.company_id
    WHERE companies.company_id = 1
"));
$company_name    = sanitizeInput($company_row['company_name']);
$company_phone   = sanitizeInput(formatPhoneNumber($company_row['company_phone'], $company_row['company_phone_country_code']));
$company_locale  = sanitizeInput($company_row['company_locale']);
$config_base_url = sanitizeInput($company_row['company_base_url'] ?? '');
$config_smtp_host = $company_row['config_smtp_host'] ?? '';
$config_invoice_from_name  = sanitizeInput($company_row['config_invoice_from_name'] ?? '');
$config_invoice_from_email = sanitizeInput($company_row['config_invoice_from_email'] ?? '');
$config_invoice_paid_notification_email = sanitizeInput($company_row['config_invoice_paid_notification_email'] ?? '');

$currency_format = numfmt_create($company_locale, NumberFormatter::CURRENCY);

// --- Record the payment ---
mysqli_query($mysqli, "INSERT INTO payments SET payment_date = '$pi_date', payment_amount = $pi_amount_paid, payment_currency_code = '$pi_currency', payment_account_id = $provider_account_id, payment_method = '$provider_display_safe', payment_reference = 'Stripe - $pi_id', payment_invoice_id = $invoice_id");

// Recompute invoice status from total payments
$paid_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT SUM(payment_amount) AS amount_paid FROM payments WHERE payment_invoice_id = $invoice_id"));
$total_paid = floatval($paid_row['amount_paid']);
$invoice_status = ($invoice_amount - $total_paid <= 0) ? 'Paid' : 'Partial';
mysqli_query($mysqli, "UPDATE invoices SET invoice_status = '$invoice_status' WHERE invoice_id = $invoice_id");
mysqli_query($mysqli, "INSERT INTO history SET history_status = '$invoice_status', history_description = 'Online Payment added (webhook)', history_invoice_id = $invoice_id");

// Gateway fee expense (if configured)
if ($provider_expense_vendor > 0 && $provider_expense_category > 0) {
    $gateway_fee = round($pi_amount_paid * $provider_percentage_fee + $provider_flat_fee, 2);
    mysqli_query($mysqli, "INSERT INTO expenses SET expense_date = '$pi_date', expense_amount = $gateway_fee, expense_currency_code = '$invoice_currency_code', expense_account_id = $provider_account_id, expense_vendor_id = $provider_expense_vendor, expense_client_id = $client_id, expense_category_id = $provider_expense_category, expense_description = 'Stripe Transaction for Invoice $invoice_prefix$invoice_number In the Amount of $pi_amount_paid', expense_reference = 'Stripe - $pi_id $extended_log_desc'");
}

// Notify + log
appNotify("Invoice Paid", "Invoice $invoice_prefix$invoice_number has been paid by $client_name ($provider_display webhook)", "/agent/invoice.php?invoice_id=$invoice_id", $client_id);
mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Payment', log_action = 'Create', log_description = '$provider_display_safe webhook payment of $pi_currency $pi_amount_paid against invoice $invoice_prefix$invoice_number - $pi_id $extended_log_desc', log_ip = '$ip', log_client_id = $client_id");
customAction('invoice_pay', $invoice_id);

// Receipt email (mirrors the redirect handler; covers the dropped-redirect case)
if (!empty($config_smtp_host)) {
    $subject = "Payment Received - Invoice $invoice_prefix$invoice_number";
    $body = "Hello $contact_name,<br><br>We have received online payment for the amount of " . numfmt_format_currency($currency_format, $pi_amount_paid, $invoice_currency_code) . " for invoice <a href=\\'https://$config_base_url/guest/guest_view_invoice.php?invoice_id=$invoice_id&url_key=$invoice_url_key\\'>$invoice_prefix$invoice_number</a>. Please keep this email as a receipt for your records.<br><br>Amount: " . numfmt_format_currency($currency_format, $pi_amount_paid, $invoice_currency_code) . "<br><br>Thank you for your business!<br><br><br>~<br>$company_name - Billing<br>$config_invoice_from_email<br>$company_phone";

    $data = [[
        'from'           => $config_invoice_from_email,
        'from_name'      => $config_invoice_from_name,
        'recipient'      => $contact_email,
        'recipient_name' => $contact_name,
        'subject'        => $subject,
        'body'           => $body,
    ]];

    if (!empty($config_invoice_paid_notification_email)) {
        $subject_internal = "Payment Received - $client_name - Invoice $invoice_prefix$invoice_number";
        $body_internal = "This is a notification that an invoice has been paid in ITFlow. Below is a copy of the receipt sent to the client:-<br><br>--------<br><br>$body";
        $data[] = [
            'from'           => $config_invoice_from_email,
            'from_name'      => $config_invoice_from_name,
            'recipient'      => $config_invoice_paid_notification_email,
            'recipient_name' => $contact_name,
            'subject'        => $subject_internal,
            'body'           => $body_internal,
        ];
    }
    addToMailQueue($data);
    mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Sent', history_description = 'Emailed Receipt (webhook)!', history_invoice_id = $invoice_id");
}

mysqli_query($mysqli, "UPDATE payment_webhook_events SET event_status = 'processed' WHERE event_id = $event_row_id");

http_response_code(200);
echo 'OK';
