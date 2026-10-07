<?php
/*
 * Deployment (hosted binaries, per-department installer, token-gated installer download, hosted self-update), REAL HTTP against php -S on a
 * scratch database. Same harness and rules as tests/endpoint_agent_lib.php. The API server runs with a 16 MiB PHP memory_limit so a
 * 30 MiB download only works if the file is streamed, not buffered.
 */
require __DIR__ . '/endpoint_agent_lib.php';
use ITFlow\EndpointAgent\Binaries; use ITFlow\EndpointAgent\Config; use ITFlow\EndpointAgent\Enrollment; use ITFlow\EndpointAgent\Installer;
use ITFlow\EndpointAgent\InstallerStamp as S; use ITFlow\EndpointAgent\Updates;

ea_reset();
$U = ea_seed_users();
$q("DELETE FROM endpoint_agent_binaries");
$binDir = Binaries::storageDir();
register_shutdown_function(function () use ($binDir, $db) { foreach ($db->query("SELECT storage_name FROM endpoint_agent_binaries") ?: [] as $r) { @unlink("$binDir/{$r['storage_name']}"); } });

function fakePe(int $machine, int $size = 8192, string $fill = 'a'): string
{
    $b = 'MZ' . str_repeat("\0", 0x3A) . pack('V', 128);
    $b = str_pad($b, 128, "\0");
    $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);
    $need = $size - strlen($b);
    return $b . substr(str_repeat($chunk, intdiv($need, 32) + 1), 0, $need);
}
function stamped_parts(string $body): ?array { return S::read($body); }

// servers
$sdir = sys_get_temp_dir() . '/ea_dsessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$apiSrv = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ['memory_limit=16M']);
$base = "http://127.0.0.1:{$apiSrv['port']}";      // lib's http() uses this global
$webSrv = ea_start_php($root . '/tests/mobile_api_router.php', [], ["session.save_path=$sdir", 'upload_max_filesize=64M', 'post_max_size=70M']);
$wb = "http://127.0.0.1:{$webSrv['port']}";
$smallSrv = ea_start_php($root . '/tests/mobile_api_router.php', [], ["session.save_path=$sdir", 'upload_max_filesize=1M', 'post_max_size=2M']);
$sb = "http://127.0.0.1:{$smallSrv['port']}";
$S_ = []; foreach ([1 => 'admin', 10 => 'tech', 12 => 'viewer', 14 => 'moduleonly', 15 => 'normo', 16 => 'deptb'] as $uid => $n) { $S_[$n] = ea_forge_session($sdir, $uid); }
$REF = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];

/** multipart/form POST (fields + files) with a forged session; returns [status, body, headers] */
function webm(string $baseUrl, string $path, string $sid, array $fields, array $files = [], array $headers = []): array
{
    $ch = curl_init($baseUrl . $path);
    foreach ($files as $k => $f) { $fields[$k] = new CURLFile($f[0], 'application/octet-stream', $f[1]); }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers]);
    $raw = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, substr((string) $raw, $hs), substr((string) $raw, 0, $hs)];
}
$tmp = sys_get_temp_dir() . '/ea_dep_' . bin2hex(random_bytes(4)); mkdir($tmp);
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) { @unlink($f); } @rmdir($tmp); });
$put = function (string $name, string $bytes) use ($tmp) { file_put_contents("$tmp/$name", $bytes); return "$tmp/$name"; };
$admin = fn(array $f, array $files = [], string $who = 'admin', ?string $b = null) => webm($b ?? $wb, '/admin/post.php', $S_[$who], $f + ['csrf_token' => 'csrftok1'], $files, $REF);
$page = fn(string $who = 'admin') => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_[$who]);
$hdr = function (string $headers, string $name): ?string { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', $headers, $m) ? $m[1] : null; };
$bins = fn() => (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries");
$toks = fn() => (int) $one("SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens");

Config::enable();
Config::set(['service_url' => $base]);
$exeAmd = fakePe(0x8664, 300000, 'amd'); $exeArm = fakePe(0xAA64, 200000, 'arm');

// ============================================================ upload: authorization matrix
foreach (['tech', 'viewer', 'moduleonly', 'normo', 'deptb'] as $who) {
    [$c] = $admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64', 'activate' => 1], ['agent_binary' => [$put('a.exe', $exeAmd), 'a.exe']], $who);
    $ok($bins() === 0, "upload denied to $who (no row created)");
    [$c, $b, $h] = $admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'amd64'], [], $who);
    $ok(strpos((string) $hdr($h, 'Content-Type'), 'octet-stream') === false && $toks() === 0, "installer download denied to $who (no token created)");
    [$c, $b] = $page($who); $ok($c === 403 && strpos($b, 'Download installer') === false, "admin page with the deployment UI denied to $who");
}
[$c, $b, $h] = webm($wb, '/admin/post.php', $S_['admin'], ['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64'], ['agent_binary' => [$put('a.exe', $exeAmd), 'a.exe']], $REF);
$ok($bins() === 0, 'upload without a CSRF token changes nothing');
[$c] = webm($wb, '/admin/post.php', $S_['admin'], ['upload_agent_binary' => 1, 'csrf_token' => 'wrong', 'version' => '1.0.0', 'arch' => 'amd64'], ['agent_binary' => [$put('a.exe', $exeAmd), 'a.exe']], $REF);
$ok($bins() === 0, 'upload with a wrong CSRF token changes nothing');
foreach ([['download_installer' => 1], ['show_deploy_commands' => 1], ['binary_action' => 'deactivate', 'binary_id' => 1], ['save_agent_settings' => 1, 'enabled' => 1, 'ca_pem' => 'x']] as $f) {
    $before = $toks();
    [$c, $b, $h] = webm($wb, '/admin/post.php', $S_['admin'], $f + ['client_id' => 1, 'arch' => 'amd64'], [], $REF);
    $ok($toks() === $before && strpos((string) $hdr($h, 'Content-Type'), 'octet-stream') === false, 'no CSRF token: ' . array_key_first($f) . ' does nothing');
}
$ok((string) $one("SELECT service_url FROM endpoint_agent_settings") === $base, 'settings unchanged by the CSRF-less save');

