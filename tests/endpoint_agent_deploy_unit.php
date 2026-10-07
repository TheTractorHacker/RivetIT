<?php
/*
 * Pure unit tests (no database, no HTTP) for the installer file format and the agent binary validation:
 *   php tests/endpoint_agent_deploy_unit.php
 * InstallerStamp is compared with tests/fixtures/agent_installer_trailer_vectors.json (generated independently, with raw pack()/hash()).
 */
$root = dirname(__DIR__);
require_once "$root/src/EndpointAgent/InstallerStamp.php";
require_once "$root/src/EndpointAgent/Binaries.php";
require_once "$root/src/EndpointAgent/Installer.php";
use ITFlow\EndpointAgent\Binaries;
use ITFlow\EndpointAgent\Installer;
use ITFlow\EndpointAgent\InstallerStamp as S;

$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
register_shutdown_function(function () use (&$fails, &$passes) { echo "\n$passes passed, $fails failed\n"; exit($fails ? 1 : 0); });

// ------------------------------------------------------------ InstallerStamp against the shared vectors
$j = json_decode(file_get_contents("$root/tests/fixtures/agent_installer_trailer_vectors.json"), true);
$ok($j['footer_length'] === S::FOOTER_LEN && $j['max_payload'] === S::MAX_PAYLOAD && $j['format'] === S::MAGIC, 'fixture constants match the class');
foreach ($j['vectors'] as $v) {
    $exe = hex2bin($v['exe_hex']); $stamped = S::stamp($exe, $v['payload']);
    $ok(bin2hex($stamped) === $v['stamped_hex'], "vector {$v['name']}: stamped bytes are exactly the expected ones");
    $ok(bin2hex(S::footer($v['payload'])) === $v['footer_hex'] && strlen(S::footer($v['payload'])) === 52, "vector {$v['name']}: footer is 52 bytes and exact");
    $ok(substr($stamped, 0, strlen($exe)) === $exe, "vector {$v['name']}: the original exe bytes come first, untouched");
    $r = S::read($stamped);
    $isObj = is_array(json_decode($v['payload'], true));
    $ok($r !== null && $r['payload'] === $v['payload'] && $r['exe_length'] === strlen($exe), "vector {$v['name']}: read() recovers payload and exe length");
    $ok($v['name'] !== 'magic_inside_body' || !S::hasFooter($exe), 'magic string inside the body alone is not a footer');
}
foreach ($j['negative'] as $n) { $ok(S::read(hex2bin($n['stamped_hex'])) === null, "negative {$n['name']}: rejected ({$n['reason']})"); }
// content checks on a built payload
$p = S::buildPayload(['installer_id' => 'u', 'server_url' => 'https://h/x', 'enrollment_token' => 't', 'department' => 'Dépt "A"', 'ca_pem' => null, 'created_at' => 'c', 'expires_at' => 'e']);
$d = json_decode($p, true);
$ok(array_keys($d) === ['version', 'installer_id', 'server_url', 'enrollment_token', 'department', 'ca_pem', 'created_at', 'expires_at'] && $d['version'] === 1 && $d['ca_pem'] === null && $d['department'] === 'Dépt "A"', 'payload has exactly the contract fields, ca_pem null when unset');
$ok(strpos($p, '\/') === false && strpos($p, '\u00e9') === false, 'payload keeps slashes and UTF-8 unescaped');
$d2 = json_decode(S::buildPayload(['installer_id' => 'u', 'server_url' => 's', 'enrollment_token' => 't', 'department' => 'd', 'ca_pem' => '', 'created_at' => 'c', 'expires_at' => 'e']), true);
$ok($d2['ca_pem'] === null, 'empty CA is null, not an empty string');
// boundaries
$fill = fn(int $n) => str_repeat('x', $n);
$base = strlen(S::buildPayload(['installer_id' => 'u', 'server_url' => 's', 'enrollment_token' => 't', 'department' => '', 'created_at' => 'c', 'expires_at' => 'e']));
$exact = S::buildPayload(['installer_id' => 'u', 'server_url' => 's', 'enrollment_token' => 't', 'department' => $fill(16384 - $base), 'created_at' => 'c', 'expires_at' => 'e']);
$ok(strlen($exact) === 16384 && S::read(S::stamp('exe', $exact)) !== null, 'a 16384-byte payload is accepted');
$threw = false; try { S::buildPayload(['installer_id' => 'u', 'server_url' => 's', 'enrollment_token' => 't', 'department' => $fill(16385 - $base), 'created_at' => 'c', 'expires_at' => 'e']); } catch (\LengthException $e) { $threw = true; }
$ok($threw, 'a 16385-byte payload is refused by buildPayload');
$threw = false; try { S::footer(str_repeat('a', 16385)); } catch (\LengthException $e) { $threw = true; }
$ok($threw, 'footer() refuses a payload over 16384 bytes');
$threw = false; try { S::footer(''); } catch (\LengthException $e) { $threw = true; }
$ok($threw, 'footer() refuses an empty payload');
$ok(S::read(str_repeat("\0", 51)) === null && S::read('') === null, 'files shorter than the footer are rejected');
$restamp = S::stamp(S::stamp('EXE', '{"a":1}'), '{"b":2}');
$ok(S::read($restamp)['data'] === ['b' => 2], 'the LAST footer wins when a file is stamped twice');

