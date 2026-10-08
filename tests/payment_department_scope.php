<?php
/*
 * agent/post/payment.php edit_payment / delete_payment: the department checked by enforceClientAccess() must be the payment's invoice's
 * department. payments has no payment_client_id column, so reading it (getFieldById() falls back to the row's own id; a plain
 * $row['payment_client_id'] is unset) made the check test an unrelated department or none at all. No database, no network:
 * asserts the schema fact and the handler source.
 *   php tests/payment_department_scope.php
 */
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$sql = (string) file_get_contents(__DIR__ . '/../db.sql');
preg_match('/CREATE TABLE `payments` \((.*?)\n\) ENGINE/s', $sql, $m);
$ok(isset($m[1]) && !str_contains($m[1], '`payment_client_id`') && str_contains($m[1], '`payment_invoice_id`'), 'payments has payment_invoice_id and no payment_client_id column');

$src = (string) file_get_contents(__DIR__ . '/../agent/post/payment.php');
$ok(!preg_match('/[\'\"]payment_client_id[\'\"]/', $src), 'payment.php never reads the non-existent payment_client_id');
foreach (['edit_payment' => "isset(\$_POST['edit_payment'])", 'delete_payment' => "isset(\$_GET['delete_payment'])"] as $name => $needle) {
    $start = strpos($src, $needle);
    // up to and including the department check (a "payment not found" redirect may come first)
    $end = $start === false ? false : strpos($src, 'enforceClientAccess();', $start);
    $h = ($start === false || $end === false) ? '' : substr($src, $start, $end + strlen('enforceClientAccess();') - $start);
    $ok($start !== false && $h !== '', "$name handler found");
    $ok(str_contains($h, 'invoice_client_id') && str_contains($h, 'LEFT JOIN invoices ON payment_invoice_id = invoice_id'), "$name takes the department from the payment's invoice");
    $ok(strpos($h, 'invoice_client_id') < strpos($h, 'enforceClientAccess()'), "$name resolves the department before enforceClientAccess()");
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