// ============================================================ upload: validation (admin)
$msg = function () use ($page) { [, $b] = $page(); return html_entity_decode(strip_tags($b)); };
[$c] = $admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64', 'activate' => 1], ['agent_binary' => [$put('a.exe', fakePe(0xAA64, 5000)), 'a.exe']]);
$ok($bins() === 0 && strpos($msg(), '0xAA64') !== false, 'arm64 file declared amd64 is refused with the machine type explained');
$admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64'], ['agent_binary' => [$put('t.exe', str_repeat('not an exe ', 500)), 't.exe']]);
$ok($bins() === 0 && strpos($msg(), 'not a Windows executable') !== false, 'a non-PE file is refused');
$admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64'], ['agent_binary' => [$put('s.exe', S::stamp($exeAmd, '{"version":1}')), 's.exe']]);
$ok($bins() === 0 && strpos($msg(), 'RIVETIT-EMBED') !== false, 'a pre-stamped installer is refused');
foreach (['v1.0', '1.0.0/../x', '', '1.0.0 x'] as $badv) {
    $admin(['upload_agent_binary' => 1, 'version' => $badv, 'arch' => 'amd64'], ['agent_binary' => [$put('a.exe', $exeAmd), 'a.exe']]);
    $ok($bins() === 0, 'bad version ' . json_encode($badv) . ' refused');
}
$admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'riscv'], ['agent_binary' => [$put('a.exe', $exeAmd), 'a.exe']]); $ok($bins() === 0, 'unknown architecture refused');
$admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64'], []); $ok($bins() === 0 && strpos($msg(), 'Choose the agent') !== false, 'no file chosen: clear message');
[$c, $b] = $admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64'], ['agent_binary' => [$put('big.exe', fakePe(0x8664, 1600000)), 'big.exe']], 'admin', $sb);
$ok($bins() === 0, 'upload above the PHP upload limit stores nothing');
[, $pb] = web($sb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$ok(strpos(html_entity_decode(strip_tags($pb)), 'Largest accepted upload: 1 MiB') !== false && strpos($pb, 'upload_max_filesize') !== false, 'the page states the effective upload limit (PHP limit lower than the 64 MiB cap)');
// good uploads
[$c] = $admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64', 'activate' => 1], ['agent_binary' => [$put('a.exe', $exeAmd), 'a.exe']]);
$r = $rows("SELECT * FROM endpoint_agent_binaries")[0] ?? [];
$ok($bins() === 1 && $r['arch'] === 'amd64' && $r['version'] === '1.0.0' && $r['sha256'] === hash('sha256', $exeAmd) && (int) $r['size_bytes'] === strlen($exeAmd) && (int) $r['is_current'] === 1 && (int) $r['active'] === 1, 'good amd64 upload: row with SHA-256, size, active, current');
$ok(preg_match('/^bin_[0-9a-f]{32}\.bin$/', $r['storage_name']) === 1 && file_get_contents("$binDir/{$r['storage_name']}") === $exeAmd && !file_exists("$binDir/a.exe"), 'stored under a random name with identical bytes');
$ok(is_file("$binDir/.htaccess") && strpos((string) file_get_contents("$binDir/.htaccess"), 'denied') !== false && is_file("$binDir/index.html"), 'storage directory carries deny and index files');
$ok(strpos(realpath($binDir), realpath("$root/backups")) === 0, 'storage directory is the denied backups/ area, not a served one');
$msgText = $msg(); $ok(strpos($msgText, hash('sha256', $exeAmd)) !== false, 'the stored SHA-256 is shown to the admin');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Binary Uploaded' AND log_user_id=1") === 1, 'upload is audited with the actor');
[$c] = $admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64', 'activate' => 1], ['agent_binary' => [$put('a2.exe', $exeAmd), 'a2.exe']]);
$ok($bins() === 1 && count(glob("$binDir/bin_*.bin")) === 1, 're-uploading the identical file is idempotent');
$admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'amd64'], ['agent_binary' => [$put('a3.exe', fakePe(0x8664, 9000, 'other')), 'a3.exe']]);
$ok($bins() === 1 && strpos($msg(), 'different contents') !== false, 'a different file under a published version is refused');
$admin(['upload_agent_binary' => 1, 'version' => '1.0.0', 'arch' => 'arm64', 'activate' => 1], ['agent_binary' => [$put('arm.exe', $exeArm), 'arm.exe']]);
$ok($bins() === 2 && (int) $one("SELECT is_current FROM endpoint_agent_binaries WHERE arch='arm64'") === 1 && (int) $one("SELECT is_current FROM endpoint_agent_binaries WHERE arch='amd64'") === 1, 'arm64 binary becomes current without touching amd64');
$admin(['upload_agent_binary' => 1, 'version' => '1.1.0', 'arch' => 'amd64', 'activate' => 1], ['agent_binary' => [$put('a11.exe', fakePe(0x8664, 250000, 'v11')), 'a11.exe']]);
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE arch='amd64' AND is_current=1") === 1 && $one("SELECT version FROM endpoint_agent_binaries WHERE arch='amd64' AND is_current=1") === '1.1.0', 'exactly one current amd64 binary (newest activation)');
$id100 = (int) $one("SELECT binary_id FROM endpoint_agent_binaries WHERE arch='amd64' AND version='1.0.0'");
$admin(['binary_action' => 'make_current', 'binary_id' => $id100]);
$ok($one("SELECT version FROM endpoint_agent_binaries WHERE arch='amd64' AND is_current=1") === '1.0.0', 'make current switches the installer binary back');
$admin(['binary_action' => 'deactivate', 'binary_id' => $id100]);
$rw = $rows("SELECT * FROM endpoint_agent_binaries WHERE binary_id=$id100")[0];
$ok((int) $rw['active'] === 0 && (int) $rw['is_current'] === 0 && is_file("$binDir/{$rw['storage_name']}"), 'delete = deactivate: row inactive, file kept');
$admin(['binary_action' => 'activate', 'binary_id' => $id100]);
$ok((int) $one("SELECT active FROM endpoint_agent_binaries WHERE binary_id=$id100") === 1, 'reactivate works');
$ok(strpos($page()[1], 'RivetITAgent.msi') === false && strpos(html_entity_decode($page()[1]), 'Agent binaries') !== false, 'the misleading RivetITAgent.msi hint is gone');

