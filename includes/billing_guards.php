<?php

/*
 * Pure (database-free) billing state and money guards shared by the invoice, quote and payment handlers.
 * Kept free of globals so tests/billing_guards.php can exercise it without a database.
 */

/**
 * Convert a money value to integer cents so comparisons never rely on float ==.
 */
function moneyToCents($value): int
{
    return (int) round(((float) $value) * 100);
}

/**
 * Parse a posted payment amount. Returns the amount rounded to cents, or null when it is not a
 * finite number greater than zero.
 */
function parsePositiveMoney($raw): ?float
{
    if (!is_numeric($raw)) {
        return null;
    }
    $value = (float) $raw;
    if (!is_finite($value)) {
        return null;
    }
    $cents = moneyToCents($value);
    if ($cents <= 0) {
        return null;
    }
    return $cents / 100;
}

/**
 * Invoice states that can still receive a payment.
 */
function invoiceStatusAcceptsPayment($status): bool
{
    return in_array((string) $status, ['Sent', 'Viewed', 'Partial', 'Overdue'], true);
}

/**
 * A Paid invoice is a closed financial record: no item edits, status changes, cancel or delete.
 */
function invoiceStatusIsLocked($status): bool
{
    return (string) $status === 'Paid';
}

/**
 * Status an invoice should have after a payment, comparing in cents (never float ==).
 * Returns 'Paid' when nothing is left, 'Partial' otherwise.
 */
function invoiceStatusAfterPayment($invoice_amount, $total_paid): string
{
    return moneyToCents($invoice_amount) - moneyToCents($total_paid) <= 0 ? 'Paid' : 'Partial';
}

/**
 * Whether a payment of $amount fits within the remaining balance (cents comparison).
 */
function paymentFitsBalance($amount, $balance): bool
{
    return moneyToCents($amount) <= moneyToCents($balance);
}

/**
 * A quote may only be accepted or declined from Sent/Viewed, and not after it expired.
 * $expire is the quote_expire date string (may be empty); $now is a unix timestamp.
 */
function quoteCanBeAnswered($status, $expire, ?int $now = null): bool
{
    if (!in_array((string) $status, ['Sent', 'Viewed'], true)) {
        return false;
    }
    $now = $now ?? time();
    $expire = trim((string) $expire);
    if ($expire !== '' && strpos($expire, '0000-00-00') !== 0) {
        $ts = strtotime($expire . ' 23:59:59');
        if ($ts !== false && $ts < $now) {
            return false;
        }
    }
    return true;
}

/**
 * A quote may only be turned into an invoice once the client (or an agent) accepted it.
 * Re-invoicing an already Invoiced quote is refused.
 */
function quoteCanBeInvoiced($status): bool
{
    return (string) $status === 'Accepted';
}

/**
 * Idempotency key for creating a Stripe PaymentIntent: the same invoice, amount and saved method
 * within the same minute map to one key, so a double-click or parallel POST cannot charge twice.
 * (The minute bucket keeps a genuine retry after a decline possible.)
 */
function stripeChargeIdempotencyKey($invoice_id, $amount, $saved_method_id, ?int $now = null): string
{
    $now = $now ?? time();
    return 'rivetit-inv' . intval($invoice_id) . '-' . moneyToCents($amount) . '-pm' . intval($saved_method_id) . '-' . intval(floor($now / 60));
}

/**
 * Record a Stripe payment exactly once per PaymentIntent. Returns the new payment_id, or 0 when
 * a payment for this PaymentIntent already exists (recorded by the webhook, the portal, the agent
 * or autopay path). Works before and after the payment_provider_ref UNIQUE index migration:
 * the existence check runs on payment_reference, and the index (when present) closes the race.
 * $pi_id and $currency must already be sanitised by the caller.
 */
function insertStripePaymentOnce($mysqli, $date, $amount, $currency, $account_id, $method, $pi_id, $invoice_id): int
{
    static $has_provider_ref = null;

    $reference = "Stripe - $pi_id";

    $exists = mysqli_query($mysqli, "SELECT payment_id FROM payments WHERE payment_reference = '$reference' LIMIT 1");
    if ($exists && mysqli_num_rows($exists) > 0) {
        return 0;
    }

    if ($has_provider_ref === null) {
        $col = mysqli_query($mysqli, "SHOW COLUMNS FROM payments LIKE 'payment_provider_ref'");
        $has_provider_ref = ($col && mysqli_num_rows($col) > 0);
    }

    $account_id = intval($account_id);
    $invoice_id = intval($invoice_id);
    $amount = floatval($amount);
    $ref_sql = $has_provider_ref ? ", payment_provider_ref = '$reference'" : '';

    $sql = "INSERT INTO payments SET payment_date = '$date', payment_amount = $amount, payment_currency_code = '$currency', payment_account_id = $account_id, payment_method = '$method', payment_reference = '$reference'$ref_sql, payment_invoice_id = $invoice_id";

    try {
        $ok = mysqli_query($mysqli, $sql);
    } catch (\mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            return 0;
        }
        throw $e;
    }
    if (!$ok) {
        return 0; // duplicate (errno 1062) or any failed insert: nothing was recorded
    }

    return (int) mysqli_insert_id($mysqli);
}

/**
 * Why an invoice may not be edited/cancelled/deleted right now, or null when the change is allowed.
 * A Paid invoice is closed. With $block_if_payments, any invoice that already has payments recorded
 * is also refused (cancel / non-billable / delete would orphan or destroy the financial record).
 */
function invoiceChangeBlockReason($mysqli, $invoice_id, bool $block_if_payments = false): ?string
{
    $invoice_id = intval($invoice_id);
    $res = mysqli_query($mysqli, "SELECT invoice_status FROM invoices WHERE invoice_id = $invoice_id LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        return 'Invoice not found';
    }
    if (invoiceStatusIsLocked($row['invoice_status'])) {
        return 'A paid invoice is a closed record and cannot be changed';
    }
    if ($block_if_payments) {
        $pay = mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM payments WHERE payment_invoice_id = $invoice_id");
        $prow = $pay ? mysqli_fetch_assoc($pay) : null;
        if ($prow && intval($prow['c']) > 0) {
            return 'This invoice has payments recorded; remove or reconcile the payments first';
        }
    }
    return null;
}
