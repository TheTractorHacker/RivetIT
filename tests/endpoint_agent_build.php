<?php
/*
 * Agent updates > "Build a new version" (RivetIT 26.10.55, DB 2.6.176): the GitHub token (encrypted), the workflow dispatch, the run follow-up, publish-when-built, the handler and the card.
 * Scratch database + the MOCK GitHub (tests/support/mock_github.php on a loopback port); nothing here touches the network.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... EA_TEST_NON_WINDOWS=1 RMM_TEST_STATE_DIR=none RMM_GATE_STATE_DIR=/nonexistent php tests/endpoint_agent_build.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_agent_build_ui.php";

ea_reset();
$U = ea_seed_users();
$q('DELETE FROM endpoint_agent_binaries');
$q('DELETE FROM endpoint_agent_msi_templates');
$q('DELETE FROM endpoint_agent_releases');
$q('DELETE FROM endpoint_agent_linux_assets');
$q("UPDATE endpoint_agent_feed SET repo='TheTractorHacker/rivet-core', releases_json=NULL, etag='', fetched_at=NULL, checked_at=NULL, last_error='', rate_reset_at=NULL");
$q("UPDATE endpoint_agent_build SET gh_token='', token_status='', build_version='', build_repo='', requested_at=NULL, requested_by=0, run_id=0, run_state='', run_note='', checked_at=NULL, finished_at=NULL, auto_publish=0, auto_opts='', auto_state='', auto_note=''");
$rmm = rivetRmmModule($db);
$binDir = $rmm->binaryStore()->storageDir();
register_shutdown_function(function () use ($binDir, $db) {
    foreach ($db->query('SELECT storage_name FROM endpoint_agent_binaries UNION SELECT storage_name FROM endpoint_agent_msi_templates') ?: [] as $r) { @unlink("$binDir/{$r['storage_name']}"); }
});

// ---------------------------------------------------------------- fixtures: releases 0.9.0 and 0.9.1 (listed), 0.9.2 (appears when "built")
function fx_pe(int $machine, int $size, string $fill): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0");
    $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $need = $size - strlen($b);

    return $b . substr(str_repeat(hash('sha256', $fill . $machine, true), intdiv($need, 32) + 1), 0, $need);
}
$mockDir = sys_get_temp_dir() . '/mockghb_' . bin2hex(random_bytes(4));
foreach (['0.9.0', '0.9.1', '0.9.2'] as $v) { mkdir("$mockDir/assets/agent-v$v", 0775, true); }
register_shutdown_function(function () use ($mockDir) { exec('rm -rf ' . escapeshellarg($mockDir)); });
$rel = function (string $v, bool $pre = false) use ($mockDir): array {
    $exe = fx_pe(0x8664, 40000 + strlen($v), 'b' . $v);
    file_put_contents("$mockDir/assets/agent-v$v/rivetit-agent-windows-amd64.exe", $exe);
    $sums = hash('sha256', $exe) . "  rivetit-agent-windows-amd64.exe\n";
    file_put_contents("$mockDir/assets/agent-v$v/SHA256SUMS", $sums);

    return ['tag_name' => "agent-v$v", 'name' => $v, 'draft' => false, 'prerelease' => $pre, 'published_at' => '2026-10-18T17:16:53Z', 'body' => '',
        'assets' => [['name' => 'rivetit-agent-windows-amd64.exe', 'size' => strlen($exe), 'digest' => 'sha256:' . hash('sha256', $exe)], ['name' => 'SHA256SUMS', 'size' => strlen($sums), 'digest' => 'sha256:' . hash('sha256', $sums)]]];
};
$r090 = $rel('0.9.0'); $r091 = $rel('0.9.1', true); $r092 = $rel('0.9.2');
$setReleases = fn (array $l) => file_put_contents("$mockDir/releases.json", json_encode($l));
$setReleases([$r091, $r090]);
$scn = function (array $s) use ($mockDir) { file_put_contents("$mockDir/scenario.json", json_encode($s)); };
$actions = function () use ($mockDir): array { $f = "$mockDir/actions.log"; return is_file($f) ? array_map(fn ($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES)) : []; };
$actReset = function () use ($mockDir) { @unlink("$mockDir/actions.log"); @unlink("$mockDir/requests.log"); };
$reqs = function (string $needle = '') use ($mockDir): int { $f = "$mockDir/requests.log"; if (!is_file($f)) { return 0; } return $needle === '' ? count(file($f, FILE_IGNORE_NEW_LINES)) : substr_count((string) file_get_contents($f), $needle); };
$TOKEN = 'github_pat_11ABCDEFG0abcdefghijkl_ZYXWVUTSRQPONMLKJIHGFEDCBA9876543210zyxwvutsrqponmlkjihgfedcba';
$scn(['token' => $TOKEN]);
$mock = ea_start_php("$root/tests/support/mock_github.php", ['MOCK_GH_DIR' => $mockDir]);
$origin = 'http://127.0.0.1:' . $mock['port'];
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=' . $origin);
$sdir = sys_get_temp_dir() . '/ea_build_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$webSrv = ea_start_php("$root/tests/mobile_api_router.php", ['RIVET_AGENT_FEED_TEST_ORIGIN' => $origin, 'RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir", 'upload_max_filesize=16M', 'post_max_size=32M', 'memory_limit=512M', 'max_execution_time=300']);
$wb = 'http://127.0.0.1:' . $webSrv['port'];
$S_ = []; foreach ([1 => 'admin', 10 => 'tech', 12 => 'viewer', 14 => 'moduleonly', 15 => 'normo', 16 => 'deptb'] as $uid => $n) { $S_[$n] = ea_forge_session($sdir, $uid); }
\ITFlow\EndpointAgent\Config::enable();
\ITFlow\EndpointAgent\Config::set(['service_url' => $wb]);
$row = fn () => $rows('SELECT * FROM endpoint_agent_build WHERE id = 1')[0] ?? [];
$audit = fn (string $a) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = '" . $esc($a) . "'");
$resetBuild = function () use ($q) { $q("UPDATE endpoint_agent_build SET build_version='', build_repo='', requested_at=NULL, requested_by=0, run_id=0, run_state='', run_note='', checked_at=NULL, finished_at=NULL, auto_publish=0, auto_opts='', auto_state='', auto_note=''"); };
$refresh = fn () => rivetAgentFeedRefresh($db, true);
$unforce = fn () => $q('UPDATE endpoint_agent_feed SET checked_at = NULL, rate_reset_at = NULL');
$unforce(); $refresh();
$rels = fn () => rivetAgentFeedCached($db)['releases'];

// ================================================================ 1. versions
$ok(count($rels()) === 2 && rivetAgentBuildNextVersion($rels()) === '0.9.2', 'next version: latest known 0.9.1 + 1 = 0.9.2');
$ok(rivetAgentBuildNextVersion([]) === '0.1.0', 'next version with no release at all is 0.1.0');
$ok(rivetAgentBuildNextVersion([['version' => '0.9.9-rc1']]) === '0.9.10', 'next version drops a suffix and adds one to the patch number');
$chkV = fn (string $v) => rivetAgentBuildCheckVersion($v, $rels());
$ok($chkV('0.9.2') === null && $chkV('0.9.3') === null && $chkV('1.0.0') === null && $chkV('0.9.2-rc1') === null && $chkV('0.10.0') === null, 'good versions are accepted (next patch, higher, suffix)');
$ok($chkV('0.9.1') !== null && str_contains($chkV('0.9.1'), 'already exists') && str_contains((string) $chkV('0.9.0'), 'already exists'), 'a version that exists is refused');
$ok(str_contains((string) $chkV('0.8.9'), 'lower than the latest') && str_contains((string) $chkV('0.0.1'), 'lower'), 'a lower version is refused');
foreach (['', 'abc', '1.2', '1.2.3.4', 'v1.2.3', '1.2.3-', '1.2.3-' . str_repeat('a', 21), "1.2.3\n", '1.2.3; rm -rf /', '1.2.3 ', '01.2.3x', '1.2.3-$(id)', '1.2.3-<b>', "1.2.3-a\0b", '99999999.1.1', '１.２.３', '1.2.3-.x', '1.2.3--x'] as $bv) {
    $e = $chkV($bv);
    $ok($e !== null || $bv === '1.2.3--x', 'hostile version ' . json_encode(substr($bv, 0, 30)) . ' is refused' . ($e === null ? ' (accepted: harmless characters only)' : ''));
}
$ok(rivetAgentBuildCheckVersion('0.9.1', [['version' => '0.9.1-rc1']]) === null, '0.9.1 is higher than the pre-release 0.9.1-rc1 on GitHub');
$ok(rivetAgentBuildCheckVersion('0.9.1-rc1', [['version' => '0.9.1']]) !== null, '0.9.1-rc1 is lower than 0.9.1');

// ================================================================ 2. the token
$ok(!rivetAgentBuildHasToken($db) && rivetAgentBuildToken($db) === '', 'no token at first');
foreach (['', 'hello', 'ghp_short', 'github_pat_' . str_repeat('a', 10), $TOKEN . ' x', "$TOKEN\n; DROP TABLE x", str_repeat('a', 400), 'Bearer ' . $TOKEN] as $bt) {
    $ok(rivetAgentBuildSaveToken($db, $bt, 1) !== null && !rivetAgentBuildHasToken($db), 'a malformed token ' . json_encode(substr($bt, 0, 14)) . ' is refused');
}
$ok(rivetAgentBuildSaveToken($db, "  $TOKEN \n", 1) === null && rivetAgentBuildHasToken($db), 'a good token is saved (surrounding space trimmed)');
$stored = (string) $one('SELECT gh_token FROM endpoint_agent_build WHERE id = 1');
$ok($stored !== '' && !str_contains($stored, $TOKEN) && !str_contains($stored, 'ABCDEFG') && str_starts_with($stored, 'v3:'), 'the token is stored encrypted in the settings vault (v3 envelope), not in clear (' . substr($stored, 0, 4) . '...)');
$ok(rivetAgentBuildToken($db) === $TOKEN, 'it decrypts to the same token');
$ok(decryptSetting($stored, 'generic') === '', 'it cannot be opened with another context (bound to agent_build.github_token)');
$actReset();
$t = rivetAgentBuildTestToken($db);
$a = $actions();
$ok($t['ok'] && count($a) === 1 && $a[0]['method'] === 'GET' && $a[0]['path'] === '/repos/TheTractorHacker/rivet-core/actions/workflows/endpoint-agent.yml' && $a[0]['auth'] === 'Bearer ' . $TOKEN, 'Test token: one GET of the workflow of the configured repository with the bearer token');
$ok(str_starts_with((string) $row()['token_status'], 'ok:') && !str_contains(json_encode($row()), $TOKEN), 'the test result is stored without the token');
$scn(['token' => 'another']);   // GitHub rejects it
$t = rivetAgentBuildTestToken($db);
$ok(!$t['ok'] && str_contains($t['message'], 'does not accept the token') && !str_contains($t['message'], $TOKEN) && !str_contains($t['message'], 'Bad credentials'), 'Test token with a rejected token: plain message (401)');
$scn(['token' => $TOKEN, 'wf_status' => 403]);
$t = rivetAgentBuildTestToken($db);
$ok(!$t['ok'] && str_contains($t['message'], 'Actions: read and write'), 'Test token 403: says which permission is missing');
$scn(['token' => $TOKEN, 'wf_status' => 404]);
$t = rivetAgentBuildTestToken($db);
$ok(!$t['ok'] && str_contains($t['message'], 'cannot find'), 'Test token 404: says the repository / workflow cannot be found');
$scn(['token' => $TOKEN, 'wf_state' => 'disabled_manually']);
$t = rivetAgentBuildTestToken($db);
$ok(!$t['ok'] && str_contains($t['message'], 'switched off'), 'Test token: a disabled workflow is reported');
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=http://127.0.0.1:1');
$t = rivetAgentBuildTestToken($db);
$ok(!$t['ok'] && str_contains($t['message'], 'could not reach GitHub'), 'Test token while GitHub is unreachable: says so (no exception)');
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=' . $origin);
$scn(['token' => $TOKEN]);

// ================================================================ 3. SSRF and token confinement in the HTTP client
$actReset();
$g = rivetAgentFeedGet('https://evil.example.test/repos/a/b', ['role' => 'api', 'token' => $TOKEN]);
$ok(!$g['ok'] && $g['status'] === 0 && $reqs() === 0, 'a token call to another host is refused before any connection');
$g = rivetAgentFeedGet('https://github.com/repos/a/b', ['role' => 'api', 'token' => $TOKEN, 'method' => 'POST', 'json' => ['x' => 1]]);
$ok(!$g['ok'] && $g['status'] === 0, 'a token POST to github.com (not api.github.com) is refused');
$g = rivetAgentFeedGet('https://10.0.0.1/repos/a/b', ['role' => 'api', 'token' => $TOKEN, 'method' => 'POST']);
$ok(!$g['ok'] && $g['status'] === 0, 'a token POST to a private address is refused');
$g = rivetAgentFeedGet($origin . '/repos/o/r/actions/workflows/endpoint-agent.yml/dispatches', ['role' => 'asset', 'token' => $TOKEN, 'method' => 'POST', 'json' => ['x' => 1]]);
$a = $actions();
$ok(!isset($a[0]) || ($a[0]['auth'] === '' && $a[0]['method'] === 'GET'), 'the asset role never carries a token or a POST body');
$actReset();
$scn(['token' => $TOKEN, 'dispatch_redirect' => 'https://evil.example.test/steal']);
$unforce();
$g = rivetAgentFeedGet($origin . '/repos/TheTractorHacker/rivet-core/actions/workflows/endpoint-agent.yml/dispatches', ['role' => 'api', 'token' => $TOKEN, 'method' => 'POST', 'json' => ['ref' => 'main']]);
$ok(!$g['ok'] && str_contains($g['error'], 'redirect') && count($actions()) === 1, 'a redirect of a token call is refused and not followed (one request only)');
$scn(['token' => $TOKEN]);

// ================================================================ 4. start a build
$resetBuild();
$actReset();
$s = rivetAgentBuildStart($db, '0.9.2', null, 1);
$a = array_values(array_filter($actions(), fn ($x) => str_ends_with($x['path'], '/dispatches')));
$ok($s['ok'] && count($a) === 1 && $a[0]['method'] === 'POST' && $a[0]['path'] === '/repos/TheTractorHacker/rivet-core/actions/workflows/endpoint-agent.yml/dispatches' && $a[0]['auth'] === 'Bearer ' . $TOKEN
    && json_decode($a[0]['body'], true) === ['ref' => 'main', 'inputs' => ['version' => '0.9.2']] && str_contains($a[0]['ctype'], 'application/json'), 'Build: POST to the dispatches URL of the configured repository, {ref:main, inputs:{version}}, bearer token');
$r = $row();
$ok($r['run_state'] === 'queued' && $r['build_version'] === '0.9.2' && $r['build_repo'] === 'TheTractorHacker/rivet-core' && (int) $r['requested_by'] === 1 && (int) $r['run_id'] === 0, 'the build is recorded as queued with its version, repository and user');
$actReset();
$s2 = rivetAgentBuildStart($db, '0.9.3', null, 1);
$ok(!$s2['ok'] && str_contains($s2['message'], 'already running') && count($actions()) === 0, 'a second build while one is active is refused without calling GitHub');
foreach ([[403, 'no permission'], [404, 'cannot find'], [422, 'did not accept the request'], [500, 'HTTP 500']] as [$code, $needle]) {
    $resetBuild(); $q("UPDATE endpoint_agent_build SET run_state='failed'");
    $scn(['token' => $TOKEN, 'dispatch_status' => $code]);
    $s = rivetAgentBuildStart($db, '0.9.2', null, 1);
    $ok(!$s['ok'] && str_contains($s['message'], $needle) && !str_contains($s['message'], '<script>') && !str_contains($s['message'], 'ghp_') && $row()['run_state'] === 'failed', "dispatch answered $code: plain message, GitHub's own text not echoed, state failed");
}
$scn(['token' => $TOKEN]);
$resetBuild();
foreach (['0.9.1', '0.8.0', 'x', '0.9.2; id'] as $bv) {
    $actReset();
    $s = rivetAgentBuildStart($db, $bv, null, 1);
    $ok(!$s['ok'] && count(array_filter($actions(), fn ($x) => str_ends_with($x['path'], '/dispatches'))) === 0 && $row()['run_state'] === '', 'start with ' . json_encode($bv) . ' is refused before GitHub is asked to build');
}
$q("UPDATE endpoint_agent_build SET gh_token=''");
$s = rivetAgentBuildStart($db, '0.9.2', null, 1);
$ok(!$s['ok'] && str_contains($s['message'], 'No GitHub token'), 'without a token the server refuses to start a build');
rivetAgentBuildSaveToken($db, $TOKEN, 1);
// the release is created by the workflow between the dispatch and our refresh: Start reads the list again
$resetBuild();
$setReleases([$r092, $r091, $r090]);
$unforce();
$s = rivetAgentBuildStart($db, '0.9.2', null, 1);
$ok(!$s['ok'] && str_contains($s['message'], 'already exists'), 'a version that was released a minute ago is noticed (the list is read again before dispatching)');
$setReleases([$r091, $r090]);
$unforce(); $refresh();

// ================================================================ 5. follow the run
$resetBuild();
$scn(['token' => $TOKEN]);
rivetAgentBuildStart($db, '0.9.2', null, 1);
$t0 = time();
$run = fn (string $status, ?string $concl, int $id = 4242, int $age = 0) => ['id' => $id, 'status' => $status, 'conclusion' => $concl, 'created_at' => gmdate('Y-m-d\TH:i:s\Z', time() - $age), 'html_url' => 'https://evil.example.test/steal'];
$poll = function () use ($db, $q) { $q('UPDATE endpoint_agent_build SET checked_at = NULL'); return rivetAgentBuildPoll($db, true); };
$scn(['token' => $TOKEN, 'runs' => []]);
$p = $poll();
$ok($p['state'] === 'queued' && (int) $row()['run_id'] === 0, 'no run listed yet: still queued');
$scn(['token' => $TOKEN, 'runs' => [$run('completed', 'success', 111, 4000)]]);   // an older run from before the request
$p = $poll();
$ok($p['state'] === 'queued' && (int) $row()['run_id'] === 0, 'a run created before the request is not taken for this build');
$scn(['token' => $TOKEN, 'runs' => [$run('queued', null)]]);
$p = $poll();
$ok($p['state'] === 'queued' && (int) $row()['run_id'] === 4242 && $p['changed'], 'a queued run is found and its number kept');
$actReset();
$p = rivetAgentBuildPoll($db, false);
$ok(!$p['changed'] && $reqs() === 0, 'polling again within eight seconds does not call GitHub');
$scn(['token' => $TOKEN, 'runs' => [$run('in_progress', null)]]);
$actReset();
$p = $poll();
$a = $actions();
$ok($p['state'] === 'building' && count($a) === 1 && $a[0]['path'] === '/repos/TheTractorHacker/rivet-core/actions/runs/4242', 'building: once the run number is known GET /actions/runs/{id} is used');
$url = rivetAgentBuildRunUrl($row());
$ok($url === 'https://github.com/TheTractorHacker/rivet-core/actions/runs/4242' && !str_contains($url, 'evil'), 'the link to the run is built here from repo and number, GitHub\'s html_url is ignored');
$scn(['token' => $TOKEN, 'runs' => [$run('completed', 'failure')]]);
$p = $poll();
$ok($p['state'] === 'failed' && $row()['run_state'] === 'failed' && str_contains($row()['run_note'], 'did not succeed') && $row()['finished_at'] !== null, 'a failed run is shown as failed with a pointer to the run');
$s = rivetAgentBuildStart($db, '0.9.2', null, 1);
$ok($s['ok'], 'after a failed build the same version can be built again');
$scn(['token' => $TOKEN, 'runs' => [$run('completed', 'success', 5151)]]);
$p = $poll();
$ok($p['state'] === 'finished' && (int) $row()['run_id'] === 5151, 'a successful run is shown as finished');
$resetBuild();
$scn(['token' => $TOKEN, 'runs' => [$run('completed', "cancel<b>led\n", 6)]]);
rivetAgentBuildStart($db, '0.9.2', null, 1);
$p = $poll();
$ok($p['state'] === 'failed' && !str_contains($row()['run_note'], '<') && !str_contains($row()['run_note'], "\n"), 'a hostile conclusion text cannot reach the page');
// no run ever appears
$resetBuild(); $scn(['token' => $TOKEN, 'runs' => []]);
rivetAgentBuildStart($db, '0.9.2', null, 1);
$q("UPDATE endpoint_agent_build SET requested_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE");
$p = $poll();
$ok($p['state'] === 'failed' && str_contains($row()['run_note'], 'did not start a run'), 'GitHub never starts a run: failed after 15 minutes');
$resetBuild(); $scn(['token' => $TOKEN, 'runs' => [$run('in_progress', null, 77)]]);
rivetAgentBuildStart($db, '0.9.2', null, 1);
$q("UPDATE endpoint_agent_build SET requested_at = UTC_TIMESTAMP() - INTERVAL 4 HOUR, run_state = 'building'");
$p = $poll();
$ok($p['state'] === 'failed' && str_contains($row()['run_note'], 'three hours'), 'a build never seen to finish is shown as failed after three hours (and no longer blocks a new one)');
$resetBuild(); rivetAgentBuildStart($db, '0.9.2', null, 1);
$q("UPDATE endpoint_agent_build SET requested_at = UTC_TIMESTAMP() - INTERVAL 7 HOUR");
$ok(!rivetAgentBuildIsActive($row()), 'an old claim is not active any more');
$scn(['token' => $TOKEN, 'runs_status' => 500, 'runs' => [$run('queued', null)]]);
$resetBuild(); rivetAgentBuildStart($db, '0.9.2', null, 1);
$p = $poll();
$ok($p['state'] === 'queued' && str_contains($row()['run_note'], 'Trying again'), 'GitHub trouble while polling keeps the state and says it will try again');
$scn(['token' => $TOKEN]);

// ================================================================ 6. publish when built
$wipeBins = function () use ($q, $db, $rmm) { foreach ($db->query('SELECT storage_name FROM endpoint_agent_binaries') ?: [] as $r) { @unlink($rmm->binaryStore()->storageDir() . '/' . $r['storage_name']); } $q('DELETE FROM endpoint_agent_binaries'); $q('DELETE FROM endpoint_agent_msi_templates'); $q('DELETE FROM endpoint_agent_releases'); $q('DELETE FROM endpoint_agent_linux_assets'); };
$wipeBins();
$resetBuild(); $setReleases([$r091, $r090]); $unforce(); $refresh();
$auto = rivetAgentFeedOptions(['ring' => 'pilot', 'rollout_pct' => 25, 'activate' => true, 'offer' => true]);
$scn(['token' => $TOKEN, 'runs' => []]);
$ok(rivetAgentBuildStart($db, '0.9.2', $auto, 1)['ok'] && $row()['auto_state'] === 'pending' && (int) $row()['auto_publish'] === 1, 'Build with "publish automatically": recorded as pending with its options');
$scn(['token' => $TOKEN, 'runs' => [$run('queued', null, 9001)]]);
$tick = function () use ($db, $rmm, $q, $unforce) { $q('UPDATE endpoint_agent_build SET checked_at = NULL'); $unforce(); return rivetAgentBuildTick($db, $rmm, true); };
$tk = $tick();
$ok($tk['state'] === 'queued' && $row()['auto_state'] === 'pending' && rivetAgentBuildRelease0() === 0, 'while the build runs nothing is published');
function rivetAgentBuildRelease0(): int { global $db; return (int) $db->query('SELECT COUNT(*) FROM endpoint_agent_binaries')->fetch_row()[0]; }
$scn(['token' => $TOKEN, 'runs' => [$run('completed', 'success', 9001)]]);
$tk = $tick();   // finished, but the release is not on GitHub's list yet
$ok($tk['state'] === 'finished' && $row()['auto_state'] === 'pending' && rivetAgentBuildRelease0() === 0 && str_contains($tk['message'], 'Waiting for the release'), 'finished but the release is not listed yet: waits, publishes nothing');
$setReleases([$r092, $r091, $r090]);
$a1 = $audit('Agent Build Auto Publish');
$tk = $tick();
$rr = $rows('SELECT * FROM endpoint_agent_releases');
$ok($row()['auto_state'] === 'done' && rivetAgentBuildRelease0() === 1 && count($rr) === 1 && $rr[0]['ring'] === 'pilot' && (int) $rr[0]['rollout_pct'] === 25 && $audit('Agent Build Auto Publish') === $a1 + 1, 'the release appears: published automatically with the chosen ring and rollout, audited');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%agent-v0.9.2%' AND log_type = 'Endpoint Agent'") >= 1, 'the publish itself wrote its own audit rows');
$tk = $tick();
$ok(rivetAgentBuildRelease0() === 1 && $audit('Agent Build Auto Publish') === $a1 + 1, 'a second look publishes nothing again');
// without the tick: no auto publish when not asked
$wipeBins(); $resetBuild(); $scn(['token' => $TOKEN, 'runs' => []]); $setReleases([$r091, $r090]); $unforce(); $refresh();
rivetAgentBuildStart($db, '0.9.2', null, 1);
$scn(['token' => $TOKEN, 'runs' => [$run('completed', 'success', 9002)]]); $setReleases([$r092, $r091, $r090]);
$tk = $tick();
$ok($row()['run_state'] === 'finished' && rivetAgentBuildRelease0() === 0 && count($rels()) === 3, 'without the tick box the release only appears in the list (the list is refreshed), nothing is published');
// the release never appears
$wipeBins(); $resetBuild(); $scn(['token' => $TOKEN, 'runs' => []]); $setReleases([$r091, $r090]); $unforce(); $refresh();
rivetAgentBuildStart($db, '0.9.2', $auto, 1);
$scn(['token' => $TOKEN, 'runs' => [$run('completed', 'success', 9003)]]);
$tick();
$q("UPDATE endpoint_agent_build SET finished_at = UTC_TIMESTAMP() - INTERVAL 40 MINUTE");
$tick();
$ok($row()['auto_state'] === 'failed' && str_contains($row()['auto_note'], 'did not appear') && rivetAgentBuildRelease0() === 0, 'a release that never shows up: automatic publish gives up with a clear note');
// the user who started it is gone
$wipeBins(); $resetBuild(); $setReleases([$r092, $r091, $r090]); $unforce(); $refresh();
rivetAgentBuildStart($db, '0.9.3', $auto, 999999);
$q("UPDATE endpoint_agent_build SET build_version='0.9.2', run_state='finished', finished_at=UTC_TIMESTAMP(), auto_state='pending'");
$tick();
$ok($row()['auto_state'] === 'failed' && rivetAgentBuildRelease0() === 0, 'if the user who started the build no longer exists nothing is published');
// the user is not an administrator any more: RmmAdmin refuses
$wipeBins(); $resetBuild();
$q("UPDATE endpoint_agent_build SET build_version='0.9.2', build_repo='TheTractorHacker/rivet-core', requested_at=UTC_TIMESTAMP(), requested_by=12, run_state='finished', finished_at=UTC_TIMESTAMP(), auto_publish=1, auto_opts='" . $esc(json_encode($auto)) . "', auto_state='pending'");
$tick();
$ok($row()['auto_state'] === 'failed' && rivetAgentBuildRelease0() === 0, 'a user without the publish permission cannot have it published for them (RmmAdmin refuses; viewer test user)');
$resetBuild(); $wipeBins();


// ================================================================ 7. the HTTP handler and the card
$refPage = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$adm = fn (array $f, string $who = 'admin', array $h = [], bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $S_[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), array_merge($refPage, $h));
$jsn = ['Accept: application/json'];
$jd = fn (string $b) => json_decode($b, true) ?: [];
$cardOf = function (string $html): string { $a = strpos($html, 'id="agent-updates"'); $b = $a === false ? false : strpos($html, 'id="binaries"', $a); return $a === false ? '' : substr($html, $a, $b === false ? 60000 : $b - $a); };
$wipeBins(); $resetBuild(); $q("UPDATE endpoint_agent_build SET gh_token='', token_status=''");
$setReleases([$r091, $r090]); $scn(['token' => $TOKEN]); $unforce(); $refresh();

// no token: the fallback
[$c, $page] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$card = $cardOf($page);
$ok($c === 200 && str_contains($card, 'id="af-build"') && str_contains($card, 'Build a new version') && str_contains($card, 'href="https://github.com/TheTractorHacker/rivet-core/actions/workflows/endpoint-agent.yml"') && str_contains($card, 'Start the build on GitHub')
    && str_contains($card, 'rel="noopener noreferrer"') && str_contains($card, 'target="_blank"'), 'no token: the card offers "Start the build on GitHub" with the exact workflow address');
$ok(str_contains($card, 'Run workflow') && str_contains($card, 'type the version') && str_contains($card, 'Check for updates') && preg_match('~<ol class="mb-2 ps-3"><li>.*?</li><li>.*?</li><li>.*?</li></ol>~s', $card) === 1 && str_contains($card, '<code>0.9.2</code>'), 'no token: three plain steps and the suggested version');
$ok(!str_contains($card, 'id="af-build-go"') && str_contains($card, 'How to create the token') && str_contains($card, 'Actions</strong> to <strong>Read and write') && str_contains($card, 'Only select repositories') && str_contains($card, 'type="password"'), 'no token: no Build button, but the guided token help with the exact permission');
$ok(!preg_match('/\son[a-z]+\s*=\s*["\']|javascript:/i', $card), 'the build box has no inline handlers');
// CSRF
foreach (['build_save_token' => ['gh_token' => $TOKEN], 'build_start' => ['version' => '0.9.2'], 'build_test_token' => [], 'build_clear_token' => [], 'build_status' => []] as $act => $extra) {
    [$c, $b] = $adm(['agent_feed' => $act] + $extra, 'admin', $jsn, false);
    $ok($c === 403 && ($jd($b)['success'] ?? null) === false, "$act without a CSRF token: 403");
    [$c, $b] = web($wb, 'POST', '/admin/post.php', $S_['admin'], ['agent_feed' => $act, 'csrf_token' => 'wrong'] + $extra, array_merge($refPage, $jsn));
    $ok($c === 403 && !rivetAgentBuildHasToken($db), "$act with a wrong CSRF token: 403, nothing saved");
}
// not an administrator
$actReset();
foreach (['tech', 'viewer', 'moduleonly', 'normo', 'deptb'] as $who) {
    [$c, $b] = $adm(['agent_feed' => 'build_save_token', 'gh_token' => $TOKEN], $who, $jsn);
    $ok(!rivetAgentBuildHasToken($db) && ($jd($b)['success'] ?? null) !== true, "$who: cannot save a token (status $c)");
}
rivetAgentBuildSaveToken($db, $TOKEN, 1);
foreach (['tech', 'viewer', 'moduleonly', 'normo', 'deptb'] as $who) {
    foreach (['build_start' => ['version' => '0.9.2'], 'build_test_token' => [], 'build_clear_token' => [], 'build_status' => []] as $act => $extra) {
        [$c, $b] = $adm(['agent_feed' => $act] + $extra, $who, $jsn);
        $ok(($jd($b)['success'] ?? null) !== true && rivetAgentBuildHasToken($db) && $actions() === [] && $row()['run_state'] === '', "$who: $act is refused (status $c), GitHub is not contacted");
    }
}
$q("UPDATE endpoint_agent_build SET gh_token=''");
// admin: save, never shown back
$au = $audit('Agent Build Token Saved');
foreach (['short', 'github_pat_<script>alert(1)</script>' . str_repeat('a', 30), ''] as $bt) {
    [$c, $b] = $adm(['agent_feed' => 'build_save_token', 'gh_token' => $bt], 'admin', $jsn);
    $ok($c === 422 && !rivetAgentBuildHasToken($db) && !str_contains($b, '<script>alert'), 'a malformed token through the handler is refused (422): ' . json_encode(substr($bt, 0, 12)));
}
[$c, $b] = $adm(['agent_feed' => 'build_save_token', 'gh_token' => ['x' => 'y']], 'admin', $jsn);
$ok($c === 422 && !rivetAgentBuildHasToken($db), 'a token sent as an array is refused');
[$c, $b] = $adm(['agent_feed' => 'build_save_token', 'gh_token' => $TOKEN], 'admin', $jsn);
$j = $jd($b);
$ok($c === 200 && ($j['success'] ?? false) && rivetAgentBuildHasToken($db) && !str_contains($b, $TOKEN) && !str_contains($b, substr($TOKEN, 12, 20)) && $audit('Agent Build Token Saved') === $au + 1, 'admin: the token is saved; the answer (JSON and the new card) does not contain it; audited');
$ok(str_contains((string) ($j['html'] ?? ''), 'id="af-build-go"') && str_contains($j['html'], 'name="version" value="0.9.2"') && !str_contains($j['html'], 'Start the build on GitHub'), 'with a token the card shows the Build button, the version box filled with 0.9.2 and no fallback');
[$c, $page] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$ok(!str_contains($page, $TOKEN) && !str_contains($page, substr($TOKEN, 12, 20)) && !str_contains($page, (string) $one('SELECT gh_token FROM endpoint_agent_build WHERE id = 1')) && preg_match('~name="gh_token"[^>]*value=""~', $page) === 1, 'the page never contains the token, its encrypted form, or a value in the token field');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%" . $esc(substr($TOKEN, 12, 20)) . "%'") === 0, 'no audit row contains the token');
[$c, $b] = $adm(['agent_feed' => 'build_test_token'], 'admin', $jsn);
$j = $jd($b);
$ok($c === 200 && ($j['success'] ?? false) && str_contains($j['message'], 'The token works') && !str_contains($b, $TOKEN), 'Test token through the handler');
// start through the handler
$actReset();
foreach (['0.9.1', '0.8.0', 'abc', "0.9.5\n; id", str_repeat('9', 300), '0.9.2-<script>'] as $bv) {
    [$c, $b] = $adm(['agent_feed' => 'build_start', 'version' => $bv], 'admin', $jsn);
    $ok($c === 422 && ($jd($b)['success'] ?? null) === false && !str_contains($b, '<script>') && array_filter($actions(), fn ($x) => str_ends_with($x['path'], '/dispatches')) === [], 'handler: version ' . json_encode(substr($bv, 0, 16)) . ' refused, nothing dispatched');
}
[$c, $b] = $adm(['agent_feed' => 'build_start', 'version' => ['0.9.2']], 'admin', $jsn);
$ok($c === 422, 'handler: a version sent as an array is refused');
$ab = $audit('Agent Build Started');
[$c, $b] = $adm(['agent_feed' => 'build_start', 'version' => '0.9.2', 'build_auto' => '1', 'ring' => 'gold', 'rollout_pct' => '9999', 'offer' => '1', 'activate' => '1'], 'admin', $jsn);
$j = $jd($b);
$ao = json_decode((string) $row()['auto_opts'], true);
$ok($c === 200 && ($j['success'] ?? false) && $row()['run_state'] === 'queued' && $audit('Agent Build Started') === $ab + 1 && is_array($ao) && $ao['ring'] === null && $ao['pct'] === 100 && str_contains((string) $j['html'], 'badge'), 'handler: build started, audited; hostile ring/rollout are normalised before they are stored');
[$c, $b] = $adm(['agent_feed' => 'build_start', 'version' => '0.9.3'], 'admin', $jsn);
$ok($c === 422 && str_contains($b, 'already running'), 'handler: a second build while one runs is refused');
$card = $cardOf((string) ($j['html'] ?? ''));
$ok(str_contains($card, 'data-active="1"') && str_contains($card, 'af-build-badge') && str_contains($card, ' disabled'), 'the card of a running build is marked active (the script polls) and the Build button is disabled');
// status
$scn(['token' => $TOKEN, 'runs' => [$run('in_progress', null, 31337)]]); $q('UPDATE endpoint_agent_build SET checked_at = NULL');
[$c, $b] = $adm(['agent_feed' => 'build_status'], 'admin', $jsn);
$j = $jd($b);
$ok($c === 200 && $j['state'] === 'building' && $j['active'] === true && $j['url'] === 'https://github.com/TheTractorHacker/rivet-core/actions/runs/31337' && ($j['changed'] ?? false) && str_contains((string) $j['html'], 'Building'), 'status: building, the run link, and the new card when something changed');
[$c, $b] = $adm(['agent_feed' => 'build_status'], 'admin', $jsn);
$ok($jd($b)['html'] === '' && $jd($b)['changed'] === false, 'status again: nothing changed, no card sent');
$setReleases([$r092, $r091, $r090]); $scn(['token' => $TOKEN, 'runs' => [$run('completed', 'success', 31337)]]); $q('UPDATE endpoint_agent_build SET checked_at = NULL'); $unforce();
[$c, $b] = $adm(['agent_feed' => 'build_status'], 'admin', $jsn);
$j = $jd($b);
$ok($j['state'] === 'finished' && $j['active'] === false && str_contains((string) $j['html'], 'data-tag="agent-v0.9.2"') && str_contains($j['html'], 'Publish'), 'status: finished, the new release is in the list with its Publish button');
// hostile text from the database is escaped
$q("UPDATE endpoint_agent_build SET run_note = '<img src=x onerror=alert(1)>', auto_note = '<script>alert(2)</script>', auto_state='failed', token_status = '<b>x</b>'");
[, $page] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$card = $cardOf($page);
$ok(!str_contains($card, '<img src=x') && !str_contains($card, '<script>alert(2)') && !str_contains($card, '<b>x</b>') && str_contains($card, '&lt;img src=x onerror=alert(1)&gt;'), 'run notes and test results are escaped in the card');
// removing the token and the repository
$ar = $audit('Agent Build Token Removed');
[$c, $b] = $adm(['agent_feed' => 'build_clear_token'], 'admin', $jsn);
$ok($c === 200 && !rivetAgentBuildHasToken($db) && $audit('Agent Build Token Removed') === $ar + 1 && (string) $one('SELECT gh_token FROM endpoint_agent_build') === '', 'Remove token: gone from the database, audited');
rivetAgentBuildSaveToken($db, $TOKEN, 1);
[$c, $b] = $adm(['agent_feed' => 'save_repo', 'repo' => 'someone/fork', 'confirm_repo' => '1'], 'admin', $jsn);
$ok($c === 200 && !rivetAgentBuildHasToken($db) && str_contains($b, 'token was removed'), 'changing the repository removes the saved token');
$adm(['agent_feed' => 'save_repo', 'repo' => RIVET_AGENT_FEED_DEFAULT_REPO], 'admin', $jsn);
$ok(rivetAgentFeedRepo($db) === RIVET_AGENT_FEED_DEFAULT_REPO, 'repository set back');
// a different repository: the dispatch goes to THAT repository's path, still the same API host
rivetAgentBuildSaveToken($db, $TOKEN, 1); $resetBuild(); $actReset();
$adm(['agent_feed' => 'save_repo', 'repo' => 'someone/fork', 'confirm_repo' => '1'], 'admin', $jsn);
rivetAgentBuildSaveToken($db, $TOKEN, 1); $unforce();
$setReleases([]); $refresh();
$s = rivetAgentBuildStart($db, '1.0.0', null, 1);
$a = array_values(array_filter($actions(), fn ($x) => str_ends_with($x['path'], '/dispatches')));
$ok($s['ok'] && $a[0]['path'] === '/repos/someone/fork/actions/workflows/endpoint-agent.yml/dispatches', 'with a configured fork the dispatch uses the fork\'s own path (never a path from the browser)');
$adm(['agent_feed' => 'save_repo', 'repo' => RIVET_AGENT_FEED_DEFAULT_REPO], 'admin', $jsn);
$resetBuild(); $setReleases([$r091, $r090]); $unforce(); $refresh();
// the plain post (no JavaScript)
rivetAgentBuildSaveToken($db, $TOKEN, 1);
$ref = web($wb, 'POST', '/admin/post.php', $S_['admin'], ['agent_feed' => 'build_start', 'version' => '0.9.2', 'csrf_token' => 'csrftok1'], $refPage);
$ok($ref[0] === 302 && $row()['run_state'] === 'queued', 'the plain form post (no JavaScript) starts the build and redirects');
[, $page] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$ok(str_contains(str_replace('\\/', '/', $page), 'GitHub accepted the request') , 'the flash message says what happened');
// the cron script
$resetBuild(); $actReset();
$cron = fn () => shell_exec('cd ' . escapeshellarg($root) . ' && RIVET_AGENT_FEED_TEST_ORIGIN=' . escapeshellarg($origin) . ' ' . escapeshellarg(PHP_BINARY) . ' cron/agent_build_check.php 2>&1');
$out = (string) $cron();
$ok(trim($out) === '' && $actions() === [], 'cron: idle (no build) prints nothing and makes no request');
$scn(['token' => $TOKEN, 'runs' => []]);
rivetAgentBuildStart($db, '0.9.2', $auto, 1);
$scn(['token' => $TOKEN, 'runs' => [$run('completed', 'success', 777)]]); $setReleases([$r092, $r091, $r090]); $unforce(); $wipeBins(); $q('UPDATE endpoint_agent_build SET checked_at = NULL');
$out = (string) $cron();
$ok(str_contains($out, 'agent build 0.9.2: finished') && $row()['auto_state'] === 'done' && rivetAgentBuildRelease0() === 1, 'cron: follows the run, sees it finished and publishes it automatically (' . trim($out) . ')');
$out = (string) $cron();
$ok(trim($out) === '', 'cron: a build that is done prints nothing the next time');
// new code, old schema
$wipeBins(); $resetBuild();
$q('RENAME TABLE endpoint_agent_build TO endpoint_agent_build_off');
[$c, $page] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$ok($c === 200 && str_contains($page, 'id="agent-updates"') && str_contains($page, 'one more small table') && str_contains($page, 'id="binaries"'), 'new code, old schema: the page loads and the box says to run the database update');
[$c, $b] = $adm(['agent_feed' => 'build_start', 'version' => '0.9.2'], 'admin', $jsn);
$ok(($jd($b)['success'] ?? null) === false && str_contains($b, 'database update'), 'new code, old schema: Build says to run the update (no exception)');
[$c, $b] = $adm(['agent_feed' => 'build_save_token', 'gh_token' => $TOKEN], 'admin', $jsn);
$ok(($jd($b)['success'] ?? null) === false && str_contains($b, 'database update'), 'new code, old schema: saving a token says to run the update');
$q('RENAME TABLE endpoint_agent_build_off TO endpoint_agent_build');
$q("UPDATE endpoint_agent_build SET gh_token=''");

echo "\nbuild suite done\n";