// CLI publisher
$cli = function (array $args) use ($root) { $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/scripts/endpoint_agent_publish.php") . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1'; exec($cmd, $out, $code); return [$code, implode("\n", $out)]; };
[$code, $out] = $cli([$put('cli.exe', fakePe(0x8664, 7000, 'cli')), '--version', '2.0.0', '--arch', 'amd64']);
$ok($code === 0 && strpos($out, 'Published 2.0.0 amd64') !== false && (int) $one("SELECT is_current FROM endpoint_agent_binaries WHERE version='2.0.0'") === 0, "CLI publishes without --activate and does not change the current binary ($out)");
[$code, $out] = $cli([$put('cli.exe', fakePe(0x8664, 7000, 'cli')), '--version', '2.0.0', '--arch', 'amd64', '--activate', '--release', 'pilot', '--rollout', '40']);
$rel = $rows("SELECT * FROM endpoint_agent_releases WHERE version='2.0.0'")[0] ?? [];
$ok($code === 0 && (int) $one("SELECT is_current FROM endpoint_agent_binaries WHERE version='2.0.0'") === 1 && $rel && $rel['ring'] === 'pilot' && (int) $rel['rollout_pct'] === 40 && $rel['arch'] === 'amd64' && $rel['binary_id'] !== null && $rel['url'] === "$base/api/v1/agent_update?arch=amd64&version=2.0.0" && $rel['sha256'] === hash('sha256', fakePe(0x8664, 7000, 'cli')), 'CLI --activate --release creates the hosted release row (server URL, SHA-256, ring, rollout)');
[$code, $out] = $cli([$put('bad.exe', 'nonsense'), '--version', '2.0.1', '--arch', 'amd64']); $ok($code === 1 && stripos($out, 'Error') !== false, 'CLI refuses a non-PE file with exit code 1');
[$code, $out] = $cli([$put('arm2.exe', fakePe(0xAA64, 7000)), '--version', '2.0.1', '--arch', 'amd64']); $ok($code === 1, 'CLI refuses the wrong machine type');
[$code, $out] = $cli([$put('x.exe', fakePe(0x8664, 7000, 'z')), '--version', 'abc', '--arch', 'amd64']); $ok($code === 1, 'CLI refuses a bad version');
[$code, $out] = $cli([]); $ok($code === 1 && strpos($out, 'Usage') !== false, 'CLI prints usage');
$q("DELETE FROM endpoint_agent_releases"); $admin(['binary_action' => 'make_current', 'binary_id' => (int) $one("SELECT binary_id FROM endpoint_agent_binaries WHERE version='1.1.0'")]);

// ============================================================ admin installer download
$cur = Binaries::current('amd64'); $curBytes = file_get_contents("$binDir/{$cur['storage_name']}");
$q("UPDATE logs SET log_id=log_id"); $q("DELETE FROM logs");
[$c, $body, $h] = $admin(['download_installer' => 1, 'client_id' => 1, 'location_id' => 0, 'ring' => 'pilot', 'ttl_hours' => 5, 'max_uses' => 7, 'label' => 'Front <b>desk</b>', 'arch' => 'amd64']);
$sp = S::read($body); $d = $sp['data'] ?? [];
$ok($c === 200 && strpos((string) $hdr($h, 'Content-Type'), 'application/octet-stream') === 0, 'authorized admin gets an octet-stream response');
$ok($hdr($h, 'Content-Disposition') === 'attachment; filename="RivetIT-Agent-Setup-dept-a-x64.exe"', 'filename is RivetIT-Agent-Setup-<department-slug>-<arch>.exe: ' . $hdr($h, 'Content-Disposition'));
$ok((int) $hdr($h, 'Content-Length') === strlen($body) && $hdr($h, 'X-Content-Type-Options') === 'nosniff' && strpos((string) $hdr($h, 'Cache-Control'), 'no-store') !== false, 'Content-Length exact, nosniff, no-store');
$ok($sp !== null && substr($body, 0, $sp['exe_length']) === $curBytes, 'the stamped file is the CURRENT binary byte for byte, plus the trailer');
$ok($d['version'] === 1 && $d['department'] === 'Dept A' && $d['server_url'] === $base && $d['ca_pem'] === null && preg_match('/^[0-9a-f-]{36}$/', $d['installer_id']) === 1 && preg_match('/^rvte1\.[0-9a-f]{12}\.[0-9a-f]{40}$/', $d['enrollment_token']) === 1, 'footer verifies; payload has the department, server URL, token, installer id, no CA');
$ok(abs(strtotime($d['expires_at']) - (time() + 5 * 3600)) < 30 && abs(strtotime($d['created_at']) - time()) < 30 && substr($d['expires_at'], -1) === 'Z', 'created_at/expires_at are RFC 3339 UTC and match the 5 h lifetime');
[, $sel, $sec] = explode('.', $d['enrollment_token']);
$trow = $rows("SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_selector='$sel'")[0] ?? null;
$ok($trow && hash_equals($trow['token_hash'], hash('sha256', $sec)) && (int) $trow['client_id'] === 1 && (int) $trow['location_id'] === 0 && $trow['ring'] === 'pilot' && (int) $trow['max_uses'] === 7 && $trow['label'] === 'Front <b>desk</b>' && (int) $trow['created_by'] === 1 && abs(strtotime($trow['expires_at'] . ' UTC') - strtotime($d['expires_at'])) <= 1 && (int) $trow['use_count'] === 0, 'token row: hash only, department scope, ring, max uses, label, creator, expiry');
$ok((string) $one("SELECT COUNT(*) FROM logs WHERE log_action='Installer Created' AND log_user_id=1 AND log_description LIKE '%{$d['installer_id']}%' AND log_description LIKE '%Dept A%' AND log_description LIKE '%#{$trow['token_id']}%'") === '1', 'audit row names the actor, installer id, department and token id');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%$sec%'") === 0, 'the token secret is in no log row');
// the minted token really enrolls
[$c, , $j] = ea_enroll($d['enrollment_token'], ea_dev()); $ok($c === 201 && isset($j['device_token']), 'the embedded token enrolls a device');
// default uses
$admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'amd64']);
$ok((int) $one("SELECT max_uses FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1") === 25 && strpos((string) $one("SELECT label FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1"), 'Installer ') === 0, 'default max uses is 25 and a label is generated');
// arm64
[$c, $body, $h] = $admin(['download_installer' => 1, 'client_id' => 2, 'arch' => 'arm64']); $sp = S::read($body);
$ok($c === 200 && strpos((string) $hdr($h, 'Content-Disposition'), 'RivetIT-Agent-Setup-dept-b-arm64.exe') !== false && $sp && $sp['data']['department'] === 'Dept B' && substr($body, 0, $sp['exe_length']) === $exeArm, 'ARM64 installer: arm64 binary, department B, arm64 file name');
// CA setting and its use
$k = openssl_pkey_new(['private_key_bits' => 2048]); $csr = openssl_csr_new(['commonName' => 'Test Rivet CA'], $k); $cert = openssl_csr_sign($csr, null, $k, 30); openssl_x509_export($cert, $pem);
$admin(['save_agent_settings' => 1, 'enabled' => 1, 'service_url' => $base, 'ca_pem' => 'not a cert']);
$ok((string) $one("SELECT ca_pem FROM endpoint_agent_settings") === '', 'an invalid CA certificate is refused by the settings form');
$admin(['save_agent_settings' => 1, 'enabled' => 1, 'service_url' => $base, 'ca_pem' => $pem]);
$ok(strpos((string) $one("SELECT ca_pem FROM endpoint_agent_settings"), 'BEGIN CERTIFICATE') !== false, 'a valid CA certificate is saved');
[$c, $body] = $admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'amd64']); $sp = S::read($body);
$ok($sp && is_string($sp['data']['ca_pem']) && openssl_x509_read($sp['data']['ca_pem']) !== false, 'the configured CA is embedded in the installer');
$admin(['save_agent_settings' => 1, 'enabled' => 1, 'service_url' => $base, 'ca_pem' => '']);
[$c, $body] = $admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'amd64']); $sp = S::read($body);
$ok($sp && $sp['data']['ca_pem'] === null, 'clearing the CA setting stops embedding it');
// hostile department name
$evil = "<img src=x onerror=alert(1)> \"q\"\r\nX-Injected: yes";
$q("UPDATE clients SET client_name='" . $esc($evil) . "' WHERE client_id=2");
[$c, $body, $h] = $admin(['download_installer' => 1, 'client_id' => 2, 'arch' => 'amd64']); $sp = S::read($body);
$ok($c === 200 && strpos($h, 'X-Injected') === false && preg_match('/filename="RivetIT-Agent-Setup-[a-z0-9-]+-x64\.exe"/', $h) === 1, 'a hostile department name cannot inject a header or escape the file name');
$ok($sp && strpos($sp['data']['department'], 'img src=x') !== false && strpos($sp['data']['department'], "\r") === false && strpos($sp['data']['department'], "\n") === false, 'payload department is the name with control characters flattened (JSON data, not markup)');
// commands panel
$toksBefore = $toks();
[$c] = $admin(['show_deploy_commands' => 1, 'client_id' => 2, 'arch' => 'amd64', 'max_uses' => 3, 'ttl_hours' => 2]);
$ok($toks() === $toksBefore + 1, 'Show deployment commands creates one token');
[$c, $pb] = $page(); $plain = html_entity_decode($pb);
$ok(strpos($pb, '<img src=x onerror') === false && strpos($pb, '&lt;img src=x') !== false, 'department name is escaped in the commands panel');
$tk = $rows("SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1")[0];
preg_match('/\$Token  = \'(rvte1\.' . $tk['token_selector'] . '\.[0-9a-f]{40})\'/', $plain, $mm);
$ok(!empty($mm[1]) && hash_equals($tk['token_hash'], hash('sha256', explode('.', $mm[1])[2])), 'the panel shows the new token inside the PowerShell snippet');
$ok(strpos($plain, "'setup', '--silent'") !== false && strpos($plain, '/api/v1/agent_installer') !== false && strpos($plain, 'RivetITAgent') !== false && strpos($plain, '%ProgramFiles%\\RivetIT\\Agent\\rivetit-agent.exe') !== false && strpos($plain, 'IntuneWinAppUtil') !== false && strpos($plain, 'endpoint_agent_publish.php') !== false, 'panel covers interactive, silent script, Intune/GPO detection and build/upload');
[, $pb2] = $page(); $ok(strpos($pb2, $tk['token_selector']) === strpos($pb2, $tk['token_selector']) && strpos(html_entity_decode($pb2), $mm[1] ?? 'zzz') === false, 'the token is shown once: a reload no longer contains it');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%" . explode('.', $mm[1])[2] . "%'") === 0, 'token secret not in the audit log');
$q("UPDATE clients SET client_name='Dept B' WHERE client_id=2");

