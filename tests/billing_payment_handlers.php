<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Wiring checks: payment handlers use the billing guards and the once-per-PaymentIntent insert. No DB, no network. */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$r = function ($f) { return file_get_contents(__DIR__ . '/../' . $f); };

$pay = $r('agent/post/payment.php');
$ok(strpos($pay, 'invoiceStatusAcceptsPayment(') !== false, 'add_payment checks the invoice is payable');
$ok(substr_count($pay, 'parsePositiveMoney(') >= 2, 'add_payment and edit_payment require a positive amount');
$ok(strpos($pay, 'FOR UPDATE') !== false, 'add_payment recomputes the balance under a row lock');
$ok(strpos($pay, "getFieldById('payments', \$payment_id, 'payment_client_id')") === false, 'edit_payment no longer reads a payments.payment_client_id column that does not exist');
$ok(strpos($pay, 'LEFT JOIN invoices ON payment_invoice_id = invoice_id') !== false, 'edit_payment resolves the department through the invoice');
$ok(strpos($pay, 'intval($balance_to_pay) == intval($pi_amount_paid)') === false, 'Stripe amount match compares cents, not truncated integers');
foreach (['agent/post/payment.php', 'guest/guest_pay_invoice_stripe.php', 'guest/payment_webhook.php', 'client/post.php', 'cron/cron.php'] as $f) {
    $s = $r($f);
    $ok(strpos($s, 'insertStripePaymentOnce(') !== false, "$f records Stripe payments once per PaymentIntent");
    $ok(!preg_match('/INSERT INTO payments SET[^"]*Stripe - \$pi_id/', $s), "$f has no raw Stripe payment INSERT");
}
$mig = $r('admin/database_updates.php');
$ok(strpos($mig, 'uniq_payment_provider_ref') !== false && strpos($mig, 'ADD COLUMN IF NOT EXISTS `payment_provider_ref`') !== false, 'migration adds payment_provider_ref with a UNIQUE index idempotently');
$ok(strpos($r('db.sql'), 'uniq_payment_provider_ref') !== false, 'db.sql carries the UNIQUE index');
preg_match('/"(\d+\.\d+\.\d+)"\)/', $r('includes/database_version.php'), $m);
$ok(strpos($mig, "config_current_database_version` = '{$m[1]}'") !== false, 'latest database version is the last migration step');
exit($fails ? 1 : 0);
