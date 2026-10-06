<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
require_once __DIR__ . '/../includes/api_key_security.php';

function expectKeyCheck(bool $actual, bool $expected, string $label): void {
    if ($actual !== $expected) throw new RuntimeException($label);
}

foreach ([
    ['', '203.0.113.10', true],
    ['203.0.113.10', '203.0.113.10', true],
    ['203.0.113.10', '203.0.113.11', false],
    ['203.0.113.0/25', '203.0.113.127', true],
    ['203.0.113.0/25', '203.0.113.128', false],
    ['203.0.113.10/32', '203.0.113.10', true],
    ['0.0.0.0/0', '192.0.2.1', true],
    ['2001:db8::/32', '2001:db8:abcd::1', true],
    ['2001:db8::/32', '2001:db9::1', false],
    ['2001:db8::/65', '2001:db8::ffff', true],
    ['2001:db8::/65', '2001:db8:0:0:8000::1', false],
    ['::1/128', '::1', true],
    ['::1/128', '::2', false],
    ['203.0.113.10, ::1', '::1', true],
    ['203.0.113.0/24', '::ffff:203.0.113.10', false],
    ['invalid', '203.0.113.10', false],
    [',', '203.0.113.10', false],
    ['203.0.113.0/33', '203.0.113.10', false],
    ['203.0.113.10', 'invalid', false],
] as [$list, $ip, $allowed]) {
    expectKeyCheck(rivetitApiKeyIpAllowed($list, $ip), $allowed, "IP check failed: $list / $ip");
}
foreach (['hostname.example', '203.0.113.0/-1', '::/129', '203.0.113.0/24/1', ',', str_repeat('::1 ', 101)] as $invalid) {
    try {
        rivetitApiKeyNetworks($invalid);
        throw new RuntimeException('Invalid network was accepted');
    } catch (InvalidArgumentException $expected) {}
}

$read = ['api_key_permission' => 'read', 'api_key_allow_delete' => 1];
$write = ['api_key_permission' => 'write', 'api_key_allow_delete' => 0];
$delete = ['api_key_permission' => 'write', 'api_key_allow_delete' => 1];
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    expectKeyCheck(rivetitApiKeyRequestAllowed($read, $method), false, "Read key allowed $method");
}
foreach (['GET', 'POST', 'PUT', 'PATCH'] as $method) {
    expectKeyCheck(rivetitApiKeyRequestAllowed($write, $method), true, "Write key denied $method");
}
expectKeyCheck(rivetitApiKeyRequestAllowed($write, 'DELETE'), false, 'Write key allowed delete');
expectKeyCheck(rivetitApiKeyRequestAllowed($write, 'POST', true), false, 'Write key allowed legacy delete');
expectKeyCheck(rivetitApiKeyRequestAllowed($delete, 'DELETE'), true, 'Delete scope denied REST delete');
expectKeyCheck(rivetitApiKeyRequestAllowed($delete, 'POST', true), true, 'Delete scope denied legacy delete');
expectKeyCheck(rivetitApiKeyRequestAllowed($delete, 'TRACE'), false, 'Unsupported method allowed');
expectKeyCheck(rivetitApiKeyRequestAllowed(['api_key_permission' => 'invalid'], 'GET'), false, 'Invalid permission allowed');
echo "API key IP and permission checks passed\n";
