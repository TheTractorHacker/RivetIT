<?php
/*
 * Local agent builds, "Build on my build host" (RivetIT 26.10.57, DB 2.6.177): the build host endpoints against the REAL front controller (php -S), with a mock build-host
 * client in this file. Scratch database only (guard of tests/endpoint_agent_lib.php).
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=..scratch.. RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... EA_TEST_NON_WINDOWS=1 RMM_TEST_STATE_DIR=none RMM_GATE_STATE_DIR=/nonexistent \
 *     BH_REDIS_PORT=6731 php tests/endpoint_agent_build_host.php
 * config.php must define RIVET_BUILD_HOST_DIR (a scratch folder) and the web server needs a Redis for the rate limiter (BH_REDIS_PORT, default 6379).
 */
putenv('RIVETIT_REDIS_PORT=' . (getenv('BH_REDIS_PORT') ?: '6379'));
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_build_host.php";

if (!defined('RIVET_BUILD_HOST_DIR')) { fwrite(STDERR, "config.php must define RIVET_BUILD_HOST_DIR\n"); exit(2); }
ea_reset();
$U = ea_seed_users();
\ITFlow\EndpointAgent\Config::enable();
shell_exec('redis-cli -p ' . (int) (getenv('BH_REDIS_PORT') ?: 6379) . ' flushall 2>&1');   // the failed-authentication limiter of an earlier run
foreach (['endpoint_agent_binaries', 'endpoint_agent_msi_templates', 'endpoint_agent_releases', 'endpoint_agent_linux_assets', 'endpoint_agent_build_chunks', 'endpoint_agent_build_files', 'endpoint_agent_build_jobs', 'endpoint_agent_build_hosts'] as $t) { $q("DELETE FROM $t"); }
$q("UPDATE endpoint_agent_feed SET releases_json=NULL, etag='', fetched_at=NULL, checked_at=NULL, last_error='', rate_reset_at=NULL");
$rmm = rivetRmmModule($db);
$binDir = $rmm->binaryStore()->storageDir();
register_shutdown_function(function () use ($binDir, $db) {
    foreach ($db->query('SELECT storage_name FROM endpoint_agent_binaries UNION SELECT storage_name FROM endpoint_agent_msi_templates') ?: [] as $r) { @unlink("$binDir/{$r['storage_name']}"); }
});
$EAB = ea_start_server(['RIVETIT_REDIS_PORT' => getenv('BH_REDIS_PORT') ?: '6379']);
$base = "http://127.0.0.1:{$EAB['port']}";
\ITFlow\EndpointAgent\Config::set(['service_url' => $base]);
exec('rm -rf ' . escapeshellarg(RIVET_BUILD_HOST_DIR));

// ---------------------------------------------------------------- mock build-host client
function bh_call(string $action, ?string $token, $body = [], string $method = 'POST', array $hdr = []): array
{
    global $base;
    if ($action === 'poll' && $body === []) { $body = ['host_version' => 't', 'capabilities' => ['docker' => true, 'msi' => true]]; }
    $ch = curl_init("$base/api/v1/build_host/$action");
    $h = $hdr; $h[] = 'Content-Type: application/json';
    if ($token !== null) { $h[] = "Authorization: Bearer $token"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => is_string($body) ? $body : json_encode($body)]);
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
/** The four files of a good build. */
function bh_files(string $version, string $fill = 'x'): array
{
    $f = ['rivetit-agent-windows-amd64.exe' => fx_pe(0x8664, 2500000, $version, $fill), 'rivetit-agent-windows-arm64.exe' => fx_pe(0xAA64, 1200000, $version, $fill),
        'rivetit-agent-windows-amd64.msi' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16000) . $fill];
    $s = '';
    foreach ($f as $n => $b) { $s .= hash('sha256', $b) . "  $n\n"; }
    $f['SHA256SUMS'] = $s;
    return $f;
}
function bh_manifest(array $files, array $over = []): array
{
    $m = [];
    foreach ($files as $n => $b) { $m[$n] = hash('sha256', $b); }
    return $over + ['files' => $m, 'commit' => str_repeat('a', 40), 'go_version' => 'go1.24.2', 'built_at' => '2026-10-19T12:00:00Z'];
}
/** Upload one file chunk by chunk. Returns the last answer. */
function bh_upload(string $tok, int $job, string $name, string $bytes, int $chunk = 1048576): array
{
    $parts = str_split($bytes, $chunk); $total = count($parts); $last = null;
    foreach ($parts as $i => $p) {
        $last = bh_call('upload', $tok, ['job_id' => $job, 'name' => $name, 'index' => $i, 'total' => $total, 'sha256_chunk' => hash('sha256', $p), 'data_b64' => base64_encode($p)]);
        if ($last[0] !== 200) { return $last; }
    }
    return $last;
}
function bh_upload_all(string $tok, int $job, array $files): void { foreach ($files as $n => $b) { bh_upload($tok, $job, $n, $b); } }
function job(int $id): array { global $db; return $db->query("SELECT * FROM endpoint_agent_build_jobs WHERE job_id = $id")->fetch_assoc() ?: []; }
function qdir(int $id): string { $j = job($id); return RIVET_BUILD_HOST_DIR . '/' . $j['quarantine']; }
function cnt(string $sql): int { global $db; return (int) $db->query($sql)->fetch_row()[0]; }
function newjob(int $hid, string $v, ?array $auto = null, string $ref = 'main'): int { global $db, $q; $r = rivetBuildHostRequest($db, $hid, $v, $ref, $auto, 1); if (!$r['ok']) { echo "  (request refused: {$r['message']})\n"; return 0; } return $r['job_id']; }

