<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Slack/Teams delivery hands curl the pinned URL (pentest IT-6): the host spelling curl sees is the one CURLOPT_RESOLVE is keyed on. No DB, no network. */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$src = file_get_contents(__DIR__ . '/../src/Webhooks/ChatDelivery.php');
$ok(strpos($src, 'WebhookDispatcher::pinnedUrl($url, $vet)') !== false, 'ChatDelivery::post builds the URL with WebhookDispatcher::pinnedUrl()');
$ok(strpos($src, 'curl_init($url)') === false, 'curl is never given the raw URL');
$ok(strpos($src, "method_exists(\\RivetCore\\Webhooks\\WebhookDispatcher::class, 'pinnedUrl')") !== false, 'delivery fails closed if RivetCore cannot pin');
$file = __DIR__ . '/../vendor/rivet/rivet-core/src/Webhooks/WebhookDispatcher.php';
if (is_file($file)) {
    // Extract just the pure static method (the package autoloader is not available in a bare checkout).
    preg_match('/public static function pinnedUrl\(string \$url, array \$target\): string\n    \{.*?\n    \}\n/s', file_get_contents($file), $m);
    if ($m) {
        eval('final class PinnedUrlProbe { ' . $m[0] . ' }');
        $t = ['host' => 'example.com', 'port' => 443, 'ips' => ['93.184.216.34']];
        $ok(PinnedUrlProbe::pinnedUrl('https://Example.COM./services/T/B/x?a=1', $t) === 'https://example.com/services/T/B/x?a=1', 'pinnedUrl normalises a trailing-dot / mixed-case host to the pinned spelling');
    } else {
        echo "SKIP  pinnedUrl not found in the installed RivetCore\n";
    }
}
exit($fails ? 1 : 0);
