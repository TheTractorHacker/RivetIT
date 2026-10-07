<?php
/*
 * guest/guest_post.php: a quote link (quote id + url_key) may only accept or decline an OPEN quote (Sent/Viewed), and accept only before
 * it expires. Without the guard the link alone could flip an Invoiced or Declined quote back to Accepted (the agent side would then
 * invoice it again) or accept an expired quote. No database, no network: asserts the guard is in the SELECT that gates each handler.
 *   php tests/guest_quote_state_guard.php
 */
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$src = (string) file_get_contents(__DIR__ . '/../guest/guest_post.php');
foreach (['accept_quote', 'decline_quote'] as $action) {
    $start = strpos($src, "isset(\$_POST['$action'], \$_POST['url_key'])");
    $ok($start !== false, "$action handler found");
    $end = $start === false ? false : strpos($src, 'if (mysqli_num_rows($sql) == 1)', $start);
    $gate = ($start === false || $end === false) ? '' : substr($src, $start, $end - $start);
    $ok(str_contains($gate, "quote_url_key = '\$url_key'"), "$action: the url_key still gates the lookup");
    $ok(str_contains($gate, "quote_status IN ('Sent', 'Viewed')"), "$action: only a Sent/Viewed quote is selected");
    if ($action === 'accept_quote') {
        $ok(str_contains($gate, 'quote_expire IS NULL OR quote_expire >= CURDATE()'), 'accept_quote: an expired quote cannot be accepted');
    }
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
