<?php
/*
 * agent/post/payment.php add_payment: a negative amount must be refused before any payment row is written (it passes the
 * "amount > balance" check and would otherwise insert a negative payment). No database, no network: asserts the guard sits in the
 * add_payment handler and before the INSERT.
 *   php tests/payment_negative_amount_guard.php
 */
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$src = (string) file_get_contents(__DIR__ . '/../agent/post/payment.php');
$start = strpos($src, "isset(\$_POST['add_payment'])");
$end = $start === false ? false : strpos($src, "isset(\$_POST['edit_payment'])", $start);
$ok($start !== false && $end !== false, 'add_payment handler found');
$h = ($start === false || $end === false) ? '' : substr($src, $start, $end - $start);
// negative (and zero / non-numeric) amounts are refused by parsePositiveMoney() before the balance check and the INSERT
$guard = strpos($h, 'parsePositiveMoney($_POST[\'amount\']');
$insert = strpos($h, 'INSERT INTO payments');
$ok($guard !== false, 'a negative amount is checked');
$ok($guard !== false && $insert !== false && $guard < $insert, 'the check comes before the payment INSERT');
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