// refusals: nothing may be created
$tn = $toks();
Config::set(['enabled' => 0]);
[$c, $body, $h] = $admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'amd64']); $ok($toks() === $tn && S::read($body) === null && strpos($msg(), 'switched off') !== false, 'feature disabled: clear refusal, no token');
Config::set(['enabled' => 1]);
Config::set(['service_url' => '']);
[$c, $body] = $admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'amd64']); $ok($toks() === $tn && S::read($body) === null && strpos($msg(), 'https') !== false, 'no service URL: refusal, no token');
foreach (['ftp://x.example', 'http://insecure.example.com', 'https://u:p@x.example', 'javascript:alert(1)'] as $badUrl) {
    Config::set(['service_url' => $badUrl]);
    [$c, $body] = $admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'amd64']); $ok($toks() === $tn && S::read($body) === null, "service_url $badUrl refused, no token");
}
Config::set(['service_url' => $base]);
$q("UPDATE endpoint_agent_binaries SET is_current=0 WHERE arch='arm64'");
[$c, $body] = $admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'arm64']); $ok($toks() === $tn && S::read($body) === null && strpos($msg(), 'No agent binary') !== false, 'no current binary for the architecture: refusal, no token');
$q("UPDATE endpoint_agent_binaries SET is_current=1 WHERE arch='arm64'");
[$c, $body] = $admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'sparc']); $ok($toks() === $tn && S::read($body) === null, 'unknown architecture refused');
[$c, $body] = $admin(['download_installer' => 1, 'client_id' => 999, 'arch' => 'amd64']); $ok($toks() === $tn && S::read($body) === null, 'unknown department refused');
// damaged stored file: refused, token revoked
$cur = Binaries::current('amd64'); $orig = file_get_contents("$binDir/{$cur['storage_name']}");
file_put_contents("$binDir/{$cur['storage_name']}", $orig . 'X');
[$c, $body] = $admin(['download_installer' => 1, 'client_id' => 1, 'arch' => 'amd64']);
$ok(S::read($body) === null && (int) $one("SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens WHERE revoked_at IS NULL AND token_id=" . (int) $one("SELECT MAX(token_id) FROM endpoint_agent_enrollment_tokens")) === 0, 'a damaged stored binary is never served and the token created for it is revoked');
file_put_contents("$binDir/{$cur['storage_name']}", $orig);

