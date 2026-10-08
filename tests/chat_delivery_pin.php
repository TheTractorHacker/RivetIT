<?php
/*
 * ChatDelivery::post() must hand curl the vetted host spelling (pinnedUrl) so the CURLOPT_RESOLVE pin always applies: a host written
 * with a trailing dot ("hooks.example.com.") passes the vetting but never matches the pin, and curl then resolves the name itself.
 * No database, no network. Run:  php tests/chat_delivery_pin.php
 */
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$src = (string) file_get_contents(__DIR__ . '/../src/Webhooks/ChatDelivery.php');
$ok(str_contains($src, '$pinned_url = \\RivetCore\\Webhooks\\WebhookDispatcher::pinnedUrl($url,') && str_contains($src, 'curl_init($pinned_url)'), 'ChatDelivery::post connects through pinnedUrl()');
$ok(!preg_match('/curl_init\(\$url\)/', $src), 'ChatDelivery::post never hands the raw URL to curl_init');

// The pinnedUrl() behaviour itself (trailing dot, case, port) is covered in RivetCore's own tests and in tests/automation_webhook_handler.php.
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
