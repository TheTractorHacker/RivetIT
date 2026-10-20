<?php
/*
 * Hosted Linux agent files (RivetIT 26.10.64, DB 2.6.180): the validators (good and hostile), the store, the download credential, the device download route
 * (GET api/v1/agent_linux_file, over php -S), the build host's additive Linux files (good build, hostile builds, an old Windows-only host), the generated command
 * and that no secret reaches a log. Scratch database only (guard of tests/endpoint_agent_lib.php).
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=..scratch.. RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... EA_TEST_NON_WINDOWS=1 RMM_TEST_STATE_DIR=none RMM_GATE_STATE_DIR=/nonexistent \
 *     BH_REDIS_PORT=6741 php tests/endpoint_agent_linux_hosting.php
 * config.php must define RIVET_BUILD_HOST_DIR (a scratch folder); the web server needs a Redis for the rate limiter (BH_REDIS_PORT).
 */
putenv('RIVETIT_REDIS_PORT=' . (getenv('BH_REDIS_PORT') ?: '6379'));
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_build_host.php";   // also loads rmm_linux_store.php

if (!defined('RIVET_BUILD_HOST_DIR')) { fwrite(STDERR, "config.php must define RIVET_BUILD_HOST_DIR\n"); exit(2); }
ea_reset();
$U = ea_seed_users();
\ITFlow\EndpointAgent\Config::enable();
shell_exec('redis-cli -p ' . (int) (getenv('BH_REDIS_PORT') ?: 6379) . ' flushall 2>&1');
$lxWipe = function () use ($db, $q) { $d = rivetLxDir(); foreach ($db->query('SELECT storage_name FROM endpoint_agent_linux_files') ?: [] as $r) { if ($d) { @unlink($d . '/' . $r['storage_name']); } } $q('DELETE FROM endpoint_agent_linux_files'); };
foreach (['endpoint_agent_binaries', 'endpoint_agent_msi_templates', 'endpoint_agent_releases', 'endpoint_agent_linux_assets', 'endpoint_agent_build_chunks', 'endpoint_agent_build_files', 'endpoint_agent_build_jobs', 'endpoint_agent_build_hosts'] as $t) { $q("DELETE FROM $t"); }
$lxWipe();
$rmm = rivetRmmModule($db);
$binDir = $rmm->binaryStore()->storageDir();
register_shutdown_function(function () use ($binDir, $db, $lxWipe) { foreach ($db->query('SELECT storage_name FROM endpoint_agent_binaries UNION SELECT storage_name FROM endpoint_agent_msi_templates') ?: [] as $r) { @unlink("$binDir/{$r['storage_name']}"); } $lxWipe(); });
$EAB = ea_start_server(['RIVETIT_REDIS_PORT' => getenv('BH_REDIS_PORT') ?: '6379']);
$base = "http://127.0.0.1:{$EAB['port']}";
\ITFlow\EndpointAgent\Config::set(['service_url' => $base]);
exec('rm -rf ' . escapeshellarg(RIVET_BUILD_HOST_DIR));
$tmp = sys_get_temp_dir() . '/lxhost' . bin2hex(random_bytes(3)); mkdir($tmp);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

function lx_elf(int $machine, string $fill = 'A', int $type = 2, int $class = 2, int $data = 1, int $size = 6000): string
{
    return "\x7fELF" . chr($class) . chr($data) . "\x01" . str_repeat("\0", 9) . pack('v', $type) . pack('v', $machine) . pack('V', 1) . substr(str_repeat($fill, $size), 0, $size - 24) . 'main.version=0.9.9';
}
function lx_script(string $extra = ''): string { return "#!/usr/bin/env bash\n# install-linux.sh test double\nset -eu\n" . $extra . "echo \"ARGS: \$*\"\n"; }
function lx_file(string $bytes): string { global $tmp; $p = $tmp . '/' . bin2hex(random_bytes(5)); file_put_contents($p, $bytes); return $p; }
function lx_get(string $path, array $headers = [], string $method = 'GET'): array
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers, CURLOPT_NOBODY => $method === 'HEAD']);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $hd = []; foreach (explode("\r\n", substr((string) $raw, 0, $hs)) as $l) { if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $hd[strtolower(trim($k))] = trim($v); } }
    return [$code, $hd, substr((string) $raw, $hs)];
}