// ============================================================ POST /api/v1/agent_installer
$q("DELETE FROM endpoint_agent_enroll_attempts"); $q("DELETE FROM logs");
$tokA = ea_token(1, 24, 5); [, $selA, $secA] = explode('.', $tokA);
$tokB = Enrollment::createToken(2, 0, 'stable', 24, 5, 'b', 1)['token'];
$raw = function (string $method, string $path, $body = null, array $headers = [], bool $json = true) use (&$base) {
    $ch = curl_init($base . $path);
    $h = $headers;
    if ($body !== null) { if ($json) { $h[] = 'Content-Type: application/json'; $body = json_encode($body); } curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $h]);
    $out = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, substr((string) $out, $hs), substr((string) $out, 0, $hs)];
};
[$c, $body, $h] = $raw('POST', '/api/v1/agent_installer', ['token' => $tokA, 'arch' => 'amd64']); $sp = S::read($body);
$ok($c === 200 && $sp && $sp['data']['department'] === 'Dept A' && $sp['data']['enrollment_token'] === $tokA && substr($body, 0, $sp['exe_length']) === file_get_contents("$binDir/" . Binaries::current('amd64')['storage_name']), 'JSON POST returns the stamped exe for the token\'s department with that token embedded');
$ok(strpos((string) $hdr($h, 'Content-Type'), 'application/octet-stream') === 0 && (int) $hdr($h, 'Content-Length') === strlen($body) && $hdr($h, 'X-Content-Type-Options') === 'nosniff' && strpos((string) $hdr($h, 'Cache-Control'), 'no-store') !== false && strpos((string) $hdr($h, 'Content-Disposition'), 'RivetIT-Agent-Setup-dept-a-x64.exe') !== false, 'headers: octet-stream, exact Content-Length, nosniff, no-store, file name');
[$c, $body] = $raw('POST', '/api/v1/agent_installer', http_build_query(['token' => $tokB, 'arch' => 'arm64']), ['Content-Type: application/x-www-form-urlencoded'], false); $sp = S::read($body);
$ok($c === 200 && $sp && $sp['data']['department'] === 'Dept B' && substr($body, 0, $sp['exe_length']) === $exeArm, 'form POST works and the department comes from the token (Dept B, arm64 binary)');
[$c, $body] = $raw('POST', '/api/v1/agent_installer', '{"arch":"amd64"}', ['Authorization: Bearer ' . $tokA, 'Content-Type: application/json'], false); $ok($c === 200 && S::read($body) !== null, 'the token is also accepted as an Authorization: Bearer header');
[$c, $body] = $raw('POST', '/api/v1/agent_installer?token=' . urlencode($tokA), ['arch' => 'amd64']); $ok($c === 400 && S::read($body) === null && strpos($body, $secA) === false, 'a token in the query string is refused (400) and never used');
[$c, $body] = $raw('POST', '/api/v1/agent_installer?token=' . urlencode($tokA), ['token' => $tokA, 'arch' => 'amd64']); $ok($c === 400, 'a query token is refused even when the body also carries one');
[$c] = $raw('GET', '/api/v1/agent_installer'); $ok($c === 405, 'GET -> 405');
[$c] = $raw('GET', '/api/v1/agent_installer?token=' . urlencode($tokA) . '&arch=amd64'); $ok($c === 405 || $c === 400, 'GET with the token in the URL does not download');
// rejections: identical generic 404
$expired = ea_token(1, 24, 5); $q("UPDATE endpoint_agent_enrollment_tokens SET expires_at='2020-01-01 00:00:00' WHERE token_selector='" . explode('.', $expired)[1] . "'");
$revoked = ea_token(1, 24, 5); $q("UPDATE endpoint_agent_enrollment_tokens SET revoked_at=NOW() WHERE token_selector='" . explode('.', $revoked)[1] . "'");
$used = ea_token(1, 24, 2); $q("UPDATE endpoint_agent_enrollment_tokens SET use_count=2 WHERE token_selector='" . explode('.', $used)[1] . "'");
$wrongSecret = 'rvte1.' . $selA . '.' . str_repeat('0', 40);
$nearMiss = substr($tokA, 0, -1) . (substr($tokA, -1) === 'a' ? 'b' : 'a');
$unknown = 'rvte1.' . str_repeat('f', 12) . '.' . str_repeat('1', 40);
$bodies = [];
foreach (['expired' => $expired, 'revoked' => $revoked, 'used up' => $used, 'wrong secret' => $wrongSecret, 'near miss' => $nearMiss, 'unknown selector' => $unknown, 'malformed' => 'garbage', 'wrong prefix' => 'rvtx9.' . $selA . '.' . $secA] as $label => $t) {
    $q("DELETE FROM endpoint_agent_enroll_attempts");
    [$c, $b, $h] = $raw('POST', '/api/v1/agent_installer', ['token' => $t, 'arch' => 'amd64']);
    $bodies[$label] = [$c, $b];
    $ok($c === 404 && S::read($b) === null && $hdr($h, 'Content-Disposition') === null, "rejected token ($label): 404 and no file");
}
$ok(count(array_unique(array_map('json_encode', $bodies))) === 1, 'every rejection returns the identical generic body (no oracle)');
$q("DELETE FROM endpoint_agent_enroll_attempts");   // keep later cases clear of the failure budget
[$c] = $raw('POST', '/api/v1/agent_installer', ['token' => $tokA, 'arch' => 'sparc']); $ok($c === 422, 'unknown arch -> 422');
[$c] = $raw('POST', '/api/v1/agent_installer', ['token' => $tokA]); $ok($c === 422, 'missing arch -> 422');
[$c] = $raw('POST', '/api/v1/agent_installer', ['arch' => 'amd64']); $ok($c === 422, 'missing token -> 422');
[$c] = $raw('POST', '/api/v1/agent_installer', ['token' => ['a'], 'arch' => 'amd64']); $ok($c === 422, 'array token -> 422');
[$c] = $raw('POST', '/api/v1/agent_installer', 'not json', [], true === false); $ok($c === 422, 'garbage body -> 422');
[$c] = $raw('POST', '/api/v1/agent_installer', str_repeat('a', 5000), ['Content-Type: application/json'], false); $ok($c === 413, 'oversize body -> 413');
$q("UPDATE endpoint_agent_binaries SET is_current=0 WHERE arch='arm64'");
[$c, $b] = $raw('POST', '/api/v1/agent_installer', ['token' => $tokA, 'arch' => 'arm64']); $ok($c === 409 && S::read($b) === null, 'a valid token but no binary for that architecture -> 409, not a file');
$q("UPDATE endpoint_agent_binaries SET is_current=1 WHERE arch='arm64'");
Config::set(['enabled' => 0]); [$c] = $raw('POST', '/api/v1/agent_installer', ['token' => $tokA, 'arch' => 'amd64']); $ok($c === 403, 'feature disabled -> 403'); Config::set(['enabled' => 1]);
Config::set(['service_url' => 'http://not-loopback.example.com']); [$c] = $raw('POST', '/api/v1/agent_installer', ['token' => $tokA, 'arch' => 'amd64']); $ok($c === 409, 'non-https service URL -> no installer'); Config::set(['service_url' => $base]);
// audit and logs
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Installer Downloaded'") >= 3 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Installer Download Rejected'") >= 8, 'successes and rejected attempts are audited');
$allLogs = (string) $one("SELECT GROUP_CONCAT(log_description) FROM logs") . (string) $one("SELECT GROUP_CONCAT(CONCAT(ip_text, reason, token_selector)) FROM endpoint_agent_enroll_attempts");
$srvLog = (string) @file_get_contents($apiSrv['log']);
foreach ([$secA, explode('.', $expired)[2], explode('.', $revoked)[2], explode('.', $tokB)[2], str_repeat('0', 40)] as $secret) {
    $ok(strpos($allLogs, $secret) === false && strpos($srvLog, $secret) === false, 'token secret ' . substr($secret, 0, 6) . '... appears in no audit row, attempt row or PHP log');
}
// streaming a big file with a 16 MiB memory limit
$bigExe = fakePe(0xAA64, 30 * 1024 * 1024, 'big');
$ob = $put('bigarm.exe', $bigExe);
[$code, $out] = $cli([$ob, '--version', '3.0.0', '--arch', 'arm64', '--activate']);
$ok($code === 0, "30 MiB arm64 binary published ($out)");
$q("DELETE FROM endpoint_agent_enroll_attempts");
$ch = curl_init("$base/api/v1/agent_installer"); $bodyFile = "$tmp/dl.bin"; $fp = fopen($bodyFile, 'wb');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['token' => $tokB, 'arch' => 'arm64']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_FILE => $fp, CURLOPT_HEADER => false, CURLOPT_TIMEOUT => 120]);
curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $len = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD); $clen = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD); curl_close($ch); fclose($fp);
$ok($code === 200 && $len === filesize($bodyFile) && (int) $clen === $len, "30 MiB streamed under a 16 MiB PHP memory limit with exact Content-Length ($len bytes)");
$dl = file_get_contents($bodyFile); $sp = S::read($dl);
$ok($sp && $sp['exe_length'] === strlen($bigExe) && hash('sha256', substr($dl, 0, $sp['exe_length'])) === hash('sha256', $bigExe) && $sp['data']['department'] === 'Dept B', 'the 30 MiB body is the exact binary followed by a valid trailer');
unset($dl);
// rate limits (last: they poison this address's budget)
$q("DELETE FROM endpoint_agent_enroll_attempts");
$codes = []; for ($i = 0; $i < 12; $i++) { [$c, $b, $h] = $raw('POST', '/api/v1/agent_installer', ['token' => $unknown, 'arch' => 'amd64']); $codes[] = $c; }
$ok($codes[0] === 404 && end($codes) === 429 && $hdr($h, 'Retry-After') !== null, 'repeated failures from one address end in 429 with Retry-After: ' . implode(',', $codes));
[$c] = $raw('POST', '/api/v1/agent_installer', ['token' => $tokA, 'arch' => 'amd64']); $ok($c === 429, 'while rate limited even a valid token is refused (429)');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_enroll_attempts WHERE ip_hash='" . hash('sha256', 'ea-enroll|127.0.0.1') . "'") === 0, 'installer attempts do not consume the enrollment rate-limit bucket');
$q("DELETE FROM endpoint_agent_enroll_attempts");
[$c] = ea_enroll($tokA, ea_dev()); $ok($c === 201, 'enrollment still works for the same address afterwards');
$q("DELETE FROM endpoint_agent_enroll_attempts");
$tokR = ea_token(1, 24, 5); $selR = explode('.', $tokR)[1];
for ($i = 0; $i < 30; $i++) { $q("INSERT INTO endpoint_agent_enroll_attempts SET ip_hash='" . hash('sha256', "other$i") . "', ip_text='10.0.0.$i', success=1, reason='installer_download', token_selector='$selR', attempted_at=UTC_TIMESTAMP()"); }
[$c] = $raw('POST', '/api/v1/agent_installer', ['token' => $tokR, 'arch' => 'amd64']); $ok($c === 429, 'per-token budget: a token downloaded 30 times in the window is limited even from a fresh address');
[$c] = $raw('POST', '/api/v1/agent_installer', ['token' => $tokA, 'arch' => 'amd64']); $ok($c === 200, '...while another token is unaffected');
$q("DELETE FROM endpoint_agent_enroll_attempts");

