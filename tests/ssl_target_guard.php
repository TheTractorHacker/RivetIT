<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * sslTargetIp() (functions.php) refuses loopback/link-local/reserved targets for getSSL() (pentest F-06). No DB, no network
 * (only literal IPs and "localhost" resolution).
 *   php tests/ssl_target_guard.php
 */
$src = file_get_contents(__DIR__ . '/../functions.php');
preg_match('/function sslTargetIp\(\$name\)\n\{.*?\n\}\n/s', $src, $m);
eval($m[0]);
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
foreach (['127.0.0.1', '127.1.2.3', '169.254.169.254', '0.0.0.0', '::1', '::', 'fe80::1', '::ffff:127.0.0.1', '224.0.0.1', 'localhost'] as $bad) {
    $ok(sslTargetIp($bad) === null, "refuses $bad");
}
foreach (['8.8.8.8', '192.168.1.10', '10.0.0.5', '2606:4700:4700::1111'] as $good) {
    $ok(sslTargetIp($good) === $good, "allows $good");
}
exit($fails ? 1 : 0);