// ================================================================ 1. validators
echo "== validators\n";
$good = lx_elf(62); $goodArm = lx_elf(183);
$ok(rivetLxValidate(lx_file($good), 'rivetit-agent-linux-amd64') === null, 'a 64-bit little-endian x86-64 ELF is accepted as the amd64 agent');
$ok(rivetLxValidate(lx_file($goodArm), 'rivetit-agent-linux-arm64') === null, 'an aarch64 ELF is accepted as the arm64 agent');
$ok(rivetLxValidate(lx_file($goodArm), 'rivetit-agent-linux-amd64') !== null, 'an arm64 ELF named amd64 is refused (e_machine must match the name)');
$ok(rivetLxValidate(lx_file($good), 'rivetit-agent-linux-arm64') !== null, 'an amd64 ELF named arm64 is refused');
$ok(rivetLxValidate(lx_file(lx_elf(3)), 'rivetit-agent-linux-amd64') !== null, 'an i386 machine is refused');
$ok(rivetLxValidate(lx_file(lx_elf(62, 'A', 2, 1)), 'rivetit-agent-linux-amd64') !== null, 'a 32-bit ELF is refused');
$ok(rivetLxValidate(lx_file(lx_elf(62, 'A', 2, 2, 2)), 'rivetit-agent-linux-amd64') !== null, 'a big-endian ELF is refused');
$ok(rivetLxValidate(lx_file(lx_elf(62, 'A', 1)), 'rivetit-agent-linux-amd64') !== null, 'a relocatable object (ET_REL) is refused');
$ok(rivetLxValidate(lx_file(lx_elf(62, 'A', 4)), 'rivetit-agent-linux-amd64') !== null, 'a core dump (ET_CORE) is refused');
$ok(rivetLxValidate(lx_file(lx_elf(62, 'A', 3)), 'rivetit-agent-linux-amd64') === null, 'a PIE (ET_DYN) executable is accepted');
$ok(rivetLxValidate(lx_file(fx_pe_lx()), 'rivetit-agent-linux-amd64') !== null, 'a Windows PE named as the Linux agent is refused');
$ok(rivetLxValidate(lx_file("#!/bin/sh\necho pwned\n"), 'rivetit-agent-linux-amd64') !== null, 'a shell script named as the Linux agent is refused');
$ok(rivetLxValidate(lx_file(substr($good, 0, 100)), 'rivetit-agent-linux-amd64') !== null && rivetLxValidate(lx_file(''), 'rivetit-agent-linux-amd64') !== null, 'a truncated ELF and an empty file are refused');
$ok(rivetLxValidate(lx_file(lx_elf(62, 'A', 2, 2, 1, 70 * 1048576)), 'rivetit-agent-linux-amd64') !== null, 'an ELF over the size cap is refused');
$ok(rivetLxValidate(lx_file(lx_script()), 'install-linux.sh') === null, 'a small shell script that starts with a shebang is accepted');
$ok(rivetLxValidate(lx_file("#!/bin/sh\n" . str_repeat("# x\n", 100)), 'install-linux.sh') === null && rivetLxValidate(lx_file("#!/usr/bin/bash\necho\n"), 'install-linux.sh') === null, 'sh and bash shebangs both work');
$ok(rivetLxValidate(lx_file("echo hi\n"), 'install-linux.sh') !== null, 'a script without a shebang is refused');
$ok(rivetLxValidate(lx_file("#!/usr/bin/python3\nprint(1)\n"), 'install-linux.sh') !== null, 'a script whose interpreter is not a shell is refused');
$ok(rivetLxValidate(lx_file("#!/bin/sh\n" . "\0\0\0 binary"), 'install-linux.sh') !== null, 'a "script" with NUL bytes is refused');
$ok(rivetLxValidate(lx_file("#!/bin/sh\n\x01\x02 control"), 'install-linux.sh') !== null, 'a "script" with control characters is refused');
$ok(rivetLxValidate(lx_file("#!/bin/sh\n\xff\xfe\xfd not utf8\n"), 'install-linux.sh') !== null, 'a "script" that is not valid UTF-8 is refused');
$ok(rivetLxValidate(lx_file($good), 'install-linux.sh') !== null, 'an ELF named install-linux.sh is refused');
$ok(rivetLxValidate(lx_file("#!/bin/sh\n" . str_repeat('# padding ', 40000)), 'install-linux.sh') !== null, 'a script over 256 KiB is refused');
$ok(rivetLxValidate(lx_file("\x1f\x8b\x08\0" . str_repeat('x', 100)), 'rivetit-agent-linux-amd64.tar.gz') === null && rivetLxValidate(lx_file('not gzip data here'), 'rivetit-agent-linux-amd64.tar.gz') !== null, 'a tarball must be a gzip stream');
foreach (['../etc/passwd', 'rivetit-agent-linux-amd64/../x', 'rivetit-agent-linux-riscv64', 'install-linux.sh.bak', 'INSTALL-LINUX.SH', "install-linux.sh\0", 'rivetit-agent-windows-amd64.exe', '', 'rivetit-agent-linux-amd64.tar.gz.exe', "rivetit-agent-linux-amd64\n"] as $n) {
    $ok(rivetLxParseName($n) === null && rivetLxValidate(lx_file(lx_script()), $n) !== null, 'name refused: ' . json_encode($n));
}
$lnk = $tmp . '/link'; symlink(lx_file(lx_script()), $lnk);
$ok(rivetLxValidate($lnk, 'install-linux.sh') !== null, 'a symbolic link is not read');
function fx_pe_lx(): string { $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0"); return $b . "PE\0\0" . pack('v', 0x8664) . str_repeat("\0", 5000); }

// ================================================================ 2. the store
echo "== store\n";
$dir = rivetLxDir();
$ok($dir !== null && is_dir($dir) && !str_starts_with(realpath($dir), realpath($root . '/assets')) && !str_contains($dir, '/uploads/') && is_file("$dir/.htaccess") && str_contains(file_get_contents("$dir/.htaccess"), 'Require all denied'), 'the folder is under the agent binary directory (backups/, denied), with a deny file');
$ok(str_starts_with($dir, rtrim($binDir, '/') . '/'), 'the Linux folder sits inside the agent binary directory');
$r1 = rivetLxStore($db, lx_file(lx_script()), 'install-linux.sh', '0.9.9', 1, ['activate' => false, 'source' => 'upload']);
$ok($r1['ok'] && $r1['current'] && !$r1['replay'], 'the first file of a name becomes current by itself');
$row = $rows('SELECT * FROM endpoint_agent_linux_files')[0];
$ok(preg_match('/^[0-9a-f]{32}$/', $row['storage_name']) === 1 && $row['sha256'] === hash('sha256', lx_script()) && (int) $row['size_bytes'] === strlen(lx_script()) && $row['uploaded_by'] == 1 && $row['kind'] === 'script' && $row['arch'] === '', 'random storage name, sha256, size, uploader, kind recorded');
$p = rivetLxPath($row);
$ok(is_file($p) && file_get_contents($p) === lx_script() && (fileperms($p) & 0007) === 0, 'the bytes are stored and not world readable');
$ok(rivetLxPath(['storage_name' => '../../etc/passwd']) === null && rivetLxPath(['storage_name' => str_repeat('g', 32)]) === null && rivetLxPath(['storage_name' => $row['storage_name'] . '/..']) === null, 'a storage name that is not 32 hex characters never becomes a path');
$r1b = rivetLxStore($db, lx_file(lx_script()), 'install-linux.sh', '0.9.9', 1);
$ok($r1b['ok'] && $r1b['replay'] && cnt1('SELECT COUNT(*) FROM endpoint_agent_linux_files') === 1, 'the same bytes again are a no-op');
$r1c = rivetLxStore($db, lx_file(lx_script('echo changed' . "\n")), 'install-linux.sh', '0.9.9', 1);
$ok(!$r1c['ok'] && str_contains($r1c['error'], 'different contents'), 'different bytes under the same version are refused');
$r2 = rivetLxStore($db, lx_file(lx_script("# v2\n")), 'install-linux.sh', '1.0.0', 1, ['activate' => false]);
$ok($r2['ok'] && !$r2['current'] && rivetLxCurrent($db, 'install-linux.sh')['version'] === '0.9.9', 'a later version is not current unless asked');
$r3 = rivetLxStore($db, lx_file(lx_script("# v2\n")), 'install-linux.sh', '1.0.0', 1, ['activate' => true]);
$ok($r3['ok'] && rivetLxCurrent($db, 'install-linux.sh')['version'] === '1.0.0' && cnt1('SELECT COUNT(*) FROM endpoint_agent_linux_files WHERE name = \'install-linux.sh\' AND is_current = 1') === 1, 'activating makes exactly one file current');
$ok(rivetLxDelete($db, (int) $r3['file_id']) !== null, 'the current file cannot be deleted');
$ok(rivetLxDelete($db, (int) $r1['file_id']) === null && !is_file($p) && cnt1('SELECT COUNT(*) FROM endpoint_agent_linux_files') === 1, 'a non-current file can be deleted, record and bytes');
foreach ([['1.0', 'x'], ['v1.0.0', 'x'], ["1.0.0'; DROP TABLE x", 'x'], ['../1.0.0', 'x']] as [$v]) {
    $ok(!rivetLxStore($db, lx_file(lx_script()), 'install-linux.sh', $v, 1)['ok'], 'bad version refused: ' . json_encode($v));
}
$ok(!rivetLxStore($db, lx_file($good), 'install-linux.sh', '2.0.0', 1)['ok'] && !rivetLxStore($db, lx_file(lx_script()), 'rivetit-agent-linux-amd64', '2.0.0', 1)['ok'], 'the store validates by name: an ELF as the script and a script as the agent are refused');
$ok(!rivetLxStore($db, lx_file(lx_script()), 'install-linux.sh', '2.0.0', 1, ['expected_sha256' => str_repeat('0', 64)])['ok'], 'an expected SHA-256 that does not match refuses the file');
$a1 = rivetLxStore($db, lx_file(lx_elf(62, 'A')), 'rivetit-agent-linux-amd64', '1.0.0', 1, ['activate' => true, 'ring' => 'pilot', 'pct' => 25]);
$ok($a1['ok'] && $rows('SELECT release_ring, rollout_pct FROM endpoint_agent_linux_files WHERE name = \'rivetit-agent-linux-amd64\'')[0] == ['release_ring' => 'pilot', 'rollout_pct' => 25], 'ring and rollout are recorded');

// ================================================================ 3. the download credential
echo "== credential\n";
$tok = ea_token(1, 24, 1);   // rvte1.<selector>.<secret>
[, $sel, $secret] = explode('.', $tok);
$trow = $rows("SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_selector = '$sel'")[0];
$files = rivetLxCommandFiles($db, 'amd64');
$ok($files !== null && $files['version'] === '1.0.0', 'the command files are the current script and the current amd64 agent');
$cr = rivetLxCredentialsForToken($db, $tok, $files);
$ok(is_array($cr) && preg_match(RIVET_LX_CRED_RE, $cr['script']) === 1 && preg_match(RIVET_LX_CRED_RE, $cr['binary']) === 1 && $cr['script'] !== $cr['binary'], 'a credential per file, well formed');
$ok(!str_contains($cr['script'], $secret) && !str_contains($cr['binary'], $secret) && !str_contains($cr['script'], $trow['token_hash']), 'a credential contains neither the token secret nor the stored token hash');
$ok($cr['exp'] <= time() + RIVET_LX_CRED_CAP_S && $cr['exp'] > time(), 'the credential is short lived (capped at 24 hours)');
$v = fn (string $c, string $n, string $ver) => rivetLxVerifyCredential($db, $c, $n, $ver);
$ok($v($cr['script'], 'install-linux.sh', '1.0.0')['ok'], 'the credential verifies for its own file and version');
$ok(!$v($cr['script'], 'rivetit-agent-linux-amd64', '1.0.0')['ok'], 'it does not work for another file');
$ok(!$v($cr['script'], 'install-linux.sh', '1.0.1')['ok'], 'it does not work for another version');
$ok(!$v(substr($cr['script'], 0, -1) . (substr($cr['script'], -1) === '0' ? '1' : '0'), 'install-linux.sh', '1.0.0')['ok'], 'a changed MAC fails');
$bits = explode('.', $cr['script']); $bits[2] = (string) ($bits[2] + 1000);
$ok(!$v(implode('.', $bits), 'install-linux.sh', '1.0.0')['ok'], 'a changed expiry fails (the expiry is in the MAC)');
$ok(!$v('rvdl1.' . str_repeat('a', 12) . '.' . (time() + 99) . '.' . str_repeat('0', 64), 'install-linux.sh', '1.0.0')['ok'] && !$v('', 'install-linux.sh', '1.0.0')['ok'] && !$v('Bearer x', 'install-linux.sh', '1.0.0')['ok'] && !$v($tok, 'install-linux.sh', '1.0.0')['ok'], 'unknown selector, empty, junk and the raw enrollment token are all refused');
$other = ea_token(1, 24, 1);
$cr2 = rivetLxCredentialsForToken($db, $other, $files);
$swap = explode('.', $cr['script']); $swap[1] = explode('.', $other)[1];
$ok(!$v(implode('.', $swap), 'install-linux.sh', '1.0.0')['ok'], 'a credential moved to another token fails');
$short = ea_token(1, 1, 1); $shortSel = explode('.', $short)[1];
$crShort = rivetLxCredentialsForToken($db, $short, $files);
$ok(is_array($crShort) && $crShort['exp'] <= time() + 3600 + 5, 'a one hour token gives a credential that ends with the token');
$q("UPDATE endpoint_agent_enrollment_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE token_selector = '$shortSel'");
$ok($v($crShort['script'], 'install-linux.sh', '1.0.0')['reason'] === 'expired' && is_string(rivetLxCredentialsForToken($db, $short, $files)), 'the token expires: the credential stops working and no new one is made');
$rev = ea_token(1, 24, 1); $revSel = explode('.', $rev)[1]; $crRev = rivetLxCredentialsForToken($db, $rev, $files);
$q("UPDATE endpoint_agent_enrollment_tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_selector = '$revSel'");
$ok($v($crRev['script'], 'install-linux.sh', '1.0.0')['reason'] === 'revoked' && is_string(rivetLxCredentialsForToken($db, $rev, $files)), 'the token is revoked: the credential stops working and no new one is made');
$ok(is_string(rivetLxCredentialsForToken($db, 'rvte1.' . str_repeat('a', 12) . '.' . str_repeat('b', 40), $files)) && is_string(rivetLxCredentialsForToken($db, 'junk', $files)), 'a token that does not exist or is not well formed gives no credential');

// ================================================================ 4. the device route
echo "== route\n";
$u = fn (string $n, string $ver = '1.0.0') => '/api/v1/agent_linux_file?name=' . rawurlencode($n) . '&version=' . rawurlencode($ver);
$uses0 = (int) $one("SELECT used_count FROM endpoint_agent_enrollment_tokens WHERE token_selector = '$sel'");
[$c, $h, $body] = lx_get($u('install-linux.sh'), ['X-Install-Key: ' . $cr['script']]);
$ok($c === 200 && $body === lx_script("# v2\n") && hash('sha256', $body) === $h['x-content-sha256'] && (int) $h['content-length'] === strlen($body), 'GET with a valid key: 200, the exact bytes, X-Content-SHA256 and Content-Length');
$ok(str_starts_with($h['content-type'], 'application/octet-stream') && ($h['x-content-type-options'] ?? '') === 'nosniff' && str_contains($h['cache-control'] ?? '', 'no-store') && str_contains($h['content-disposition'] ?? '', 'install-linux.sh'), 'octet-stream, nosniff, no-store, attachment (never served as HTML)');
[$c, $h, $body] = lx_get($u('rivetit-agent-linux-amd64'), ['Authorization: Bearer ' . $cr['binary']]);
$ok($c === 200 && $body === lx_elf(62, 'A') && $h['etag'] === '"' . hash('sha256', $body) . '"', 'the Authorization: Bearer form works for the agent binary');
[$c, $h, $body] = lx_get($u('rivetit-agent-linux-amd64'), ['X-Install-Key: ' . $cr['binary']], 'HEAD');
$ok($c === 200 && $body === '' && (int) $h['content-length'] === strlen(lx_elf(62, 'A')), 'HEAD answers the headers without a body');
$uses1 = (int) $one("SELECT used_count FROM endpoint_agent_enrollment_tokens WHERE token_selector = '$sel'");
$ok($uses0 === $uses1 && $uses1 === 0, 'downloads do not consume an enrollment use');
[$c, , $b] = lx_get($u('install-linux.sh')); $ok($c === 401 && str_contains($b, 'invalid_key'), 'no key: 401');
[$c, , $b2] = lx_get($u('install-linux.sh'), ['X-Install-Key: ' . substr($cr['script'], 0, -2) . 'ff']); $ok($c === 401 && $b === $b2, 'a wrong key: the same 401 answer');
[$c] = lx_get($u('install-linux.sh'), ['X-Install-Key: ' . $tok]); $ok($c === 401, 'the enrollment token itself is not accepted as a key');
[$c] = lx_get('/api/v1/agent_linux_file?name=install-linux.sh&version=1.0.0&key=' . urlencode($cr['script'])); $ok($c === 401, 'a key in the query string is not read');
[$c] = lx_get($u('rivetit-agent-linux-amd64'), ['X-Install-Key: ' . $cr['script']]); $ok($c === 401, 'a key for one file does not fetch another');
[$c] = lx_get($u('install-linux.sh', '0.9.9'), ['X-Install-Key: ' . $cr['script']]); $ok($c === 401, 'a key for one version does not fetch another');
$crOld = rivetLxCredentialsForToken($db, $tok, ['script' => ['name' => 'install-linux.sh', 'version' => '7.7.7'], 'binary' => ['name' => 'rivetit-agent-linux-amd64', 'version' => '7.7.7']]);
[$c, , $b] = lx_get($u('install-linux.sh', '7.7.7'), ['X-Install-Key: ' . $crOld['script']]); $ok($c === 404 && str_contains($b, 'not_found'), 'a good key for a version that is not hosted: 404');
foreach (['../../config.php', 'install-linux.sh/../../config.php', '..%2f..%2fconfig.php', "install-linux.sh%00.php", 'INSTALL-LINUX.SH', 'rivetit-agent-windows-amd64.exe', ''] as $n) {
    [$c, , $b] = lx_get('/api/v1/agent_linux_file?name=' . $n . '&version=1.0.0', ['X-Install-Key: ' . $cr['script']]);
    $ok($c === 401 && !str_contains($b, '<?php'), 'hostile name refused without touching the disk: ' . $n);
}
[$c, $h] = lx_get($u('install-linux.sh'), ['X-Install-Key: ' . $cr['script']], 'POST'); $ok($c === 405 && ($h['allow'] ?? '') !== '', 'POST is refused (405)');
$ok(cnt1("SELECT COUNT(*) FROM logs WHERE log_action = 'Linux File Downloaded'") >= 3 && cnt1("SELECT COUNT(*) FROM logs WHERE log_action = 'Linux File Download Refused'") >= 3, 'downloads and refusals are audited');
// revoked and expired tokens at the route
[$c] = lx_get($u('install-linux.sh'), ['X-Install-Key: ' . $crRev['script']]); $ok($c === 401, 'revoked token: 401 at the route');
[$c] = lx_get($u('install-linux.sh'), ['X-Install-Key: ' . $crShort['script']]); $ok($c === 401, 'expired token: 401 at the route');
$ok(cnt1("SELECT COUNT(*) FROM logs WHERE log_action = 'Linux File Download Refused' AND log_description LIKE '%(revoked)%'") === 1 && cnt1("SELECT COUNT(*) FROM logs WHERE log_action = 'Linux File Download Refused' AND log_description LIKE '%(expired)%'") === 1, 'the audit row says why (revoked, expired)');
// the file on disk is damaged -> not served
$fr = $rows("SELECT * FROM endpoint_agent_linux_files WHERE name = 'install-linux.sh' AND version = '1.0.0'")[0]; $fp = rivetLxPath($fr);
file_put_contents($fp, file_get_contents($fp) . 'X');
[$c, , $b] = lx_get($u('install-linux.sh'), ['X-Install-Key: ' . $cr['script']]); $ok($c === 500 && str_contains($b, 'damaged') && !str_contains($b, '# v2'), 'a hosted file that no longer matches its SHA-256 is not served');
file_put_contents($fp, lx_script("# v2\n"));
// an inactive record is not served
$q("UPDATE endpoint_agent_linux_files SET active = 0 WHERE file_id = " . (int) $fr['file_id']);
[$c] = lx_get($u('install-linux.sh'), ['X-Install-Key: ' . $cr['script']]); $ok($c === 404, 'a deactivated file is not served');
$q("UPDATE endpoint_agent_linux_files SET active = 1 WHERE file_id = " . (int) $fr['file_id']);
// the files are not reachable from the web root
foreach (['/backups/endpoint-agent/linux/' . $fr['storage_name'], '/backups/endpoint-agent/linux/.htaccess'] as $path) { [$c] = lx_get($path); $ok($c !== 200, 'not served as a static file: ' . $path . " ($c)"); }
// rate limits: failures per address
shell_exec('redis-cli -p ' . (int) (getenv('BH_REDIS_PORT') ?: 6379) . ' flushall 2>&1');
$codes = []; for ($i = 0; $i < 14; $i++) { [$c] = lx_get($u('install-linux.sh'), ['X-Install-Key: rvdl1.' . str_repeat('a', 12) . '.' . (time() + 50) . '.' . str_repeat('0', 64)]); $codes[] = $c; }
$ok(in_array(429, $codes, true) && $codes[0] === 401, 'repeated bad keys are rate limited (429 after the limit): ' . implode(',', array_unique($codes)));
shell_exec('redis-cli -p ' . (int) (getenv('BH_REDIS_PORT') ?: 6379) . ' flushall 2>&1');
$codes = []; for ($i = 0; $i < 34; $i++) { [$c] = lx_get($u('install-linux.sh'), ['X-Install-Key: ' . $cr['script']]); $codes[] = $c; }
$ok($codes[0] === 200 && in_array(429, $codes, true), 'one key cannot be used without limit (429 after 30 downloads in the window)');
shell_exec('redis-cli -p ' . (int) (getenv('BH_REDIS_PORT') ?: 6379) . ' flushall 2>&1');

// ================================================================ 5. the command
echo "== command\n";
$tok2 = ea_token(1, 24, 3);
$core = \RivetCore\Rmm\Installer\InstallerService::linuxSnippet($base, $tok2, 'amd64', "Dept 'A'", null, null);
$none = rivetLxCommand($db, 'junk', 'amd64');
$ok($none === null, 'a snippet of an unexpected shape gives no command');
$cmd = rivetLxCommand($db, $core, 'amd64');
$ok($cmd !== null && $cmd['version'] === '1.0.0', 'the command is built from the hosted files');
$C = $cmd['command'];
$ok(str_contains($C, "set -eu\numask 077\n") && substr_count($C, 'sha256sum -c') === 1 && substr_count($C, "\nfetch ") === 2 && str_contains($C, 'bash "$S" --binary "$B" --server'), 'set -eu, umask 077, both files fetched and verified, then install-linux.sh via bash with --binary');
$ok(str_contains($C, hash('sha256', lx_script("# v2\n"))) && str_contains($C, hash('sha256', lx_elf(62, 'A'))), 'the SHA-256 values are printed in the command (pinned now)');
$ok(!preg_match('/\|\s*(ba)?sh\b/', $C) && !str_contains($C, 'curl -s') && !str_contains($C, 'github.com'), 'no pipe into a shell, nothing from GitHub');
$ok(substr_count($C, $tok2) === 1 && preg_match("/printf '%s' '" . preg_quote($tok2, '/') . "' > \"\\\$T\"/", $C) === 1, 'the enrollment token appears once, only in the printf that writes the temp file');
preg_match_all('/rvdl1\.[0-9a-f.]+/', $C, $kk);
$ok(count($kk[0]) === 2 && !str_contains($C, "curl -H") && !str_contains($C, '--header') && str_contains($C, '-K "$H"') && str_contains($C, 'WGETRC="$W"'), 'the install keys go to curl and wget through config files, not onto a command line');
$ok(str_contains($C, "trap 'rm -f \"\$T\" \"\$S\" \"\$B\" \"\$H\" \"\$W\"' EXIT"), 'all temporary files, including the key files, are removed on exit');
$ok(str_contains($C, "'$base/api/v1/agent_linux_file?name=install-linux.sh&version=1.0.0'") && str_contains($C, '/api/v1/agent_linux_file?name=rivetit-agent-linux-amd64&version=1.0.0'), 'both URLs point at this server, with the pinned version');
$ok(!str_contains($C, 'unpacked'), 'the "unpack the tarball first" text is gone');
$sh = $tmp . '/cmd.sh'; file_put_contents($sh, $C); exec('bash -n ' . escapeshellarg($sh) . ' 2>&1', $o, $rc);
$ok($rc === 0, 'the command is valid shell (bash -n)');
// private CA and Mesh keep working; the CA is used for the download from THIS server
$ca = "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n";
$tok3 = ea_token(1, 24, 3);
$cmdCa = rivetLxCommand($db, \RivetCore\Rmm\Installer\InstallerService::linuxSnippet($base, $tok3, 'arm64', 'D', $ca, ['url' => 'https://mesh.example.test', 'meshid' => 'ABC', 'pin' => '']), 'arm64');
$ok($cmdCa === null, 'arm64 has no hosted agent yet: no command (the page says to build or publish)');
rivetLxStore($db, lx_file(lx_elf(183, 'B')), 'rivetit-agent-linux-arm64', '1.0.0', 1, ['activate' => true]);
$cmdCa = rivetLxCommand($db, \RivetCore\Rmm\Installer\InstallerService::linuxSnippet($base, $tok3, 'arm64', 'D', $ca, ['url' => 'https://mesh.example.test', 'meshid' => 'ABC', 'pin' => '']), 'arm64');
$ok($cmdCa !== null && str_contains($cmdCa['command'], '--cacert "$C"') && str_contains($cmdCa['command'], '--ca-certificate="$C"') && str_contains($cmdCa['command'], '--ca "$C"') && str_contains($cmdCa['command'], '--install-mesh-agent') && str_contains($cmdCa['command'], hash('sha256', lx_elf(183, 'B'))), 'with a private CA and the Mesh agent: the CA verifies the download, install-linux.sh still gets --ca and the Mesh options');
$ok(rivetLxCommand($db, str_replace('rvte1.', 'rvte1.0', $core), 'amd64') === null, 'a token that does not exist gives no command');
$q("UPDATE endpoint_agent_enrollment_tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_selector = '" . explode('.', $tok2)[1] . "'");
$ok(rivetLxCommand($db, $core, 'amd64') === null, 'a revoked token gives no command');

// no token or key reaches a log or the server's error log
$logs = (string) $one("SELECT GROUP_CONCAT(log_description SEPARATOR ' ') FROM logs");
$srvlog = is_file($EAB['log']) ? (string) file_get_contents($EAB['log']) : '';
$ok(!str_contains($logs, $secret) && !str_contains($logs, explode('.', $tok2)[2]) && !str_contains($logs . $srvlog, 'rvdl1.' . explode('.', $cr['script'])[1] . '.' . explode('.', $cr['script'])[2] . '.' . explode('.', $cr['script'])[3]), 'no token secret and no install key in the audit log or the server log');

// ================================================================ 6. the build host
echo "== build host\n";
function bh_call(string $action, ?string $token, $body = [], string $method = 'POST'): array
{
    global $base;
    if ($action === 'poll' && $body === []) { $body = ['host_version' => 't', 'capabilities' => ['docker' => true, 'msi' => true]]; }
    $ch = curl_init("$base/api/v1/build_host/$action");
    $h = ['Content-Type: application/json']; if ($token !== null) { $h[] = "Authorization: Bearer $token"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, json_decode((string) $raw, true), (string) $raw];
}
function fx_pe(int $machine, int $size, string $version, string $fill = 'x'): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0");
    $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $b .= "\0main.version=\0$version\0";
    $need = $size - strlen($b);
    return $b . substr(str_repeat(hash('sha256', $fill . $machine, true), intdiv($need, 32) + 1), 0, $need);
}
/** A good build; $linux adds the Linux files. */
function bh_build(string $version, bool $linux, string $fill = 'x'): array
{
    $f = ['rivetit-agent-windows-amd64.exe' => fx_pe(0x8664, 300000, $version, $fill), 'rivetit-agent-windows-arm64.exe' => fx_pe(0xAA64, 200000, $version, $fill),
        'rivetit-agent-windows-amd64.msi' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16000) . $fill];
    if ($linux) {
        $f['rivetit-agent-linux-amd64'] = lx_elf(62, $fill, 2, 2, 1, 40000) . "\0main.version=$version\0";
        $f['rivetit-agent-linux-arm64'] = lx_elf(183, $fill, 2, 2, 1, 40000) . "\0main.version=$version\0";
        $f['install-linux.sh'] = lx_script("# $version $fill\n");
        $f['rivetit-agent-linux-amd64.tar.gz'] = "\x1f\x8b\x08\0" . str_repeat($fill, 3000);
    }
    return $f;
}
function bh_sums(array $f): string { $s = ''; foreach ($f as $n => $b) { $s .= hash('sha256', $b) . "  $n\n"; } return $s; }
function bh_manifest2(array $files, array $over = []): array { $m = []; foreach ($files as $n => $b) { $m[$n] = hash('sha256', $b); } return $over + ['files' => $m, 'commit' => str_repeat('a', 40), 'go_version' => 'go1.24.2', 'built_at' => '2026-10-20T12:00:00Z']; }
function bh_up(string $tok, int $job, string $name, string $bytes): array
{
    $parts = str_split($bytes, 1048576); $last = null;
    foreach ($parts as $i => $p) { $last = bh_call('upload', $tok, ['job_id' => $job, 'name' => $name, 'index' => $i, 'total' => count($parts), 'sha256_chunk' => hash('sha256', $p), 'data_b64' => base64_encode($p)]); if ($last[0] !== 200) { return $last; } }
    return $last;
}
function bh_job($tok, $hid, $version, $auto = null): int { global $db; $r = rivetBuildHostRequest($db, $hid, $version, 'main', $auto, 1); if (!$r['ok']) { echo "  (request refused: {$r['message']})\n"; return 0; } bh_call('poll', $tok, []); bh_call('status', $tok, ['job_id' => $r['job_id'], 'state' => 'building']); return $r['job_id']; }
$H = rivetBuildHostCreate($db, 'Linux box', 1); $HT = $H['token']; $HID = $H['host_id'];
$q('DELETE FROM endpoint_agent_binaries'); $q('DELETE FROM endpoint_agent_msi_templates'); $q('DELETE FROM endpoint_agent_releases'); $lxWipe();

