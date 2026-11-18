<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Onboarding packet: PacketRequest validation. Pure PHP, no DB.  php tests/onboarding_packet_request.php */
require __DIR__ . '/../src/Onboarding/PacketRequest.php';
use ITFlow\Onboarding\PacketRequest;

$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . ": $m\n"; if (!$c) { $fail++; } };
$throws = function (array $post, string $needle = '') {
    try { PacketRequest::fromPost($post); } catch (\InvalidArgumentException $e) { return $needle === '' || str_contains($e->getMessage(), $needle); }
    return false;
};

$base = [
    'computer' => ['username' => 'jdoe', 'password' => 'pc-pass'],
    'email' => ['username' => 'jdoe@x.test', 'password' => 'mail-pass'],
    'software' => [['name' => 'CRM', 'username' => 'a', 'password' => 'b'], ['name' => 'ERP', 'username' => 'c', 'password' => 'd']],
    'start_date' => '2026-12-01', 'manager_name' => 'Pat',
];
$r = PacketRequest::fromPost($base);
$ok(count($r->lines) === 4 && array_column($r->lines, 'kind') === ['computer', 'email', 'software', 'software'], '4 lines in order computer,email,software,software');
$ok($r->startDate === '2026-12-01' && $r->managerName === 'Pat', 'start date and manager captured');

$p = $base; $p['email_same'] = '1';
$r = PacketRequest::fromPost($p);
$ok($r->lines[1]['kind'] === 'email' && $r->lines[1]['username'] === 'jdoe' && $r->lines[1]['password'] === 'pc-pass', 'email_same copies computer username/password, ignores posted email password');

$p = $base; $p['computer']['change'] = '0'; $p['email']['save'] = '1';
$r = PacketRequest::fromPost($p);
$ok($r->lines[0]['change'] === false && $r->lines[1]['change'] === true && $r->lines[0]['save'] === false && $r->lines[1]['save'] === true, 'change defaults true (false only for "0"); save defaults false');

$p = $base; $p['software'][] = ['name' => '', 'username' => '', 'password' => '']; $p['software'][] = ['name' => 'OnlyName'];
$r = PacketRequest::fromPost($p);
$ok(count($r->lines) === 5 && $r->lines[4]['name'] === 'OnlyName', 'blank software rows dropped; name-only row kept');

$p = $base; $p['software'] = array_fill(0, 26, ['name' => 'X', 'username' => 'u', 'password' => 'p']);
$ok($throws($p), '26 software rows throws');
$p = $base; $p['software'] = array_fill(0, 25, ['name' => 'X', 'username' => 'u', 'password' => 'p']);
$ok(count(PacketRequest::fromPost($p)->lines) === 27, '25 software rows allowed');
$p = $base; $p['computer']['username'] = str_repeat('a', 201);
$ok($throws($p), '201-char username throws');
$p = $base; $p['computer']['password'] = str_repeat('a', 501);
$ok($throws($p), '501-char password throws');
$p = $base; $p['computer']['password'] = str_repeat('a', 500);
$ok(!$throws($p), '500-char password allowed');
$p = $base; $p['software'][0]['save'] = '1'; $p['software'][0]['password'] = '';
$ok($throws($p, 'needs a password'), 'save=1 with empty password throws "needs a password"');

$pw = "Pässw0rd<b>&'\"%";
$p = $base; $p['computer']['password'] = "  $pw \n";
$ok(PacketRequest::fromPost($p)->lines[0]['password'] === $pw, 'password round-trips unchanged; outer whitespace trimmed');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
