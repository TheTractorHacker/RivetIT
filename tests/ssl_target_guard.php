<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * sslTargetIp() / sslAllowedPorts() (functions.php) for getSSL() (pentest F-06, IT-3): loopback/link-local/reserved targets are refused,
 * private space only inside an administrator-allowed network, and only TLS ports are connected to. No DB, no network
 * (only literal IPs and "localhost" resolution).
 *   php tests/ssl_target_guard.php
 */
require_once __DIR__ . '/../vendor/rivet/rivet-core/src/Webhooks/NetworkList.php';
$src = file_get_contents(__DIR__ . '/../functions.php');
preg_match('/function sslTargetIp\(\$name, \$allowed_networks = null\)\n\{.*?\n\}\n/s', $src, $m);
eval($m[0]);
preg_match('/function sslAllowedPorts\(\)\n\{.*?\n\}\n/s', $src, $m);
eval($m[0]);
function sslAllowedInternalNetworks() { return []; }   // no administrator list configured
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
foreach (['127.0.0.1', '127.1.2.3', '169.254.169.254', '0.0.0.0', '::1', '::', 'fe80::1', '::ffff:127.0.0.1', '224.0.0.1', 'localhost'] as $bad) {
    $ok(sslTargetIp($bad) === null, "refuses $bad");
    $ok(sslTargetIp($bad, ['127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/0']) === null, "refuses $bad even when a network list is configured");
}
foreach (['8.8.8.8', '2606:4700:4700::1111'] as $good) {
    $ok(sslTargetIp($good) === $good, "allows public $good");
}
foreach (['192.168.1.10', '10.0.0.5', '172.16.5.5', '100.64.0.9', 'fd00::5', '::ffff:10.0.0.5'] as $priv) {
    $ok(sslTargetIp($priv) === null, "refuses private $priv when no internal network is allowed");
}
$ok(sslTargetIp('192.168.1.10', ['192.168.1.0/24']) === '192.168.1.10', 'allows a private address inside an allowed network');
$ok(sslTargetIp('192.168.2.10', ['192.168.1.0/24']) === null, 'refuses a private address outside the allowed networks');
$ok(sslTargetIp('10.0.0.5', []) === null, 'an empty allowed list means no internal access');

foreach ([443, 8443, 993, 636] as $p) { $ok(in_array($p, sslAllowedPorts(), true), "port $p is allowed"); }
foreach ([22, 25, 80, 3306, 6379, 5432, 8080, 2375, 1, 65535, 0] as $p) { $ok(!in_array($p, sslAllowedPorts(), true), "port $p is refused"); }
$ok(strpos($src, '!in_array($port, sslAllowedPorts(), true)') !== false, 'getSSL() enforces the port list');
exit($fails ? 1 : 0);