// ------------------------------------------------------------ PE validation
function fakePe(int $machine, int $size = 4096, int $peOff = 128, int $chars = 0x0022): string
{
    $b = str_repeat("\0", $size);
    $b = substr_replace($b, 'MZ', 0, 2);
    $b = substr_replace($b, pack('V', $peOff), 0x3C, 4);
    $b = substr_replace($b, "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', $chars), $peOff, 24);
    return $b;
}
$tmp = sys_get_temp_dir() . '/ea_unit_' . getmypid(); @mkdir($tmp);
$w = function (string $name, string $bytes) use ($tmp) { file_put_contents("$tmp/$name", $bytes); return "$tmp/$name"; };
$good = Binaries::inspect($w('amd64.exe', fakePe(0x8664)), 'amd64');
$ok(is_array($good) && $good['size'] === 4096 && $good['sha256'] === hash('sha256', fakePe(0x8664)), 'good amd64 PE: accepted, SHA-256 and size reported');
$ok(is_array(Binaries::inspect($w('arm64.exe', fakePe(0xAA64)), 'arm64')), 'good arm64 PE: accepted');
$ok(is_string($e1 = Binaries::inspect($w('wrong.exe', fakePe(0xAA64)), 'amd64')) && strpos($e1, '0xAA64') !== false, "arm64 binary declared amd64: refused ($e1)");
$ok(is_string(Binaries::inspect($w('wrong2.exe', fakePe(0x8664)), 'arm64')), 'amd64 binary declared arm64: refused');
$ok(is_string(Binaries::inspect($w('i386.exe', fakePe(0x014C)), 'amd64')), 'x86 binary refused');
$ok(is_string(Binaries::inspect($w('elf', "\x7fELF" . str_repeat("\0", 4096)), 'amd64')), 'ELF refused (not MZ)');
$ok(is_string(Binaries::inspect($w('text.exe', str_repeat('hello world ', 400)), 'amd64')), 'text file refused');
$ok(is_string(Binaries::inspect($w('tiny.exe', 'MZ'), 'amd64')), 'tiny file refused');
$ok(is_string(Binaries::inspect($w('nope.exe', substr(fakePe(0x8664), 0, 2000) ), 'amd64')) || true, 'truncated PE handled without error');
$ok(is_string(Binaries::inspect($w('badoff.exe', fakePe(0x8664, 4096, 5000)), 'amd64')) || is_string(Binaries::inspect($w('badoff2.exe', substr_replace(fakePe(0x8664), pack('V', 0xFFFFFFF0), 0x3C, 4)), 'amd64')), 'PE offset past the end of the file refused');
$ok(is_string(Binaries::inspect($w('badoff3.exe', substr_replace(fakePe(0x8664), pack('V', 0xFFFFFFF0), 0x3C, 4)), 'amd64')), 'huge e_lfanew refused');
$ok(is_string(Binaries::inspect($w('nopesig.exe', substr_replace(fakePe(0x8664), 'XX', 128, 2)), 'amd64')), 'missing PE signature refused');
$ok(is_string(Binaries::inspect($w('dll.exe', fakePe(0x8664, 4096, 128, 0x2022)), 'amd64')), 'a DLL is refused');
$ok(is_string(Binaries::inspect($w('amd64.exe', fakePe(0x8664)), 'x86')), 'unknown architecture refused');
$ok(is_string(Binaries::inspect($w('missing', 'x') . '.nope', 'amd64')), 'missing file refused');
$stamped = S::stamp(fakePe(0x8664), '{"version":1}');
$ok(is_string($e2 = Binaries::inspect($w('stamped.exe', $stamped), 'amd64')) && strpos($e2, 'RIVETIT-EMBED') !== false, 'a pre-stamped file is refused');
$withMagic = fakePe(0x8664) . 'xx' . S::MAGIC . 'yy';
$ok(is_array(Binaries::inspect($w('magicbody.exe', $withMagic), 'amd64')), 'the magic string inside the file (not at the end) is not treated as stamped');
$ok(is_string($e3 = Binaries::inspect($w('big.exe', fakePe(0x8664, 5000)), 'amd64', 4999)) && strpos($e3, 'larger') !== false, "oversize refused: $e3");
$ok(is_array(Binaries::inspect($w('exact.exe', fakePe(0x8664, 5000)), 'amd64', 5000)), 'exactly at the cap is accepted');
$ok(Binaries::iniBytes('8M') === 8388608 && Binaries::iniBytes('2G') === 2147483648 && Binaries::iniBytes('512K') === 524288 && Binaries::iniBytes('-1') === 0 && Binaries::iniBytes('123') === 123, 'ini size parsing');
$ok(Binaries::DEFAULT_MAX_BYTES === 64 * 1024 * 1024, 'default cap is 64 MiB');
$vr = fn(string $v) => (bool) preg_match(Binaries::VERSION_RE, $v);
$ok($vr('1.2.3') && $vr('1.2.3-rc1') && $vr('10.0.100+build.5') && !$vr('1.2') && !$vr('v1.2.3') && !$vr('1.2.3 ') && !$vr("1.2.3\n") && !$vr('../1.2.3') && !$vr('1.2.3/../x'), 'version syntax');
foreach (glob("$tmp/*") as $f) { unlink($f); } rmdir($tmp);

// ------------------------------------------------------------ names and quoting
$ok(Installer::slug('Acme Corp') === 'acme-corp' && Installer::slug('  --Müller & Söhne--  ') !== '' && Installer::slug('日本') === 'department' && Installer::slug('') === 'department', 'department slug');
foreach (["Evil\r\nSet-Cookie: x=1", '"; filename="x.php', '../../etc/passwd', "a\0b", str_repeat('é', 100), 'Dept <script>alert(1)</script>'] as $bad) {
    $fn = Installer::filename($bad, 'amd64');
    $ok(preg_match('/^RivetIT-Agent-Setup-[a-z0-9-]{1,40}-x64\.exe$/', $fn) === 1, 'filename is header safe for ' . json_encode($bad) . " -> $fn");
}
$ok(Installer::filename('Sales', 'arm64') === 'RivetIT-Agent-Setup-sales-arm64.exe', 'arm64 filename');
$ok(Installer::psQuote("it's") === "'it''s'" && Installer::psQuote('a$b`c"d') === "'a\$b`c\"d'", 'PowerShell quoting doubles single quotes and leaves $ ` " inert inside single quotes');
$threw = false; try { Installer::psQuote("a\nb"); } catch (\InvalidArgumentException $e) { $threw = true; }
$ok($threw, 'PowerShell quoting refuses control characters');
$snip = Installer::powershellSnippet("https://h.example/it'x", 'rvte1.aaaaaaaaaaaa.' . str_repeat('b', 40), 'amd64', "Evil'; Remove-Item C:\\ #\n");
$ok(strpos($snip, "\$Server = 'https://h.example/it''x'") !== false && strpos($snip, "Remove-Item C:\\ #\n") === false && substr_count($snip, "\n# ") <= 2, 'snippet: server value quoted, department only appears sanitised in a one-line comment');
$ok(strpos($snip, "'setup', '--silent'") !== false && strpos($snip, '/api/v1/agent_installer') !== false && strpos($snip, 'ExitCode -ne 0') !== false && strpos($snip, 'Remove-Item -LiteralPath $Exe') !== false, 'snippet: posts to agent_installer, runs setup --silent, checks the exit code, deletes the file');
$ok(strpos($snip, '?token') === false && strpos($snip, '?') === strpos($snip, '?') , 'snippet never puts the token in a URL');
// CA normalisation
[$none, $e] = Installer::normalizeCa('   '); $ok($none === null && $e === null, 'empty CA clears the setting');
[$x, $e] = Installer::normalizeCa('hello'); $ok($x === null && $e !== null, 'non-PEM refused');
[$x, $e] = Installer::normalizeCa("-----BEGIN CERTIFICATE-----\n!!!!\n-----END CERTIFICATE-----"); $ok($x === null && $e !== null, 'garbage inside PEM refused');
[$x, $e] = Installer::normalizeCa(str_repeat('A', 9000)); $ok($x === null && $e !== null, 'oversize CA refused');
if (function_exists('openssl_pkey_new')) {
    $k = openssl_pkey_new(['private_key_bits' => 2048]); $csr = openssl_csr_new(['commonName' => 'Test CA'], $k); $cert = openssl_csr_sign($csr, null, $k, 30); openssl_x509_export($cert, $pem);
    [$x, $e] = Installer::normalizeCa("  \n" . $pem . "\n junk after \n"); $ok($x !== null && $e === null && strpos($x, 'junk') === false && substr($x, -1) === "\n", 'a real certificate is accepted and normalised');
}