// 6a. an OLD build host: only the Windows files, no Linux
$j = bh_job($HT, $HID, '0.9.6', ['ring' => null, 'pct' => 100, 'activate' => true, 'offer' => false]);
$F = bh_build('0.9.6', false); $F['SHA256SUMS'] = bh_sums($F); foreach ($F as $n => $b) { bh_up($HT, $j, $n, $b); }
[$c, $r] = bh_call('complete', $HT, ['job_id' => $j, 'manifest' => bh_manifest2($F)]);
$ok($c === 200 && $r['state'] === 'published' && cnt1('SELECT COUNT(*) FROM endpoint_agent_binaries') === 2 && cnt1('SELECT COUNT(*) FROM endpoint_agent_linux_files') === 0, 'an old build host that sends only the Windows files still publishes; nothing Linux is stored');

// 6b. a NEW build host: Windows + Linux
$j = bh_job($HT, $HID, '0.9.7', ['ring' => 'pilot', 'pct' => 30, 'activate' => true, 'offer' => true]);
$F = bh_build('0.9.7', true); $F['SHA256SUMS'] = bh_sums($F); foreach ($F as $n => $b) { $u2 = bh_up($HT, $j, $n, $b); if ($u2[0] !== 200) { echo "  upload $n: " . json_encode($u2) . "\n"; } }
[$c, $r] = bh_call('complete', $HT, ['job_id' => $j, 'manifest' => bh_manifest2($F)]);
$hosted = array_column($rows('SELECT * FROM endpoint_agent_linux_files WHERE version = \'0.9.7\''), null, 'name');
$ok($c === 200 && $r['state'] === 'published' && count($hosted) === 4 && $hosted['install-linux.sh']['sha256'] === hash('sha256', $F['install-linux.sh']) && $hosted['rivetit-agent-linux-amd64']['source'] === 'build_host' && (int) $hosted['rivetit-agent-linux-amd64']['is_current'] === 1 && $hosted['rivetit-agent-linux-amd64']['release_ring'] === 'pilot' && (int) $hosted['rivetit-agent-linux-amd64']['rollout_pct'] === 30, 'a new build host: the Linux files are hosted together with the Windows files (' . json_encode($r) . ')');
$ok(rivetLxCommandFiles($db, 'amd64')['version'] === '0.9.7' && rivetLxCommandFiles($db, 'arm64')['version'] === '0.9.7', 'both architectures now have a command');
$ok(cnt1("SELECT COUNT(*) FROM logs WHERE log_action = 'Agent Build Host Published' AND log_description LIKE '%4 Linux files hosted%'") === 1, 'the publish audit row counts the Linux files');

