<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Billing state-machine and money guards (includes/billing_guards.php). Pure PHP, no database needed:
 *   php tests/billing_guards.php        (exit 0 = all pass)
 * Covers: positive-only payment amounts, cents comparison instead of float ==, which invoice states accept
 * a payment, Paid invoices being locked, quote accept/decline/invoice transitions, Stripe idempotency keys.
 */
require __DIR__ . '/../includes/billing_guards.php';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

// parsePositiveMoney
$ok(parsePositiveMoney('10.50') === 10.5, 'valid amount parses');
$ok(parsePositiveMoney('-5') === null, 'negative amount rejected');
$ok(parsePositiveMoney('0') === null, 'zero rejected');
$ok(parsePositiveMoney('0.004') === null, 'sub-cent amount rounds to zero and is rejected');
$ok(parsePositiveMoney('abc') === null, 'non-numeric rejected');
$ok(parsePositiveMoney('') === null && parsePositiveMoney(null) === null, 'empty/null rejected');
$ok(parsePositiveMoney('INF') === null && parsePositiveMoney('NAN') === null, 'INF/NAN rejected');
$ok(parsePositiveMoney('1e400') === null, 'overflow to INF rejected');

// cents comparison
$ok(0.1 + 0.2 != 0.3, 'sanity: float == is unreliable');
$ok(moneyToCents(0.1 + 0.2) === moneyToCents(0.3), 'cents comparison treats 0.1+0.2 as 0.30');
$ok(invoiceStatusAfterPayment(100.00, 99.99999999999) === 'Paid', 'float residue no longer leaves invoice Partial');
$ok(invoiceStatusAfterPayment(100.00, 99.99) === 'Partial', 'one cent short stays Partial');
$ok(invoiceStatusAfterPayment(100.00, 100.00) === 'Paid', 'exact payment is Paid');
$ok(paymentFitsBalance(19.99, 19.99) && !paymentFitsBalance(20.00, 19.99), 'balance check in cents');
$ok(moneyToCents(19.99) === 1999, '19.99 is 1999 cents (not 1998)');

// invoice states
foreach (['Sent', 'Viewed', 'Partial', 'Overdue'] as $st) $ok(invoiceStatusAcceptsPayment($st), "$st accepts payment");
foreach (['Draft', 'Paid', 'Cancelled', 'Non-Billable', '', null] as $st) $ok(!invoiceStatusAcceptsPayment($st), "'" . (string) $st . "' does not accept payment");
$ok(invoiceStatusIsLocked('Paid') && !invoiceStatusIsLocked('Draft') && !invoiceStatusIsLocked('Partial'), 'only Paid is locked');

// quotes
$now = strtotime('2026-10-06 12:00:00');
$ok(quoteCanBeAnswered('Sent', '2026-12-01', $now) && quoteCanBeAnswered('Viewed', '', $now), 'Sent/Viewed can be answered');
$ok(!quoteCanBeAnswered('Accepted', '2026-12-01', $now), 'accepted quote cannot be flipped');
$ok(!quoteCanBeAnswered('Declined', '2026-12-01', $now), 'declined quote cannot be flipped');
$ok(!quoteCanBeAnswered('Invoiced', '2026-12-01', $now), 'invoiced quote cannot be answered');
$ok(!quoteCanBeAnswered('Draft', '', $now), 'draft quote cannot be answered by a link');
$ok(!quoteCanBeAnswered('Sent', '2026-10-01', $now), 'expired quote cannot be accepted');
$ok(quoteCanBeAnswered('Sent', '2026-10-06', $now), 'quote expiring today is still answerable');
$ok(quoteCanBeAnswered('Sent', '0000-00-00', $now), 'zero-date expiry means no expiry');
$ok(quoteCanBeInvoiced('Accepted'), 'accepted quote can be invoiced');
foreach (['Draft', 'Sent', 'Viewed', 'Declined', 'Invoiced'] as $st) $ok(!quoteCanBeInvoiced($st), "$st quote cannot be invoiced (no approval bypass / no re-invoice)");

// idempotency key
$k1 = stripeChargeIdempotencyKey(7, 50.00, 3, 1000000);
$ok($k1 === stripeChargeIdempotencyKey(7, 50.00, 3, 1000010), 'same minute -> same key (double click collapses)');
$ok($k1 !== stripeChargeIdempotencyKey(7, 50.00, 3, 1000000 + 120), 'later minute -> new key (retry possible)');
$ok($k1 !== stripeChargeIdempotencyKey(8, 50.00, 3, 1000000) && $k1 !== stripeChargeIdempotencyKey(7, 51.00, 3, 1000000), 'key depends on invoice and amount');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
