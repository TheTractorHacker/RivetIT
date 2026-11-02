<?php
/*
 * QMS address vetting (issue #52) WITHOUT the loopback test seam: run as its own process by tests/quality_module.php, or directly:
 *   RIVETIT_TEST_DB=1 php tests/quality_ssrf.php
 * Uses IP literals and "localhost" only (no DNS to the outside), against the scratch database's allowed-networks setting.
 */

use ITFlow\Quality\QmsUrl;

if (getenv('RIVETIT_TEST_DB') !== '1' && !isset($argv)) {
    exit(2);
}
$root = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $root;
require_once $root . '/config.php';
if (!str_contains((string) $database, 'scratch')) {
    fwrite(STDERR, "Refusing: not a scratch database.\n");
    exit(2);
}
require_once $root . '/vendor/autoload.php';
require_once $root . '/functions.php';
if (defined('RIVETIT_QMS_TEST_LOOPBACK')) {
    fwrite(STDERR, "The test seam must not be defined here.\n");
    exit(2);
}
$bad = 0;
$n = 0;
$chk = function (string $name, bool $ok) use (&$bad, &$n): void {
    $n++;
    if (!$ok) { $bad++; echo "  FAIL $name\n"; }
};
$mysqli->query("UPDATE settings SET config_webhook_allowed_networks = ''");
$refused = [
    'http://quality.example.com' => 'plain http',
    'ftp://quality.example.com' => 'ftp',
    'file:///etc/passwd' => 'file',
    'gopher://127.0.0.1:70/' => 'gopher',
    'https://127.0.0.1' => 'loopback',
    'https://127.1.2.3/' => 'loopback range',
    'https://localhost/' => 'localhost',
    'https://[::1]/' => 'ipv6 loopback',
    'https://169.254.169.254/latest/meta-data/' => 'cloud metadata',
    'https://[fe80::1]/' => 'link-local v6',
    'https://10.0.0.5/' => 'private 10/8 (not allowed)',
    'https://192.168.1.10/' => 'private 192.168/16',
    'https://172.16.0.9/' => 'private 172.16/12',
    'https://100.64.0.1/' => 'carrier-grade NAT',
    'https://0.0.0.0/' => 'unspecified',
    'https://[::ffff:127.0.0.1]/' => 'ipv4-mapped loopback',
    'https://[::ffff:10.0.0.5]/' => 'ipv4-mapped private',
    'https://user:pass@8.8.8.8/' => 'credentials in the URL',
    'https://8.8.8.8/?x=1' => 'query',
    'https://8.8.8.8/#frag' => 'fragment',
    'https://8.8.8.8/a/../b' => 'path traversal',
    'https://8.8.8.8/a b' => 'space',
    "https://8.8.8.8/a\nb" => 'newline',
    'https://8.8.8.8\\@127.0.0.1/' => 'backslash trick',
    'https://a%62c.example/' => 'percent-encoded host',
    '' => 'empty',
    'quality.example.com' => 'no scheme',
    'https://' => 'no host',
    'https://8.8.8.8/' . str_repeat('a', 600) => 'too long',
];
foreach ($refused as $url => $why) {
    $chk("refused: $why", QmsUrl::vet($mysqli, $url)['ok'] === false);
}
foreach (['https://8.8.8.8', 'https://8.8.8.8:8443', 'https://8.8.8.8/qms', 'https://8.8.8.8/qms/v1/', 'https://[2001:4860:4860::8888]/'] as $url) {
    $v = QmsUrl::vet($mysqli, $url);
    $chk("allowed: $url", $v['ok'] === true && $v['target'] !== null && $v['target']['ips'] !== []);
}
$v = QmsUrl::vet($mysqli, 'https://8.8.8.8/qms/');
$chk('trailing slash trimmed in the base', $v['base'] === 'https://8.8.8.8/qms');
$v = QmsUrl::vet($mysqli, 'https://8.8.8.8:8443');
$chk('port kept, target port set', $v['base'] === 'https://8.8.8.8:8443' && $v['target']['port'] === 8443);
// an internal network is allowed only when the administrator lists it
$mysqli->query("UPDATE settings SET config_webhook_allowed_networks = '10.0.0.0/8'");
$chk('private address allowed once its network is listed', QmsUrl::vet($mysqli, 'https://10.0.0.5/')['ok'] === true);
$chk('...but another private network still is not', QmsUrl::vet($mysqli, 'https://192.168.1.10/')['ok'] === false);
$chk('...and loopback and metadata never are, even if listed', (function () use ($mysqli) {
    $mysqli->query("UPDATE settings SET config_webhook_allowed_networks = '127.0.0.0/8,169.254.0.0/16,0.0.0.0/0'");
    $r = QmsUrl::vet($mysqli, 'https://127.0.0.1/')['ok'] === false && QmsUrl::vet($mysqli, 'https://169.254.169.254/')['ok'] === false;
    $mysqli->query("UPDATE settings SET config_webhook_allowed_networks = ''");
    return $r;
})());
// links shown to people
$base = 'https://quality.example.com/qms';
$chk('safeLink: same origin kept', QmsUrl::safeLink($base, 'https://quality.example.com/qms/doc/1') === 'https://quality.example.com/qms/doc/1');
foreach (['javascript:alert(1)', 'data:text/html,x', 'https://evil.example/x', 'http://quality.example.com/x', 'https://user:p@quality.example.com/x', 'https://quality.example.com:8443/x', '//evil.example/x', '/relative', 'https://quality.example.com/x y', null, 5] as $l) {
    $chk('safeLink refuses ' . json_encode($l), QmsUrl::safeLink($base, $l) === null);
}
$chk('sameOrigin compares scheme, host and port', QmsUrl::sameOrigin('https://a.example/x', 'https://A.example/y') && !QmsUrl::sameOrigin('https://a.example', 'https://b.example') && !QmsUrl::sameOrigin('https://a.example', 'https://a.example:444'));
echo $bad === 0 ? "ALL OK ($n checks)\n" : "$bad of $n failed\n";
exit($bad === 0 ? 0 : 1);