// ============================================================ hosted self-update
$flush = function () { exec('redis-cli -p ' . (int) getenv('RIVETIT_REDIS_PORT') . ' flushall 2>&1'); };
$flush();
$q("DELETE FROM endpoint_agent_releases");
$tokE = ea_token(1, 24, 50);
[$c, , $je] = ea_enroll($tokE, ea_dev(['agent_version' => '1.0.0', 'arch' => 'amd64'])); $DEV = $je['device_token']; $DID = $je['device_id'];
[$c, , $ja] = ea_enroll($tokE, ea_dev(['agent_version' => '1.0.0', 'arch' => 'arm64'])); $DEVARM = $ja['device_token'];
$b110 = $rows("SELECT * FROM endpoint_agent_binaries WHERE version='1.1.0' AND arch='amd64'")[0]; $b110Bytes = file_get_contents("$binDir/{$b110['storage_name']}");
$none = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV); $ok($none[0] === 404, 'a binary that has no release is not downloadable by a device');
$ok(Binaries::publishRelease((int) $b110['binary_id'], 'stable', 100, 'n', 1) === null, 'publishing a release for the hosted binary');
[, , $ck] = ea_checkin($DEV);
$ok(($ck['update']['url'] ?? '') === "$base/api/v1/agent_update?arch=amd64&version=1.1.0" && $ck['update']['sha256'] === $b110['sha256'] && $ck['update']['version'] === '1.1.0' && isset($ck['update']['signature']), 'the manifest offers the server-hosted URL with the binary\'s SHA-256, signed');
[, , $cka] = ea_checkin($DEVARM); $ok(empty($cka['update']), 'an arm64 device is not offered the amd64-only hosted release');
[$c, $hd, , $body] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV);
$ok($c === 200 && $body === $b110Bytes && S::read($body) === null && hash('sha256', $body) === $ck['update']['sha256'], 'the offered release downloads: UNSTAMPED bytes whose SHA-256 equals the manifest');
$ok(strpos($hd['content-type'][0], 'application/octet-stream') === 0 && ($hd['x-content-type-options'][0] ?? '') === 'nosniff' && strpos($hd['cache-control'][0], 'no-store') !== false && (int) $hd['content-length'][0] === strlen($body), 'self-update headers: octet-stream, nosniff, no-store, Content-Length');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Agent Update Downloaded'") >= 1, 'self-update download is audited');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0'); $ok($c === 401, 'no credential -> 401');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', 'deadbeef'); $ok($c === 401, 'bad credential -> 401');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $U['admin']); $ok($c === 401, 'a user API token is not a device credential');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEVARM); $ok($c === 404, 'another device (arm64) cannot fetch the amd64 file');
[$c] = http('GET', '/api/v1/agent_update?arch=arm64&version=1.1.0', $DEV); $ok($c === 404, 'the amd64 device cannot fetch the arm64 file');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.0.0', $DEV); $ok($c === 404, 'an unoffered (current/older) version -> 404');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=2.0.0', $DEV); $ok($c === 404, 'a published but unoffered version -> 404');
foreach (['../../config.php', '1.1.0/../../x', 'config.php', '', '1.1.0%00'] as $bv) { [$c, , , $b] = http('GET', '/api/v1/agent_update?arch=amd64&version=' . rawurlencode($bv), $DEV); $ok(in_array($c, [404, 422], true) && strpos($b, '$database') === false, "path traversal attempt version=$bv refused ($c)"); }
foreach (['../x', 'amd64/../../config.php', 'x86'] as $ba) { [$c, , , $b] = http('GET', '/api/v1/agent_update?arch=' . rawurlencode($ba) . '&version=1.1.0', $DEV); $ok(in_array($c, [404, 422], true) && strpos($b, '$database') === false, "arch=$ba refused ($c)"); }
[$c] = http('GET', '/api/v1/agent_update?version=1.1.0', $DEV); $ok($c === 422, 'missing arch -> 422');
[$c] = http('POST', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV, []); $ok($c === 405, 'POST -> 405');
$q("UPDATE endpoint_agent_releases SET rollout_pct=0"); [$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV); $ok($c === 404, 'rollout 0% -> not offered, not downloadable');
$q("UPDATE endpoint_agent_releases SET rollout_pct=100, active=0"); [$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV); $ok($c === 404, 'inactive release -> 404');
$q("UPDATE endpoint_agent_releases SET active=1"); $q("UPDATE endpoint_agent_binaries SET active=0 WHERE binary_id=" . (int) $b110['binary_id']);
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV); $ok($c === 404, 'a deactivated binary is not served even if its release row is still active');
$q("UPDATE endpoint_agent_binaries SET active=1 WHERE binary_id=" . (int) $b110['binary_id']);
$q("UPDATE endpoint_agent_releases SET sha256='" . str_repeat('9', 64) . "'"); [$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV); $ok($c === 404, 'a release whose SHA-256 differs from the stored binary is refused');
$q("UPDATE endpoint_agent_releases SET sha256='{$b110['sha256']}'");
file_put_contents("$binDir/{$b110['storage_name']}", $b110Bytes . 'Z'); [$c, , , $b] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV); $ok($c === 503 && strlen($b) < 200, 'a tampered stored file is refused before any byte is sent');
file_put_contents("$binDir/{$b110['storage_name']}", $b110Bytes);
$q("UPDATE endpoint_agent_binaries SET storage_name='../../config.php' WHERE binary_id=" . (int) $b110['binary_id']); [$c, , , $b] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV);
$ok($c >= 400 && strpos($b, '$database') === false && strpos($b, 'password') === false, 'a corrupted storage_name can never read outside the binary directory');
$q("UPDATE endpoint_agent_binaries SET storage_name='{$b110['storage_name']}' WHERE binary_id=" . (int) $b110['binary_id']);
\ITFlow\EndpointAgent\Devices::revoke((int) $DID, 'test', 1); [$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.1.0', $DEV); $ok($c === 401, 'a revoked device cannot download');
// an external https release keeps working
$q("DELETE FROM endpoint_agent_releases");
Config::set(['service_url' => $base]);
$tokX = ea_token(1, 24, 5); [, , $jx] = ea_enroll($tokX, ea_dev(['agent_version' => '1.0.0', 'arch' => 'amd64']));
$ok(Updates::addRelease('1.5.0', 'https://127.0.0.1/downloads/agent.exe', str_repeat('a', 64), '0.0.0', 'stable', 100, '', 1) === null, 'external https release still publishes');
[, , $cx] = ea_checkin($jx['device_token']); $ok(($cx['update']['url'] ?? '') === 'https://127.0.0.1/downloads/agent.exe' && $cx['update']['version'] === '1.5.0', 'an external release keeps its own URL in the manifest');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=1.5.0', $jx['device_token']); $ok($c === 404, 'the hosted download endpoint does not serve an externally hosted release');
$q("DELETE FROM endpoint_agent_releases");
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_releases") === 0, 'cleanup');