// ---------------------------------------------------------------- 1. hosts and tokens
echo "== hosts and tokens\n";
$a = rivetBuildHostCreate($db, 'Build box A', 1); $b = rivetBuildHostCreate($db, 'Build box B', 1);
$TA = $a['token']; $TB = $b['token']; $HA = $a['host_id']; $HB = $b['host_id'];
$ok(preg_match('/^rbh_[0-9a-f]{16}_[0-9a-f]{48}$/', $TA) === 1, 'a token looks like rbh_<id>_<secret>');
$row = $db->query("SELECT * FROM endpoint_agent_build_hosts WHERE host_id = $HA")->fetch_assoc();
$secretA = substr($TA, 21);
$ok(!str_contains(json_encode($row), $secretA) && $row['token_hash'] === hash('sha256', $row['token_salt'] . $secretA) && strlen($row['token_salt']) === 32, 'only a salted hash is stored; the secret is nowhere in the row');
$ok(rivetBuildHostCreate($db, '<script>', 1)['ok'] === false && rivetBuildHostCreate($db, '', 1)['ok'] === false, 'hostile or empty host names are refused');
[$c, $j] = bh_call('poll', null, []); $ok($c === 401 && ($j['code'] ?? '') === 'invalid_token', 'no token: 401 invalid_token');
[$c] = bh_call('poll', 'garbage', []); $ok($c === 401, 'malformed token: 401');
[$c, , $raw1] = bh_call('poll', substr($TA, 0, 21) . str_repeat('0', 48), []); [$c2, , $raw2] = bh_call('poll', 'rbh_' . str_repeat('1', 16) . '_' . str_repeat('0', 48), []);
$ok($c === 401 && $c2 === 401 && $raw1 === $raw2, 'right id with the wrong secret and an unknown id give the same 401 answer');
[$c, $j] = bh_call('poll', $TA, ['host_version' => '1.0 <b>x</b>', 'capabilities' => ['docker' => true, 'msi' => true]]);
$ok($c === 200 && $j === ['job' => null], 'poll with a valid token and nothing queued: {job:null}');
$h = $db->query("SELECT * FROM endpoint_agent_build_hosts WHERE host_id = $HA")->fetch_assoc();
$ok($h['last_seen_at'] !== null && $h['last_ip'] === '127.0.0.1' && $h['capabilities'] === 'docker,msi' && !str_contains($h['host_version'], '<') === false || $h['host_version'] === '1.0 <b>x</b>', 'last seen, last IP and capabilities are recorded (text is stored raw, escaped on output)');
[$c, $rj] = bh_call('poll', $TA, ['host_version' => 'reg']); $ok($c === 200 && $rj === ['job' => null], 'a poll without capabilities (--register) answers job:null');
[$c] = bh_call('poll', $TA, [], 'GET'); $ok($c === 405, 'GET is refused (405)');
[$c] = bh_call('poll', $TA, 'not json'); $ok($c === 400, 'a body that is not JSON: 400');
[$c] = bh_call('poll', $TA, str_repeat('a', 1600000)); $ok($c === 413, 'a request body over the cap: 413');
[$c] = bh_call('nope', $TA, []); $ok($c === 404, 'an unknown action: 404');
[$c] = bh_call('poll', $TA, [], 'POST', ['X-Forwarded-For: 9.9.9.9']); $h = $db->query("SELECT last_ip FROM endpoint_agent_build_hosts WHERE host_id = $HA")->fetch_row(); $ok($c === 200 && $h[0] === '127.0.0.1', 'a spoofed X-Forwarded-For does not change the recorded address');
// an API key (normal bearer) does not authenticate here, and a build host token does not authenticate the normal API
[$c] = bh_call('poll', $U['admin'], []); $ok($c === 401, 'a normal API key is not a build host token');
[$c] = (function () use ($base, $TA) { $ch = curl_init("$base/api/v1/tickets"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $TA"]]); curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); return [$c]; })();
$ok($c === 401, 'a build host token does not open the normal API (401)');
$old = $TA; $TA = rivetBuildHostRotate($db, $HA);
[$c] = bh_call('poll', $old, []); $ok($c === 401, 'a rotated (revoked) token stops working at once');
[$c] = bh_call('poll', $TA, []); $ok($c === 200, 'the new token works');
rivetBuildHostSetEnabled($db, $HB, false); [$c] = bh_call('poll', $TB, []); $ok($c === 401, 'a disabled host gets 401');
rivetBuildHostSetEnabled($db, $HB, true); [$c] = bh_call('poll', $TB, []); $ok($c === 200, 're-enabled: works again');

// ---------------------------------------------------------------- 2. requests and version rules
echo "== version rules\n";
foreach (['x.y', '1.2', '1.2.3.4', "1.2.3'", '../1.0.0', ''] as $bad) { $ok(!rivetBuildHostRequest($db, $HA, $bad, 'main', null, 1)['ok'], 'bad version refused: ' . json_encode($bad)); }
foreach (['../x', 'a..b', '-rf', 'a b', "a\nb", 'x/', str_repeat('a', 61), 'a;b'] as $bad) { $ok(!rivetBuildHostRequest($db, $HA, '0.9.1', $bad, null, 1)['ok'], 'bad source ref refused: ' . json_encode($bad)); }
$ok(!rivetBuildHostRequest($db, 9999, '0.9.1', 'main', null, 1)['ok'], 'an unknown host is refused');
$q("INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, uploaded_by, active, is_current, created_at) VALUES ('0.9.5','amd64','" . str_repeat('c', 64) . "',10,'zz',1,1,0,NOW())");
$ok(str_contains(rivetBuildHostRequest($db, $HA, '0.9.5', 'main', null, 1)['message'], 'already exists'), 'a version that is already published is refused');
$ok(str_contains(rivetBuildHostRequest($db, $HA, '0.9.4', 'main', null, 1)['message'], 'lower'), 'a version lower than the latest is refused');
$ok(rivetBuildHostNextVersion($db) === '0.9.6', 'the suggested version is the next patch number');
$q('DELETE FROM endpoint_agent_binaries');
$j1 = newjob($HA, '0.9.6', ['ring' => 'pilot', 'pct' => 40, 'activate' => true, 'offer' => true], 'agent-v0.9.6');
$ok($j1 > 0 && job($j1)['state'] === 'queued' && job($j1)['release_ring'] === 'pilot' && (int) job($j1)['rollout_pct'] === 40, 'a build is queued with its ring and rollout');
$ok(!rivetBuildHostRequest($db, $HA, '0.9.7', 'main', null, 1)['ok'], 'a second build for the same host while one is open is refused');
$ok(str_contains(rivetBuildHostRequest($db, $HB, '0.9.6', 'main', null, 1)['message'], 'already waiting'), 'no second build for the same version, on any host');
$j2 = newjob($HB, '0.9.7', null);
$ok($j2 > 0 && (int) job($j2)['auto_publish'] === 0, 'a second host can build another version at the same time (concurrent jobs)');

// ---------------------------------------------------------------- 3. poll, lease, status
echo "== poll, lease, status\n";
[$c, $p] = bh_call('poll', $TA, []);
$ok($c === 200 && $p['job']['id'] === $j1 && $p['job']['version'] === '0.9.6' && $p['job']['source_ref'] === 'agent-v0.9.6' && $p['job']['msi'] === true && $p['job']['targets'] === ['windows-amd64', 'windows-arm64', 'linux-amd64', 'linux-arm64'] && isset($p['job']['expires_at']), 'poll hands out the job with version, ref, targets, msi and expires_at');
$ok(job($j1)['state'] === 'claimed' && strtotime(job($j1)['lease_expires_at'] . ' UTC') - time() > 800, 'the job is claimed with a 15 minute lease');
[$c, $p] = bh_call('poll', $TA, []); $ok($p['job']['id'] === $j1, 'a second poll before any status gives the same job back (the first answer may have been lost)');
[$c, $p] = bh_call('poll', $TB, []); $ok($p['job']['id'] === $j2, 'host B gets its own job, not A\'s');
[$c, $s] = bh_call('status', $TB, ['job_id' => $j1, 'state' => 'building']); $ok($c === 404, 'the token of another host cannot touch the job (404, same as an unknown id)');
[$c] = bh_call('status', $TB, ['job_id' => 999999, 'state' => 'building']); $ok($c === 404, 'unknown job: 404');
[$c] = bh_call('status', $TA, ['job_id' => $j1, 'state' => 'done']); $ok($c === 422, 'an unknown state is refused');
[$c] = bh_call('status', $TA, ['job_id' => "1 OR 1=1", 'state' => 'building']); $ok($c === 404, 'a hostile job id is just unknown');
[$c, $s] = bh_call('status', $TA, ['job_id' => $j1, 'state' => 'building', 'note' => str_repeat('n', 800) . "<script>\x01"]);
$ok($c === 200 && $s['state'] === 'building' && strlen(job($j1)['note']) === 500 && job($j1)['state'] === 'building', 'status: building, note capped at 500 characters');
$q("UPDATE endpoint_agent_build_jobs SET lease_expires_at = UTC_TIMESTAMP() + INTERVAL 20 SECOND WHERE job_id = $j1");
bh_call('status', $TA, ['job_id' => $j1, 'state' => 'building', 'note' => 'cmake <b>']);
$ok(strtotime(job($j1)['lease_expires_at'] . ' UTC') - time() > 3000 && job($j1)['note'] === 'cmake <b>', 'status renews the lease (and stores the note raw, to be escaped on output)');
[$c, $p] = bh_call('poll', $TA, []); $ok($p['job'] === null, 'while a job is being worked on a poll hands out nothing');

// ---------------------------------------------------------------- 4. upload rules
echo "== upload rules\n";
$F = bh_files('0.9.6');
$exe = $F['rivetit-agent-windows-amd64.exe'];
$chunk = fn(array $o) => array_merge(['job_id' => $j1, 'name' => 'rivetit-agent-windows-amd64.exe', 'index' => 0, 'total' => 3, 'sha256_chunk' => hash('sha256', 'abc'), 'data_b64' => base64_encode('abc')], $o);
foreach (['../../etc/passwd', 'rivetit-agent-linux-amd64', 'evil.php', 'SHA256SUMS ', 'rivetit-agent-windows-amd64.EXE', "rivetit-agent-windows-amd64.exe\0.php", '', 'x/../SHA256SUMS'] as $n) {
    [$c, $r] = bh_call('upload', $TA, $chunk(['name' => $n])); $ok($c === 422 && $r['code'] === 'bad_name', 'file name refused: ' . json_encode($n));
}
[$c, $r] = bh_call('upload', $TA, $chunk(['name' => ['a']])); $ok($c === 422, 'a file name that is an array is refused');
[$c, $r] = bh_call('upload', $TA, $chunk(['sha256_chunk' => hash('sha256', 'other')])); $ok($c === 422 && $r['code'] === 'bad_hash', 'a chunk whose hash is wrong is refused');
[$c, $r] = bh_call('upload', $TA, $chunk(['data_b64' => base64_encode(str_repeat('a', 1048577)), 'sha256_chunk' => hash('sha256', str_repeat('a', 1048577))])); $ok($c === 413, 'a chunk over 1 MiB raw is refused (413)');
[$c, $r] = bh_call('upload', $TA, $chunk(['data_b64' => '@@@'])); $ok($c === 422, 'data that is not base64 is refused');
[$c, $r] = bh_call('upload', $TA, $chunk(['index' => 3])); $ok($c === 422, 'index >= total is refused');
[$c, $r] = bh_call('upload', $TA, $chunk(['index' => -1])); $ok($c === 422, 'a negative index is refused');
[$c, $r] = bh_call('upload', $TA, $chunk(['total' => 100000])); $ok($c === 422, 'an absurd chunk count is refused');
[$c, $r] = bh_call('upload', $TA, $chunk(['index' => '0'])); $ok($c === 422, 'index must be a whole number, not a string');
[$c, $r] = bh_call('upload', $TA, $chunk(['name' => 'SHA256SUMS', 'total' => 2])); $ok($c === 422, 'SHA256SUMS cannot be split in many chunks (its cap is 16 KiB, one chunk)');
$big = str_repeat('s', 20000); [$c, $r] = bh_call('upload', $TA, $chunk(['name' => 'SHA256SUMS', 'total' => 1, 'data_b64' => base64_encode($big), 'sha256_chunk' => hash('sha256', $big)])); $ok($c === 413, 'a SHA256SUMS over 16 KiB is refused');
$ok(cnt("SELECT COUNT(*) FROM endpoint_agent_build_chunks") === 0, 'none of the refused chunks was stored');
$p0 = str_split($exe, 1048576);
$up = fn($i, $bytes = null, $tot = 3) => bh_call('upload', $TA, ['job_id' => $j1, 'name' => 'rivetit-agent-windows-amd64.exe', 'index' => $i, 'total' => $tot, 'sha256_chunk' => hash('sha256', $bytes ?? $p0[$i]), 'data_b64' => base64_encode($bytes ?? $p0[$i])]);
[$c, $r] = $up(1); $ok($c === 200 && $r['received'] === 1 && job($j1)['state'] === 'uploading', 'a chunk out of order is accepted (state uploading)');
[$c, $r] = $up(1); $ok($c === 200 && ($r['replay'] ?? false) === true && $r['received'] === 1, 'a replayed chunk (same bytes) is accepted and ignored');
[$c, $r] = $up(1, 'different bytes'); $ok($c === 409 && $r['code'] === 'chunk_conflict', 'a replayed chunk with different bytes is refused (409)');
[$c, $r] = $up(0, null, 4); $ok($c === 409 && $r['code'] === 'total_mismatch', 'a different total than the first chunk is refused');
$ok(count(glob(qdir($j1) . '/*')) === 1 && preg_match('/^[0-9a-f]{32}\.1\.part$/', basename(glob(qdir($j1) . '/*')[0])) === 1, 'only a random-named .part file exists in the job quarantine (no host-chosen name)');
$ok(!file_exists(RIVET_BUILD_HOST_DIR . '/' . 'rivetit-agent-windows-amd64.exe'), 'nothing is written under a host-chosen name');
[$c, $r] = bh_call('upload', $TB, ['job_id' => $j1] + $chunk([])); $ok($c === 404, 'host B cannot upload into host A\'s job');
$up(0); $up(2);
// complete while a file is missing
[$c, $r] = bh_call('complete', $TA, ['job_id' => $j1, 'manifest' => bh_manifest($F)]);
$ok($c === 422 && $r['code'] === 'verify_failed' && str_contains($r['error'], 'was not uploaded') && job($j1)['state'] === 'failed' && !is_dir(qdir($j1)), 'complete with a file missing: failed, quarantine removed');
[$c, $r] = bh_call('status', $TA, ['job_id' => $j1, 'state' => 'building']); $ok($c === 409 && $r['code'] === 'job_failed', 'a failed job answers 409 to later calls');

// ---------------------------------------------------------------- 5. the full flow, auto publish (ring + rollout from the request)
echo "== full flow with publish when built\n";
$q('UPDATE endpoint_agent_build_hosts SET enabled = 1');
$j3 = newjob($HA, '0.9.6', ['ring' => 'pilot', 'pct' => 40, 'activate' => true, 'offer' => true]);
[$c, $p] = bh_call('poll', $TA, []); $ok($p['job']['id'] === $j3, 'poll: the job');
bh_call('status', $TA, ['job_id' => $j3, 'state' => 'building', 'note' => 'make dist']);
bh_call('status', $TA, ['job_id' => $j3, 'state' => 'uploading', 'note' => 'uploading']);
$F = bh_files('0.9.6', 'f1'); bh_upload_all($TA, $j3, $F);
$ok(cnt("SELECT COUNT(*) FROM endpoint_agent_build_chunks") === 3 + 2 + 1 + 1, 'chunks recorded: 3 + 2 + 1 + 1 (a 2.4 MB exe in three, a 1.1 MB exe in two)');
[$c, $r] = bh_call('complete', $TA, ['job_id' => $j3, 'manifest' => bh_manifest($F)]);
$ok($c === 200 && $r['state'] === 'published' && job($j3)['state'] === 'published', 'complete: verified and published (' . json_encode($r) . ')');
$bins = $rows('SELECT version, arch, is_current, sha256 FROM endpoint_agent_binaries ORDER BY arch');
$ok(count($bins) === 2 && $bins[0]['arch'] === 'amd64' && $bins[0]['sha256'] === hash('sha256', $F['rivetit-agent-windows-amd64.exe']) && $bins[1]['sha256'] === hash('sha256', $F['rivetit-agent-windows-arm64.exe']) && (int) $bins[0]['is_current'] === 1, 'both executables are stored with the verified hashes and made current');
$rel = $rows('SELECT ring, rollout_pct, arch FROM endpoint_agent_releases ORDER BY arch');
$ok(count($rel) === 2 && $rel[0]['ring'] === 'pilot' && (int) $rel[0]['rollout_pct'] === 40, 'offered to the pilot ring at 40% as chosen at request time');
$ok(cnt("SELECT COUNT(*) FROM endpoint_agent_msi_templates WHERE version = '0.9.6' AND arch = 'amd64' AND is_current = 1") === 1, 'the MSI template is stored and current');
$ok(!is_dir(qdir($j3)) && cnt('SELECT COUNT(*) FROM endpoint_agent_build_chunks') === 0, 'the quarantine and chunk rows are gone after publishing');
$ok(cnt("SELECT COUNT(*) FROM logs WHERE log_action IN ('Agent Build Host Published','Agent Build Host Job Claimed')") >= 2 && cnt("SELECT COUNT(*) FROM logs WHERE log_action = 'Binary Uploaded'") === 2, 'claim, publish and the Core upload are audited');
[$c, $r] = bh_call('complete', $TA, ['job_id' => $j3, 'manifest' => bh_manifest($F)]); $ok($c === 409 && $r['state'] === 'published', 'complete twice: the second is refused (409, published)');

// ---------------------------------------------------------------- 6. built, then an administrator publishes
echo "== stop at built, administrator publishes\n";
rivetBuildHostCancel($db, $j2);
$j4 = newjob($HB, '0.9.7', null);
bh_call('poll', $TB, []); bh_call('status', $TB, ['job_id' => $j4, 'state' => 'building']);
$F4 = bh_files('0.9.7', 'f2'); bh_upload_all($TB, $j4, $F4);
[$c, $r] = bh_call('complete', $TB, ['job_id' => $j4, 'manifest' => bh_manifest($F4)]);
$ok($c === 200 && $r['state'] === 'built' && cnt("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '0.9.7'") === 0 && is_dir(qdir($j4)), 'without publish-when-built the job stops at "built": nothing stored yet, files kept in quarantine');
$pr = rivetBuildHostPublish($db, $rmm, $j4, ['id' => 1, 'name' => 'Scratch Admin']);
$ok($pr['ok'] && job($j4)['state'] === 'published' && cnt("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '0.9.7'") === 2 && cnt("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '0.9.7' AND is_current = 1") === 0, 'the administrator publishes it: stored, not current, not offered (no choices made)');
$pr = rivetBuildHostPublish($db, $rmm, $j4, ['id' => 1, 'name' => 'Scratch Admin']); $ok(!$pr['ok'], 'publishing twice is refused');
$pr = rivetBuildHostPublish($db, $rmm, $j3, ['id' => 1, 'name' => 'x']); $ok(!$pr['ok'], 'a published job cannot be published again');

// ---------------------------------------------------------------- 7. verification failures: nothing stored
echo "== verification failures\n";
$bad = function (string $label, callable $mutate, string $needle, ?string $ver = null) use ($TA, $HA, $db, $q, $rows, $ok): void {
    static $n = 0; $n++;
    $v = '0.9.' . (20 + $n);
    $id = newjob($HA, $v, null);
    bh_call('poll', $TA, []); bh_call('status', $TA, ['job_id' => $id, 'state' => 'building']);
    $F = bh_files($ver ?? $v, 'g' . $n); $man = null; $up = $F;
    $mutate($F, $up, $man);            // $F: what is uploaded, $up: unused, $man: manifest override
    bh_upload_all($TA, $id, $F);
    $before = cnt('SELECT COUNT(*) FROM endpoint_agent_binaries') + cnt('SELECT COUNT(*) FROM endpoint_agent_msi_templates');
    [$c, $r] = bh_call('complete', $TA, ['job_id' => $id, 'manifest' => $man ?? bh_manifest($F)]);
    $after = cnt('SELECT COUNT(*) FROM endpoint_agent_binaries') + cnt('SELECT COUNT(*) FROM endpoint_agent_msi_templates');
    $ok($c === 422 && job($id)['state'] === 'failed' && $before === $after && !is_dir(qdir($id)) && str_contains(strtolower($r['error'] ?? ''), strtolower($needle)), "$label: refused, job failed, nothing stored, quarantine gone (" . ($r['error'] ?? json_encode($r)) . ')');
    // the job is over: reuse the host
};
$bad('manifest hash differs from the bytes', function (&$F, &$u, &$m) { $m = bh_manifest($F); $m['files']['rivetit-agent-windows-arm64.exe'] = str_repeat('0', 64); }, 'manifest');
$bad('SHA256SUMS lists another hash', function (&$F) { $F['SHA256SUMS'] = str_replace(substr(hash('sha256', $F['rivetit-agent-windows-amd64.msi']), 0, 8), 'deadbeef', $F['SHA256SUMS']); }, 'SHA256SUMS');
$bad('SHA256SUMS is garbage', function (&$F) { $F['SHA256SUMS'] = "hello world\n"; }, 'SHA256SUMS');
$bad('an executable built with another version', function (&$F) { }, 'does not contain the version', '0.9.1');
$bad('a file that is not an executable', function (&$F) { $F['rivetit-agent-windows-amd64.exe'] = str_repeat('A', 100000); $s = ''; foreach ($F as $n => $b) { if ($n !== 'SHA256SUMS') { $s .= hash('sha256', $b) . "  $n\n"; } } $F['SHA256SUMS'] = $s; }, 'windows executable');
$bad('an arm64 binary uploaded as the amd64 name', function (&$F) { global $argv; $v = null; $F['rivetit-agent-windows-amd64.exe'] = $F['rivetit-agent-windows-arm64.exe']; $s = ''; foreach ($F as $n => $b) { if ($n !== 'SHA256SUMS') { $s .= hash('sha256', $b) . "  $n\n"; } } $F['SHA256SUMS'] = $s; }, 'amd64');
$bad('an MSI that is not an OLE file', function (&$F) { $F['rivetit-agent-windows-amd64.msi'] = str_repeat('Z', 20000); $s = ''; foreach ($F as $n => $b) { if ($n !== 'SHA256SUMS') { $s .= hash('sha256', $b) . "  $n\n"; } } $F['SHA256SUMS'] = $s; }, 'windows installer');
$bad('an incomplete upload (a chunk missing)', function (&$F, &$u, &$m) { $m = bh_manifest($F); $F['rivetit-agent-windows-arm64.exe'] = substr($F['rivetit-agent-windows-arm64.exe'], 0, 1048576); }, 'manifest');
$ok(cnt("SELECT COUNT(*) FROM logs WHERE log_action = 'Agent Build Host Verify Failed'") >= 8, 'every failed verification is audited');

// ---------------------------------------------------------------- 8. expiry, cancel, revoked mid-job
echo "== expiry, cancel, revoked token\n";
$id = newjob($HA, '0.9.40', null); bh_call('poll', $TA, []); bh_call('status', $TA, ['job_id' => $id, 'state' => 'building']);
$q("UPDATE endpoint_agent_build_jobs SET lease_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE job_id = $id");
[$c, $r] = bh_call('status', $TA, ['job_id' => $id, 'state' => 'uploading']); $ok($c === 409 && $r['code'] === 'job_expired' && job($id)['state'] === 'expired', 'a job whose lease ran out is expired; later calls get 409 job_expired');
[$c, $r] = bh_call('upload', $TA, ['job_id' => $id] + $chunk([])); $ok($c === 409, 'upload to an expired job: 409');
$id = newjob($HA, '0.9.41', null); $q("UPDATE endpoint_agent_build_jobs SET created_at = UTC_TIMESTAMP() - INTERVAL 61 MINUTE WHERE job_id = $id");
[$c, $p] = bh_call('poll', $TA, []); $ok($p['job'] === null && job($id)['state'] === 'expired', 'a queued job nobody claimed for 60 minutes expires');
$id = newjob($HA, '0.9.42', null); bh_call('poll', $TA, []); bh_call('status', $TA, ['job_id' => $id, 'state' => 'building']);
bh_upload($TA, $id, 'rivetit-agent-windows-arm64.exe', bh_files('0.9.42')['rivetit-agent-windows-arm64.exe']);
$ok(is_dir(qdir($id)) && rivetBuildHostCancel($db, $id), 'an administrator cancels a running job');
[$c, $r] = bh_call('status', $TA, ['job_id' => $id, 'state' => 'building']); $ok($c === 409 && $r['code'] === 'job_cancelled', 'the build host learns of the cancel at its next status call');
$ok(!is_dir(qdir($id)) && cnt('SELECT COUNT(*) FROM endpoint_agent_build_chunks') === 0, 'cancel removes the quarantine');
[$c] = bh_call('complete', $TA, ['job_id' => $id, 'manifest' => ['files' => []]]); $ok($c === 409, 'complete after cancel: 409');
$id = newjob($HA, '0.9.43', null); ok_cancel: $ok(rivetBuildHostCancel($db, $id) && job($id)['state'] === 'cancelled', 'a queued job can be cancelled before it is claimed');
[$c, $p] = bh_call('poll', $TA, []); $ok($p['job'] === null, 'a cancelled job is not handed out');
$id = newjob($HA, '0.9.44', null); bh_call('poll', $TA, []); bh_call('status', $TA, ['job_id' => $id, 'state' => 'building']);
$TA = rivetBuildHostRotate($db, $HA) ?: $TA;   // revoke while building
[$c] = bh_call('status', $TA . 'x', ['job_id' => $id, 'state' => 'building']); $ok($c === 401, 'a revoked token cannot report on its running job');
[$c] = bh_call('status', $TA, ['job_id' => $id, 'state' => 'failed', 'note' => 'docker failed', 'log_tail' => str_repeat("line\n", 2000) . 'token rbh_' . str_repeat('a', 16) . '_' . str_repeat('b', 48) . "\x07"]);
$j = job($id); $ok($c === 200 && $j['state'] === 'failed' && strlen($j['log_tail']) <= 4096 && !str_contains($j['log_tail'], 'rbh_aaaa') && !str_contains($j['log_tail'], "\x07"), 'a failed report stores a log tail of at most 4 KiB with token-shaped text masked');
$ok(rivetBuildHostSetEnabled($db, $HA, false) && true, 'host disabled');
$id = newjob($HA, '0.9.45', null); $ok($id === 0, 'no build can be requested for a disabled host');
rivetBuildHostSetEnabled($db, $HA, true);
$id = newjob($HA, '0.9.45', null); bh_call('poll', $TA, []); rivetBuildHostSetEnabled($db, $HA, false);
$ok(job($id)['state'] === 'cancelled', 'disabling a host cancels its open jobs'); rivetBuildHostSetEnabled($db, $HA, true);
$id = newjob($HA, '0.9.46', null); bh_call('poll', $TA, []); bh_call('status', $TA, ['job_id' => $id, 'state' => 'building']);
$F = bh_files('0.9.46', 'h'); bh_upload_all($TA, $id, $F); $dirB = qdir($id);
[$c] = bh_call('complete', $TA, ['job_id' => $id, 'manifest' => bh_manifest($F)]); $ok($c === 200, 'job at built (for the retention check)');
$q("UPDATE endpoint_agent_build_jobs SET last_status_at = UTC_TIMESTAMP() - INTERVAL 2 DAY, state='built' WHERE job_id = $id"); rivetBuildHostSweep($db);
$ok(job($id)['state'] === 'expired' && !is_dir($dirB), 'a verified build nobody published for a day is removed');

// ---------------------------------------------------------------- 9. concurrency
echo "== concurrency\n";
$ids = []; foreach ([[$HA, $TA, '0.9.50'], [$HB, $TB, '0.9.51']] as [$h, $t, $v]) { $ids[$v] = newjob($h, $v, ['ring' => 'stable', 'pct' => 100, 'activate' => false, 'offer' => false]); bh_call('poll', $t, []); bh_call('status', $t, ['job_id' => $ids[$v], 'state' => 'building']); }
$FF = ['0.9.50' => bh_files('0.9.50', 'p'), '0.9.51' => bh_files('0.9.51', 'q')];
// both hosts upload the same-sized exe chunks at the same time (interleaved with curl_multi)
$mh = curl_multi_init(); $hs = [];
foreach ([['0.9.50', $TA], ['0.9.51', $TB]] as [$v, $t]) {
    foreach (str_split($FF[$v]['rivetit-agent-windows-amd64.exe'], 1048576) as $i => $p) {
        $ch = curl_init("$base/api/v1/build_host/upload"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $t", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['job_id' => $ids[$v], 'name' => 'rivetit-agent-windows-amd64.exe', 'index' => $i, 'total' => 3, 'sha256_chunk' => hash('sha256', $p), 'data_b64' => base64_encode($p)])]);
        curl_multi_add_handle($mh, $ch); $hs[] = $ch;
    }
}
do { curl_multi_exec($mh, $run); curl_multi_select($mh); } while ($run > 0);
$codes = array_map(fn($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $hs);
$ok($codes === array_fill(0, 6, 200), 'six chunks of two hosts, sent at the same time, are all accepted');
bh_upload($TA, $ids['0.9.50'], 'rivetit-agent-windows-arm64.exe', $FF['0.9.50']['rivetit-agent-windows-arm64.exe']); bh_upload($TA, $ids['0.9.50'], 'rivetit-agent-windows-amd64.msi', $FF['0.9.50']['rivetit-agent-windows-amd64.msi']); bh_upload($TA, $ids['0.9.50'], 'SHA256SUMS', $FF['0.9.50']['SHA256SUMS']);
bh_upload($TB, $ids['0.9.51'], 'rivetit-agent-windows-arm64.exe', $FF['0.9.51']['rivetit-agent-windows-arm64.exe']); bh_upload($TB, $ids['0.9.51'], 'rivetit-agent-windows-amd64.msi', $FF['0.9.51']['rivetit-agent-windows-amd64.msi']); bh_upload($TB, $ids['0.9.51'], 'SHA256SUMS', $FF['0.9.51']['SHA256SUMS']);
// the same job completed twice at once: one wins
$mh = curl_multi_init(); $hs = [];
foreach ([['0.9.50', $TA], ['0.9.50', $TA], ['0.9.51', $TB]] as [$v, $t]) {
    $ch = curl_init("$base/api/v1/build_host/complete"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $t", 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['job_id' => $ids[$v], 'manifest' => bh_manifest($FF[$v])])]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $run); curl_multi_select($mh); } while ($run > 0);
$codes = array_map(fn($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $hs); sort($codes);
$ok(job($ids['0.9.50'])['state'] === 'published' && job($ids['0.9.51'])['state'] === 'published', 'both concurrent jobs end published (' . implode(',', $codes) . ')');
$ok(cnt("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version IN ('0.9.50','0.9.51')") === 4 && cnt("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '0.9.50'") === 2, 'no duplicate rows from the double complete');

// ---------------------------------------------------------------- 10. quarantine and secrets hygiene
echo "== hygiene\n";
$ok(cnt("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%" . $esc(substr($TA, 21)) . "%' OR log_description LIKE '%rbh_%_%'") === 0, 'no token appears in the audit log');
$ok(count(glob(RIVET_BUILD_HOST_DIR . '/*')) === 0, 'no quarantine directory is left over after all jobs ended');
$mode = decoct(fileperms(RIVET_BUILD_HOST_DIR) & 0777); $ok($mode === '700', "the quarantine root is mode 0700 ($mode)");
// rate limit on failed authentication: last, it blocks the address for a minute
$last = 0; for ($i = 0; $i < 30; $i++) { [$c] = bh_call('poll', 'rbh_' . bin2hex(random_bytes(8)) . '_' . bin2hex(random_bytes(24)), []); if ($c === 429) { $last = $i; break; } }
$ok($last > 0 && $last <= 21, "failed authentication is rate limited (429 after $last tries)");
// deleting a host removes its jobs and files
rivetBuildHostDelete($db, $HB); $ok(cnt("SELECT COUNT(*) FROM endpoint_agent_build_jobs WHERE host_id = $HB") === 0 && rivetBuildHostGet($db, $HB) === null, 'deleting a host removes it and its jobs');
exit($fails > 0 ? 1 : 0);
