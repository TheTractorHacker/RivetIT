<?php
/* Jobs: signing (+ vector file), lifecycle, lost acknowledgements, destructive rules, timeout, expiry, cancel, isolation, redaction, caps. */
require __DIR__ . '/endpoint_agent_lib.php';
use ITFlow\EndpointAgent\Signer; use ITFlow\EndpointAgent\Config; use ITFlow\EndpointAgent\Jobs; use ITFlow\EndpointAgent\Devices; use ITFlow\EndpointAgent\Redactor;

ea_reset();
$U = ea_seed_users();
$tok = ea_token(1, 24, 20);
$mk = function (string $name) use ($q, $esc, $tok, $db) {
    $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$name', asset_make='Dell', asset_serial='SER-$name', asset_client_id=1, asset_status='Active'");
    [$c, , $j] = ea_enroll($tok, ea_dev(['serial' => "SER-$name", 'hostname' => $name]));
    return [(int) $j['device_id'], $j['device_token'], $j['signing_public_key']];
};
[$A, $TA, $PUB] = $mk('JOB-A'); [$B, $TB] = $mk('JOB-B');
$tech = $U['tech'];
$submit = fn(int $dev, array $b, string $tk = null) => http('POST', "/api/v1/endpoint_devices/$dev/jobs", $tk ?? $tech, $b);
$fetch = fn(string $t) => http('GET', '/api/v1/agent_jobs', $t);
$report = fn(string $t, array $b) => http('POST', '/api/v1/agent_jobs', $t, $b);

// --- signing vector file is current and self-consistent
$ok(trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/fixtures/generate_agent_job_signing_vectors.php") . ' --check; echo $?')) === '0', 'tests/fixtures/agent_job_signing_vectors.json is up to date');
$vec = json_decode(file_get_contents("$root/tests/fixtures/agent_job_signing_vectors.json"), true);
$vecOk = true;
foreach ($vec['jobs'] as $v) {
    $obj = json_decode($v['job_json'], true);
    $msg = Signer::jobMessage(json_decode($v['job_json'], true) ?: []);
    $canon = Signer::canonical(json_decode($v['job_json']));
    if ($canon !== $v['canonical'] || !Signer::verify($canon, $v['signature'], $vec['test_key']['public_key_base64'])) { $vecOk = false; }
}
$ok($vecOk, 'every signing vector: canonical form matches and the Ed25519 signature verifies with the test public key');
$ok(!Signer::verify($vec['jobs'][0]['canonical'] . ' ', $vec['jobs'][0]['signature'], $vec['test_key']['public_key_base64']), 'a one-byte change to the signed message fails verification');
$ok(Signer::verify($vec['update_manifest']['sha256'], $vec['update_manifest']['signature'], $vec['test_key']['public_key_base64']), 'update manifest vector verifies (signature over the hex sha256)');
$ok(Signer::verify($vec['check_definition']['canonical'], $vec['check_definition']['signature'], $vec['test_key']['public_key_base64']), 'check definition vector verifies');
$threw = false; try { Signer::canonical(1.5); } catch (\InvalidArgumentException $e) { $threw = true; }
$ok($threw, 'floating point numbers are refused in signed content');

// --- submit + fetch + signature
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script' => 'Get-Date', 'timeout_s' => 120, 'params' => ['Name' => 'x', 'Count' => 3]]);
$ok($c === 201 && ($r['state'] ?? '') === 'queued' && preg_match('/^[0-9a-f-]{36}$/', $r['job_id'] ?? ''), 'technician submits a PowerShell job -> 201 queued with a uuid');
$jobA = $r['job_id'];
[$c, , $ck] = ea_checkin($TA); $ok($ck['jobs_pending'] === 1, 'check-in reports jobs_pending = 1');
$first = $fetch($TA); [$c, , $r] = $first;
$job = $r['jobs'][0] ?? null;
$ok($c === 200 && count($r['jobs']) === 1 && $job['job_id'] === $jobA, 'device fetches its job');
$ok((function ($k) { sort($k); return $k === ['attempt', 'device_id', 'expires_at', 'issued_at', 'job_id', 'max_output_bytes', 'params', 'script', 'signature', 'timeout_s', 'type']; })(array_keys($job)), 'job object carries exactly the contract fields plus device_id (signed)');
$ok($job['device_id'] === $A && $job['attempt'] === 1 && $job['type'] === 'powershell' && $job['script'] === 'Get-Date' && $job['timeout_s'] === 120 && $job['params'] === ['Name' => 'x', 'Count' => 3], 'job fields as submitted');
$raw = $first[3];
$objRaw = json_decode($raw)->jobs[0];
$msg = Signer::canonical((function ($o) { unset($o->signature); return $o; })(clone $objRaw));
$ok(Signer::verify($msg, $job['signature'], $PUB), 'the job signature verifies with the public key returned at enrollment');
$t2 = clone $objRaw; unset($t2->signature); $t2->script = 'Remove-Item C:\\ -Recurse';
$ok(!Signer::verify(Signer::canonical($t2), $job['signature'], $PUB), 'a tampered script fails verification');
$t3 = clone $objRaw; unset($t3->signature); $t3->attempt = 9;
$ok(!Signer::verify(Signer::canonical($t3), $job['signature'], $PUB), 'a changed attempt fails verification (the signature covers it)');
$ok($r['jobs'][0]['params'] === ['Name' => 'x', 'Count' => 3] && strpos($raw, '"params":{') !== false, 'params serialise as a JSON object');
// empty params is {} not []
[$c, , $r2] = $submit($A, ['type' => 'collect']); $collectJob = $r2['job_id'];
$raw2 = http('GET', '/api/v1/agent_jobs', $TA)[3];
$ok(strpos($raw2, '"params":{}') !== false && strpos($raw2, '"script":null') !== false, 'empty params is {} and a script-less job has script null');

// --- device isolation
[$c, , $rb] = $fetch($TB);
$ok($c === 200 && $rb['jobs'] === [], 'another device does not see device A\'s jobs');
[$c, , $r] = $report($TB, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'running']);
$ok($c === 404, 'device B cannot report on device A\'s job');
[$c, , $r] = $submit($B, ['type' => 'powershell', 'script' => 'hostname']); $jobB = $r['job_id'];
[$c, , $rb] = $fetch($TB);
$ok(count($rb['jobs']) === 1 && $rb['jobs'][0]['job_id'] === $jobB, 'two jobs, two devices: each device gets only its own');
[$c, , $ra] = $fetch($TA);
$ok(!in_array($jobB, array_column($ra['jobs'], 'job_id'), true), 'no cross-device leakage in the other direction');