// 6c. hostile builds: each stores NOTHING (Windows or Linux) and the job fails
$hostile = function (string $label, callable $mut, string $version, ?callable $man = null) use ($HT, $HID, $q, $rows, $ok) {
    $before = cnt1('SELECT COUNT(*) FROM endpoint_agent_linux_files'); $bb = cnt1('SELECT COUNT(*) FROM endpoint_agent_binaries');
    $j = bh_job($HT, $HID, $version, ['ring' => null, 'pct' => 100, 'activate' => true, 'offer' => false]);
    $F = bh_build($version, true, 'h'); $F = $mut($F); if (!isset($F['SHA256SUMS'])) { $F['SHA256SUMS'] = bh_sums($F); }
    foreach ($F as $n => $b) { bh_up($HT, $j, $n, $b); }
    [$c, $r] = bh_call('complete', $HT, ['job_id' => $j, 'manifest' => $man ? $man($F) : bh_manifest2($F)]);
    $ok($c === 422 && cnt1('SELECT COUNT(*) FROM endpoint_agent_linux_files') === $before && cnt1('SELECT COUNT(*) FROM endpoint_agent_binaries') === $bb && $rows("SELECT state FROM endpoint_agent_build_jobs WHERE job_id = $j")[0]['state'] === 'failed', "hostile build refused, nothing stored: $label (" . ($r['error'] ?? '') . ')');
};
$hostile('an ELF for the wrong CPU', function ($F) { $F['rivetit-agent-linux-amd64'] = lx_elf(183, 'h', 2, 2, 1, 40000) . "\0main.version=0.9.8\0"; return $F; }, '0.9.8');
$hostile('a Windows PE as the Linux agent', function ($F) { $F['rivetit-agent-linux-arm64'] = fx_pe(0xAA64, 50000, '0.9.9'); return $F; }, '0.9.9');
$hostile('a script as the Linux agent', function ($F) { $F['rivetit-agent-linux-amd64'] = lx_script(str_repeat('# pad ', 2000)); return $F; }, '0.9.10');
$hostile('install-linux.sh without a shebang', function ($F) { $F['install-linux.sh'] = "echo hi\n"; return $F; }, '0.9.11');
$hostile('install-linux.sh with binary data', function ($F) { $F['install-linux.sh'] = "#!/bin/sh\n\0\0\0\x01"; return $F; }, '0.9.12');
$hostile('install-linux.sh over 256 KiB', function ($F) { $F['install-linux.sh'] = "#!/bin/sh\n" . str_repeat('# long ', 50000); return $F; }, '0.9.13');
$hostile('a Linux file missing from SHA256SUMS', function ($F) { unset($F['SHA256SUMS']); $s = $F; unset($s['rivetit-agent-linux-amd64.tar.gz']); $F['SHA256SUMS'] = bh_sums($s); return $F; }, '0.9.14');
$hostile('a Linux file whose SHA256SUMS line is wrong', function ($F) { unset($F['SHA256SUMS']); $s = bh_sums($F); $F['SHA256SUMS'] = str_replace(hash('sha256', $F['install-linux.sh']), str_repeat('0', 64), $s); return $F; }, '0.9.15');
$hostile('a Linux file the manifest hashes differently', function ($F) { return $F; }, '0.9.16', function ($F) { $m = bh_manifest2($F); $m['files']['install-linux.sh'] = str_repeat('1', 64); return $m; });
$hostile('a Linux agent built with another version', function ($F) { $F['rivetit-agent-linux-amd64'] = lx_elf(62, 'h', 2, 2, 1, 40000) . "\0main.version=9.9.9\0"; return $F; }, '0.9.17');
$hostile('a tarball that is not gzip', function ($F) { $F['rivetit-agent-linux-amd64.tar.gz'] = 'not gzip at all'; return $F; }, '0.9.18');
$hostile('a manifest that lists a Linux file that was not uploaded', function ($F) { return $F; }, '0.9.19', function ($F) { $m = bh_manifest2($F); $m['files']['rivetit-agent-linux-arm64.tar.gz'] = str_repeat('2', 64); return $m; });
// names outside the list are still refused
$jx = bh_job($HT, $HID, '0.9.20'); [$c] = bh_up($HT, $jx, 'rivetit-agent-linux-riscv64', 'x'); $ok($c === 422, 'a Linux file name outside the list is refused at upload');
[$c] = bh_up($HT, $jx, 'install-linux.sh', "#!/bin/sh\n" . str_repeat('# ', 200000)); $ok($c === 413 || $c === 422, 'an oversized script is refused at upload');
rivetBuildHostCancel($db, $jx);

// ================================================================ 7. the Add device page and the installer action
echo "== page and action\n";
$ok(rivetLxAvailable($db, 'amd64') && rivetLxAvailable($db, 'arm64'), 'rivetLxAvailable is true with hosted files');
$lx = rivetAgentFeedLinuxAssets($db);
$ok($lx['hosted']['amd64'] === '0.9.7' && $lx['hosted']['arm64'] === '0.9.7' && count($lx['hosted']['rows']) >= 3, 'the page data lists the hosted files');
$lxWipe();
$lx = rivetAgentFeedLinuxAssets($db);
$ok(!rivetLxAvailable($db, 'amd64') && $lx['hosted']['amd64'] === null, 'with nothing hosted there is nothing to offer (the action answers with the fix message; see tests/endpoint_agent_installer_ui.php)');
echo "\n";

function cnt1(string $sql): int { global $db; return (int) $db->query($sql)->fetch_row()[0]; }