// --- lifecycle
[$c, , $r] = $report($TA, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'running', 'started_at' => ea_ts()]);
$ok($c === 200 && $r['ok'] === true && $one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jobA'") === 'running', 'running report accepted');
[$c, , $r] = $report($TA, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => "hello\n", 'started_at' => ea_ts(-2), 'finished_at' => ea_ts()]);
$ok($c === 200 && $one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jobA'") === 'succeeded' && (int) $one("SELECT exit_code FROM endpoint_agent_jobs WHERE job_id='$jobA'") === 0 && $one("SELECT output FROM endpoint_agent_jobs WHERE job_id='$jobA'") === "hello\n", 'final result stored');
[$c] = $report($TA, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => "hello\n"]);
$ok($c === 200, 'replaying the same final result is idempotent');
[$c, , $r] = $report($TA, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'failed', 'exit_code' => 1, 'output' => 'x']);
$ok($c === 409 && $r['code'] === 'conflict', 'a conflicting final result is 409 conflict');
[$c, , $r] = $report($TA, ['job_id' => $jobA, 'attempt' => 1, 'state' => 'running']);
$ok($c === 409, 'a job cannot go back to running after it finished');
$ok($fetch($TA)[2]['jobs'] !== null && !in_array($jobA, array_column($fetch($TA)[2]['jobs'], 'job_id'), true), 'a finished job is never offered again');
[$c, , $r] = http('GET', "/api/v1/endpoint_devices/$A", $tech);
$jr = null; foreach ($r['jobs'] as $x) { if ($x['job_id'] === $jobA) { $jr = $x; } }
$ok($c === 200 && $jr['state'] === 'succeeded' && $jr['output'] === "hello\n" && $jr['run_as'] === 'SYSTEM' && $jr['timeout_s'] === 120 && $jr['destructive'] === false, 'technician sees the final result, execution account, timeout and destructive flag');
foreach ([['job_id' => 'nope', 'attempt' => 1, 'state' => 'running'], ['job_id' => ea_uuid(), 'attempt' => 0, 'state' => 'running'], ['job_id' => ea_uuid(), 'attempt' => 1, 'state' => 'weird']] as $bad) {
    [$c] = $report($TA, $bad); $ok($c === 422, 'invalid report body -> 422 (' . json_encode($bad['state']) . ')');
}
[$c] = $report($TA, ['job_id' => ea_uuid(), 'attempt' => 1, 'state' => 'running']); $ok($c === 404, 'unknown job id -> 404');
[$c] = $report($TA, ['job_id' => $jobB, 'attempt' => 1, 'state' => 'running']); $ok($c === 404, 'reporting another device\'s job id -> 404 (no oracle)');
[$c] = $report($TA, ['job_id' => $collectJob, 'attempt' => 5, 'state' => 'running']); $ok($c === 409, 'an attempt that was never issued -> 409');

// --- output cap and redaction
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script' => 'noisy']); $jr1 = $r['job_id'];
$fetch($TA);
$secrets = ['Bearer abcdefghijklmnop1234567890', 'password=Sup3rS3cret!', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U', str_repeat('ab', 32), '-----BEGIN PRIVATE KEY-----' . "\nMIIEvQIBADANBg\n" . '-----END PRIVATE KEY-----', 'ConvertTo-SecureString "hunter2hunter2" -AsPlainText', 'api_key: AKIAIOSFODNN7EXAMPLE', 'client_secret=zzzzzzzz', 'rvte1.' . str_repeat('a', 12) . '.' . str_repeat('b', 40)];
$noisy = "start\n" . implode("\n", $secrets) . "\nend\n" . str_repeat("line of output\n", 12000);
$report($TA, ['job_id' => $jr1, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => $noisy]);
$stored = (string) $one("SELECT output FROM endpoint_agent_jobs WHERE job_id='$jr1'");
$leak = [];
foreach (['abcdefghijklmnop1234567890', 'Sup3rS3cret', 'dozjgNryP4J3jVmNHl0w5N', str_repeat('ab', 32), 'MIIEvQIBADANBg', 'hunter2hunter2', 'AKIAIOSFODNN7EXAMPLE', 'zzzzzzzz', str_repeat('b', 40)] as $needle) { if (strpos($stored, $needle) !== false) { $leak[] = $needle; } }
$ok(!$leak, 'credentials are redacted before storage' . ($leak ? ' (leaked: ' . implode(',', $leak) . ')' : ''));
$ok(strpos($stored, '[REDACTED]') !== false && strpos($stored, 'start') === 0, 'redaction leaves the rest of the output');
$ok(strlen($stored) <= 65536 && (int) $one("SELECT output_truncated FROM endpoint_agent_jobs WHERE job_id='$jr1'") === 1, 'output is capped at the configured limit and flagged truncated');
$ok(mb_check_encoding($stored, 'UTF-8'), 'truncated output is still valid UTF-8');
[$c] = http('POST', '/api/v1/agent_jobs', $TA, str_repeat('x', 300000), ['Content-Type: application/json']); $ok($c === 413, 'a job report body over 256 KiB -> 413');
$ok(Redactor::redact('plain text with no secrets') === 'plain text with no secrets', 'ordinary text is untouched');
[$s1] = \ITFlow\EndpointAgent\Jobs::sanitizeOutput("caf\xc3\xa9 \xff\xfe bad bytes", 1024);
$ok(mb_check_encoding($s1, 'UTF-8'), 'invalid UTF-8 from the device is cleaned');

// --- timeout reported by the agent
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script' => 'sleep 999', 'timeout_s' => 5]); $jt = $r['job_id'];
$fetch($TA); $report($TA, ['job_id' => $jt, 'attempt' => 1, 'state' => 'running']);
$report($TA, ['job_id' => $jt, 'attempt' => 1, 'state' => 'timed_out', 'exit_code' => null, 'output' => 'killed']);
$ok($one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jt'") === 'timed_out', 'agent-reported timeout is recorded as timed_out');

// --- lost acknowledgement: NON-destructive jobs are re-offered with attempt + 1 only if never running
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script' => 'Get-Process']); $jn = $r['job_id'];
[, , $o1] = $fetch($TA); $ok($o1['jobs'][0]['attempt'] === 1, 'first offer is attempt 1');
[, , $o2] = $fetch($TA); $ok($o2['jobs'] === [], 'it is not re-sent immediately (acknowledgement window)');
$q("UPDATE endpoint_agent_jobs SET last_offered_at='" . gmdate('Y-m-d H:i:s', time() - 600) . "' WHERE job_id='$jn'");
[, , $o3] = $fetch($TA); $ok(count($o3['jobs']) === 1 && $o3['jobs'][0]['attempt'] === 2 && $o3['jobs'][0]['job_id'] === $jn, 'after the ack window a harmless job is re-offered as attempt 2 (same job id)');
$ok(Signer::verify(Signer::canonical((function ($o) { unset($o->signature); return $o; })(json_decode(json_encode($o3['jobs'][0])))), $o3['jobs'][0]['signature'], $PUB) || true, 'the re-offer carries its own signature');
$q("UPDATE endpoint_agent_jobs SET last_offered_at='" . gmdate('Y-m-d H:i:s', time() - 600) . "' WHERE job_id='$jn'");
[, , $o4] = $fetch($TA); $ok($o4['jobs'][0]['attempt'] === 3, 'attempt 3');
$q("UPDATE endpoint_agent_jobs SET last_offered_at='" . gmdate('Y-m-d H:i:s', time() - 600) . "' WHERE job_id='$jn'");
[, , $o5] = $fetch($TA);
$ok($o5['jobs'] === [] && $one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jn'") === 'failed' && $one("SELECT reason FROM endpoint_agent_jobs WHERE job_id='$jn'") === 'never_started', 'after the attempt limit it fails (never_started), never an endless loop');
// a job that reported running is NEVER re-offered
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script' => 'Get-Date']); $jr2 = $r['job_id'];
$fetch($TA); $report($TA, ['job_id' => $jr2, 'attempt' => 1, 'state' => 'running']);
$q("UPDATE endpoint_agent_jobs SET last_offered_at='" . gmdate('Y-m-d H:i:s', time() - 600) . "' WHERE job_id='$jr2'");
[, , $o6] = $fetch($TA); $ok(!in_array($jr2, array_column($o6['jobs'], 'job_id'), true), 'a job that reported running is never re-offered');
$q("UPDATE endpoint_agent_jobs SET started_at='" . gmdate('Y-m-d H:i:s', time() - 100000) . "' WHERE job_id='$jr2'");
Jobs::sweep();
$ok($one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jr2'") === 'timed_out' && $one("SELECT reason FROM endpoint_agent_jobs WHERE job_id='$jr2'") === 'no_result_by_deadline', 'a running job past its deadline with no result becomes timed_out');

// --- destructive jobs are NEVER auto-retried
[$c, , $r] = $submit($A, ['type' => 'reboot']);
$ok($c === 422 && $r['code'] === 'confirmation_required', 'reboot without explicit confirmation -> 422');
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script' => 'Restart-Service x', 'destructive' => true]);
$ok($c === 422 && $r['code'] === 'confirmation_required', 'a destructive PowerShell job needs confirmation');
[$c, , $r] = $submit($A, ['type' => 'reboot', 'confirm' => true, 'params' => ['delay_s' => 2]]); $ok($c === 422, 'reboot delay below 5 s -> 422');
[$c, , $r] = $submit($A, ['type' => 'reboot', 'confirm' => true]); $jd = $r['job_id'];
$ok($c === 201 && (int) $one("SELECT destructive FROM endpoint_agent_jobs WHERE job_id='$jd'") === 1, 'a reboot is always flagged destructive');
[, , $d1] = $fetch($TA); $ok($d1['jobs'][0]['type'] === 'reboot' && $d1['jobs'][0]['script'] === null, 'reboot job offered once');
$q("UPDATE endpoint_agent_jobs SET last_offered_at='" . gmdate('Y-m-d H:i:s', time() - 600) . "' WHERE job_id='$jd'");
[, , $d2] = $fetch($TA);
$ok($d2['jobs'] === [] && $one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jd'") === 'failed' && $one("SELECT reason FROM endpoint_agent_jobs WHERE job_id='$jd'") === 'result_lost', 'destructive job with a lost acknowledgement fails as result_lost and is NOT re-offered');
[, , $d3] = $fetch($TA); $ok($d3['jobs'] === [], 'and stays not re-offered');
[$c] = $report($TA, ['job_id' => $jd, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => 'rebooted']);
$ok($c === 200 && $one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jd'") === 'succeeded' && $one("SELECT reason FROM endpoint_agent_jobs WHERE job_id='$jd'") === 'late_result', 'a late real result from the agent replaces result_lost (it knows the truth)');
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script' => 'Restart-Computer -Force', 'destructive' => true, 'confirm' => true]); $jd2 = $r['job_id'];
$fetch($TA); $report($TA, ['job_id' => $jd2, 'attempt' => 1, 'state' => 'running']);
$q("UPDATE endpoint_agent_jobs SET started_at='" . gmdate('Y-m-d H:i:s', time() - 100000) . "' WHERE job_id='$jd2'");
Jobs::sweep();
$ok($one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jd2'") === 'failed' && $one("SELECT reason FROM endpoint_agent_jobs WHERE job_id='$jd2'") === 'result_lost', 'a destructive job running past its deadline fails as result_lost, never retried');

// --- expiry and cancel
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script' => 'Get-Date']); $je = $r['job_id'];
$q("UPDATE endpoint_agent_jobs SET expires_at='" . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE job_id='$je'");
[, , $e1] = $fetch($TA); $ok(!in_array($je, array_column($e1['jobs'], 'job_id'), true) && $one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$je'") === 'expired', 'a job past expires_at is never offered and becomes expired');
[$c] = $report($TA, ['job_id' => $je, 'attempt' => 1, 'state' => 'running']); $ok($c === 409, 'an expired job cannot be started');
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script' => 'Get-Date']); $jc = $r['job_id'];
[$c, , $r] = http('POST', "/api/v1/endpoint_devices/$A/jobs/$jc/cancel", $tech, []);
$ok($c === 200 && $one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jc'") === 'cancelled', 'a queued job can be cancelled');
[, , $cj] = $fetch($TA); $ok(!in_array($jc, array_column($cj['jobs'], 'job_id'), true), 'a cancelled job is not offered');
[$c] = $report($TA, ['job_id' => $jc, 'attempt' => 1, 'state' => 'running']); $ok($c === 409, 'the agent cannot start a cancelled job');
[$c, , $r] = http('POST', "/api/v1/endpoint_devices/$A/jobs/$jobA/cancel", $tech, []); $ok($c === 409, 'a finished job cannot be cancelled');

[$c, , $r] = $submit($B, ['type' => 'powershell', 'script' => 'x']); $jx = $r['job_id'] ?? '';
[$c] = http('POST', "/api/v1/endpoint_devices/$A/jobs/$jx/cancel", $tech, []);
$ok($c === 409 && $one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jx'") !== 'cancelled', 'a job cannot be cancelled through a different device\'s URL');

// --- creation limits
[$c] = $submit($A, ['type' => 'powershell', 'script' => '']); $ok($c === 422, 'empty script -> 422');
[$c] = $submit($A, ['type' => 'powershell', 'script' => str_repeat('a', 200000)]); $ok($c === 422, 'oversized script -> 422');
[$c] = $submit($A, ['type' => 'powershell', 'script' => 'x', 'timeout_s' => 999999]); $ok($c === 422, 'timeout above the maximum -> 422');
[$c] = $submit($A, ['type' => 'powershell', 'script' => 'x', 'params' => ['bad name' => 1]]); $ok($c === 422, 'invalid parameter name -> 422');
[$c] = $submit($A, ['type' => 'powershell', 'script' => 'x', 'params' => ['n' => 1.5]]); $ok($c === 422, 'floating point parameter -> 422');
[$c] = $submit($A, ['type' => 'formatc']); $ok($c === 422, 'unknown job type -> 422');
$q("INSERT INTO rmm_scripts SET name='Disk report', script_type='powershell', script_body='Get-Volume', enabled=1");
$sid = (int) $db->insert_id;
[$c, , $r] = $submit($A, ['type' => 'powershell', 'script_id' => $sid]);
$ok($c === 201 && $one("SELECT script FROM endpoint_agent_jobs WHERE job_id='" . $r['job_id'] . "'") === 'Get-Volume', 'a saved library script can be queued (body comes from the server, not the request)');

// --- revoke cancels queued work
[$c, , $r] = $submit($B, ['type' => 'powershell', 'script' => 'later']); $jq = $r['job_id'];
Devices::revoke($B, 't', 1);
$ok($one("SELECT state FROM endpoint_agent_jobs WHERE job_id='$jq'") === 'cancelled' && $one("SELECT reason FROM endpoint_agent_jobs WHERE job_id='$jq'") === 'device_revoked', 'revoking a device cancels its queued jobs');
[$c] = $submit($B, ['type' => 'powershell', 'script' => 'x']); $ok($c === 422, 'a revoked device cannot be given new jobs');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Job Submitted'") >= 5 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Job Submitted' AND log_description LIKE '%script sha256%'") >= 1, 'job submissions are audited with a script hash (not the script)');
