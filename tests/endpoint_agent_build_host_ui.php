<?php
/*
 * Agent updates > "Build on my build host" (RivetIT 26.10.57): the page section and the bh_* handler actions over HTTP with forged sessions. CSRF, roles, hostile input, IDOR-style ids, the
 * token shown once, no secret in any later page. Scratch DB only.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=..scratch.. RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... EA_TEST_NON_WINDOWS=1 RMM_TEST_STATE_DIR=none RMM_GATE_STATE_DIR=/nonexistent BH_REDIS_PORT=6731 php tests/endpoint_agent_build_host_ui.php
 */
putenv('RIVETIT_REDIS_PORT=' . (getenv('BH_REDIS_PORT') ?: '6379'));
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_build_host_ui.php";

shell_exec('redis-cli -p ' . (int) (getenv('BH_REDIS_PORT') ?: 6379) . ' flushall 2>&1');   // the host suite ends by exhausting the failed-authentication limiter
ea_reset();
$U = ea_seed_users();
foreach (['endpoint_agent_binaries', 'endpoint_agent_msi_templates', 'endpoint_agent_releases', 'endpoint_agent_build_chunks', 'endpoint_agent_build_files', 'endpoint_agent_build_jobs', 'endpoint_agent_build_hosts'] as $t) { $q("DELETE FROM $t"); }
$sdir = sys_get_temp_dir() . '/ea_bhui_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$srv = ea_start_php("$root/tests/mobile_api_router.php", ['RIVETIT_REDIS_PORT' => getenv('BH_REDIS_PORT') ?: '6379', 'RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir", 'post_max_size=32M', 'memory_limit=512M']);
$wb = 'http://127.0.0.1:' . $srv['port'];
\ITFlow\EndpointAgent\Config::enable();
\ITFlow\EndpointAgent\Config::set(['service_url' => $wb]);
$S = []; foreach ([1 => 'admin', 10 => 'tech', 12 => 'viewer', 14 => 'moduleonly', 15 => 'normo', 16 => 'deptb'] as $uid => $n) { $S[$n] = ea_forge_session($sdir, $uid); }
$ref = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$jsn = ['Accept: application/json'];
$jd = fn (string $b) => json_decode($b, true) ?: [];
$adm = fn (array $f, string $who = 'admin', array $h = [], bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $S[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), array_merge($ref, $h));
$page = fn (string $who = 'admin') => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S[$who]);
$cnt = fn (string $t) => (int) $one("SELECT COUNT(*) FROM $t");
$audit = fn (string $a) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = '" . $esc($a) . "'");

echo "== the page\n";
[$c, $html] = $page();
$a = strpos($html, 'id="af-build"'); $card = $a === false ? '' : substr($html, $a, 80000);
$ok($c === 200 && str_contains($card, 'id="af-prov-gh"') && str_contains($card, 'id="af-prov-host"') && str_contains($card, 'Build on GitHub') && str_contains($card, 'Build on my build host'), 'the card offers the provider choice (GitHub | build host)');
$ok(str_contains($card, 'id="af-build-local"') && str_contains($card, 'Add build host') && str_contains($card, 'No build host yet') && !str_contains($card, 'id="af-bh-go"'), 'no host yet: the Local build section offers Add build host and no Build button');
$ok(str_contains($card, 'build-host guide') === false && str_contains($card, 'rivet-core/blob/main/docs/rmm/BUILD_HOST.md'), 'it links the build host guide from Core');
$ok(!preg_match('/\son[a-z]+\s*=\s*["\']|javascript:/i', $card), 'no inline handlers in the section');

echo "== CSRF and roles\n";
$acts = ['bh_add' => ['name' => 'Lab'], 'bh_request' => ['host_id' => '1', 'version' => '0.9.1'], 'bh_rotate' => ['host_id' => '1'], 'bh_disable' => ['host_id' => '1'], 'bh_enable' => ['host_id' => '1'], 'bh_delete' => ['host_id' => '1'], 'bh_cancel' => ['job_id' => '1'], 'bh_publish' => ['job_id' => '1'], 'bh_status' => []];
foreach ($acts as $act => $extra) {
    [$c, $b] = $adm(['agent_feed' => $act] + $extra, 'admin', $jsn, false);
    $ok($c === 403 && ($jd($b)['success'] ?? null) === false, "$act without a CSRF token: 403");
    [$c, $b] = web($wb, 'POST', '/admin/post.php', $S['admin'], ['agent_feed' => $act, 'csrf_token' => 'wrong'] + $extra, array_merge($ref, $jsn));
    $ok($c === 403, "$act with a wrong CSRF token: 403");
}
$ok($cnt('endpoint_agent_build_hosts') === 0, 'nothing was created by the refused requests');
foreach (['tech', 'viewer', 'moduleonly', 'normo', 'deptb'] as $who) {
    [$c, $b] = $adm(['agent_feed' => 'bh_add', 'name' => 'Intruder'], $who, $jsn);
    $ok($cnt('endpoint_agent_build_hosts') === 0 && ($jd($b)['success'] ?? null) !== true && !str_contains($b, 'rbh_'), "$who: cannot add a build host (status $c)");
}
[$c, $b] = web($wb, 'POST', '/admin/post.php', 'nosession', ['agent_feed' => 'bh_add', 'name' => 'x', 'csrf_token' => 'csrftok1'], array_merge($ref, $jsn));
$ok($cnt('endpoint_agent_build_hosts') === 0 && ($jd($b)['success'] ?? null) !== true, 'no session: nothing happens');

echo "== add a host: the token is shown once\n";
$au = $audit('Agent Build Host Added');
foreach (['', '<img src=x onerror=alert(1)>', str_repeat('a', 200)] as $n) {
    [$c, $b] = $adm(['agent_feed' => 'bh_add', 'name' => $n], 'admin', $jsn); $ok($c === 422 && $cnt('endpoint_agent_build_hosts') === 0, 'a bad name is refused: ' . json_encode(substr($n, 0, 20)));
}
[$c, $b] = $adm(['agent_feed' => 'bh_add', 'name' => 'Lab builder'], 'admin', $jsn);
$j = $jd($b);
$ok($c === 200 && ($j['success'] ?? false) && !empty($j['reveal']) && $cnt('endpoint_agent_build_hosts') === 1 && $audit('Agent Build Host Added') === $au + 1, 'admin adds a build host (audited)');
preg_match('/id="af-bh-token" readonly value="(rbh_[0-9a-f]{16}_[0-9a-f]{48})"/', (string) ($j['html'] ?? ''), $m); $tok = $m[1] ?? '';
$ok($tok !== '' && str_contains($j['html'], 'only once') && str_contains($j['html'], 'TOKEN=' . $tok) && str_contains($j['html'], 'SERVER_URL=http://') && str_contains($j['html'], '/api/v1/build_host') && str_contains($j['html'], 'data-af-copy="af-bh-token"') && !preg_match('/<(?:textarea|input)[^>]*>[^<]*(?:placeholder|YOUR|<token>)/i', $j['html']), 'the answer shows the real token, the real config with the server address and a copy button (no placeholder)');
$ok(str_contains($j['html'], '-m 0600') && str_contains($j['html'], 'build-host.sh --register'), 'with the install command and the register check');
[$c, $html] = $page(); $ok(!str_contains($html, $tok) && !str_contains($html, substr($tok, 21)), 'the next page load does not contain the token');
$ok($cnt("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%" . $esc(substr($tok, 21)) . "%'") === 0 && !str_contains((string) $one('SELECT CONCAT(token_hash, token_salt, token_prefix) FROM endpoint_agent_build_hosts'), substr($tok, 21)), 'the secret is in no audit line and no table');
[$c, $pj] = [0, null];
$ch = curl_init("$wb/api/v1/build_host/poll"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"capabilities":{}}', CURLOPT_HTTPHEADER => ["Authorization: Bearer $tok", 'Content-Type: application/json']]); $pj = json_decode(curl_exec($ch), true); $ok($pj === ['job' => null], 'the token from the page works against the endpoint');
$hid = (int) $one('SELECT host_id FROM endpoint_agent_build_hosts');
[$c, $html] = $page(); $a = strpos($html, 'id="af-bh-hosts"'); $t = substr($html, $a, 6000);
$ok(str_contains($t, 'Lab builder') && str_contains($t, 'Online') && str_contains($t, 'name="agent_feed" value="bh_disable"') && str_contains($t, 'New token') && str_contains($t, 'text-success'), 'the list shows the host, a green dot, Online, and the actions');
$ok(str_contains($html, 'id="af-bh-go"') && str_contains($html, 'name="source_ref"'), 'now the Build form is there');
$name = 'Evil <b>host</b> "q"'; // stored raw by the add action? names are restricted
$ok(rivetBuildHostCreate($db, $name, 1)['ok'] === false, 'a name with markup cannot be stored at all');
$q("UPDATE endpoint_agent_build_hosts SET name = '<script>alert(1)</script>' WHERE host_id = $hid");
[$c, $html] = $page(); $ok(!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'a hostile host name already in the table is escaped on the page');
$q("UPDATE endpoint_agent_build_hosts SET name = 'Lab builder' WHERE host_id = $hid");

echo "== rotate, disable, enable, delete\n";
[$c, $b] = $adm(['agent_feed' => 'bh_rotate', 'host_id' => (string) $hid], 'admin', $jsn); $j = $jd($b);
preg_match('/id="af-bh-token" readonly value="(rbh_[0-9a-f]{16}_[0-9a-f]{48})"/', (string) ($j['html'] ?? ''), $m); $tok2 = $m[1] ?? '';
$ok($c === 200 && $tok2 !== '' && $tok2 !== $tok && $audit('Agent Build Host Token Replaced') === 1, 'New token: a different token, audited');
$ch = curl_init("$wb/api/v1/build_host/poll"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"capabilities":{}}', CURLOPT_HTTPHEADER => ["Authorization: Bearer $tok", 'Content-Type: application/json']]); curl_exec($ch); $ok(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 401, 'the old token stopped working');
[$c, $b] = $adm(['agent_feed' => 'bh_rotate', 'host_id' => '99999'], 'admin', $jsn); $ok($c === 404 && !str_contains($b, 'rbh_'), 'rotating an unknown host: 404 and no token');
[$c, $b] = $adm(['agent_feed' => 'bh_rotate', 'host_id' => "1 OR 1=1"], 'admin', $jsn); $ok($c === 404, 'a hostile host id is just unknown');
[$c, $b] = $adm(['agent_feed' => 'bh_disable', 'host_id' => (string) $hid], 'admin', $jsn); $ok($c === 200 && (int) $one("SELECT enabled FROM endpoint_agent_build_hosts WHERE host_id = $hid") === 0, 'disable');
[$c, $b] = $adm(['agent_feed' => 'bh_enable', 'host_id' => (string) $hid], 'admin', $jsn); $ok($c === 200 && (int) $one("SELECT enabled FROM endpoint_agent_build_hosts WHERE host_id = $hid") === 1, 'enable');

echo "== request, cancel, publish\n";
$base0 = ['agent_feed' => 'bh_request', 'host_id' => (string) $hid];
foreach ([['version' => '1.2'], ['version' => "1.2.3'; DROP TABLE users;--"], ['version' => '1.0.0', 'source_ref' => '../../etc'], ['version' => '1.0.0', 'source_ref' => 'a;b']] as $bad) {
    [$c, $b] = $adm($base0 + $bad, 'admin', $jsn); $ok($c === 422 && $cnt('endpoint_agent_build_jobs') === 0, 'refused: ' . json_encode($bad));
}
[$c, $b] = $adm(['agent_feed' => 'bh_request', 'host_id' => '99999', 'version' => '1.0.0'], 'admin', $jsn); $ok($c === 422 && $cnt('endpoint_agent_build_jobs') === 0, 'an unknown host id is refused');
[$c, $b] = $adm($base0 + ['version' => '1.0.0', 'source_ref' => 'agent-v1.0.0', 'build_auto' => '1', 'ring' => 'stable', 'rollout_pct' => '250', 'activate' => '1', 'offer' => '1'], 'admin', $jsn);
$jb = $rows('SELECT * FROM endpoint_agent_build_jobs')[0] ?? [];
$ok($c === 200 && $jb && $jb['state'] === 'queued' && (int) $jb['auto_publish'] === 1 && $jb['release_ring'] === 'stable' && (int) $jb['rollout_pct'] === 100 && (int) $jb['requested_by'] === 1 && $jb['source_ref'] === 'agent-v1.0.0', 'a build is queued with the chosen ring/rollout (clamped to 100) and the requesting user');
[$c, $b] = $adm($base0 + ['version' => '1.0.1'], 'admin', $jsn); $ok($c === 422, 'a second open build for the same host is refused');
[$c, $html] = $page(); $a = strpos($html, 'id="af-bh-jobs"'); $t = substr($html, $a, 8000);
$ok(str_contains($t, '1.0.0') && str_contains($t, 'Waiting for the build host') && str_contains($t, 'value="bh_cancel"') && str_contains($html, 'id="af-build-local" data-active="1"'), 'the job list shows it with a Cancel button, and the section is marked active for live updates');
$jid = (int) $jb['job_id'];
[$c, $html0] = $page(); $ok(preg_match('~id="af-bh-onserver".*?sudo ' . preg_quote(htmlspecialchars($root, ENT_QUOTES), '~') . '/deploy/setup-build-host\\.sh~s', $html0) === 1 && str_contains($html0, 'root-equivalent'), 'the page shows the one-line setup command for this server');
// ---- build progress (26.10.58): bar, plain-words stage, elapsed time and the checklist
$live = function () use ($adm, $jsn, $jd): string { [, $b] = $adm(['agent_feed' => 'bh_status'], 'admin', $jsn); return (string) ($jd($b)['html'] ?? ''); };
$q("UPDATE endpoint_agent_build_jobs SET state='building', claimed_at = UTC_TIMESTAMP() - INTERVAL 192 SECOND, progress_stage='build_windows_amd64', progress_pct=40, progress_detail='<b>go build</b>', progress_at = UTC_TIMESTAMP() WHERE job_id = $jid");
$t = $live();
$ok(str_contains($t, 'Building the Windows x64 agent') && str_contains($t, 'role="progressbar"') && str_contains($t, 'aria-valuenow="40"') && str_contains($t, 'style="width:40%"') && str_contains($t, '>40%<') && str_contains($t, 'Running for 3m 1') , 'building: plain-words stage, a real bar at 40%, elapsed time');
$ok(substr_count($t, 'data-bh-steps') === 1 && preg_match_all('~fa-check-circle text-success~', $t) === 2 && substr_count($t, 'fa-spinner') === 1 && preg_match_all('~far fa-circle text-muted~', $t) === 4 && str_contains($t, 'Check the files') && str_contains($t, 'Build the installer (MSI)'),
    'the checklist has seven stages: two done, one active, four waiting');
$ok(!str_contains($t, '<b>go build') && !str_contains($t, 'go build'), 'the detail text from the host is not shown for a build stage (display text is never trusted)');
$q("UPDATE endpoint_agent_build_jobs SET progress_stage='upload', progress_pct=88, progress_detail='7 of 23' WHERE job_id = $jid");
$q("UPDATE endpoint_agent_build_jobs SET progress_detail='chunk 7 of 23' WHERE job_id = $jid");
$t = $live(); $ok(str_contains($t, 'Uploading 7 of 23') && str_contains($t, 'aria-valuenow="88"'), 'uploading: "Uploading 7 of 23"');
foreach (['7 of <script>', '9999999 of 2', '30 of 2', 'x' . str_repeat('9', 100)] as $bad) {
    $q("UPDATE endpoint_agent_build_jobs SET progress_detail='" . $esc($bad) . "' WHERE job_id = $jid"); $t = $live();
    $ok(!str_contains($t, '<script>') && !str_contains($t, '9999999') && str_contains($t, 'Uploading the files') && !str_contains($t, 'Uploading 30'), 'a malformed upload detail falls back to plain words: ' . substr($bad, 0, 14));
}
$q("UPDATE endpoint_agent_build_jobs SET progress_stage='bogus', progress_pct=250 WHERE job_id = $jid"); $t = $live();
$ok(!str_contains($t, 'bogus') && str_contains($t, 'progress-bar-striped') && !str_contains($t, '250%'), 'an unknown stage in the database shows no stage and no percent (a moving bar only)');
$q("UPDATE endpoint_agent_build_jobs SET progress_stage='', progress_pct=0, progress_at=NULL WHERE job_id = $jid"); $t = $live();
$ok(str_contains($t, 'Building') && str_contains($t, 'progress-bar-striped') && !str_contains($t, 'data-bh-steps'), 'a build host script that reports no progress still gets a moving bar and no checklist');
$q("UPDATE endpoint_agent_build_jobs SET state='failed', progress_stage='msi', progress_pct=70, finished_at = UTC_TIMESTAMP() WHERE job_id = $jid"); $t = $live();
$ok(str_contains($t, 'Stopped while: building the installer (MSI)') && str_contains($t, 'bg-danger') && str_contains($t, 'fa-times-circle') && !str_contains($t, 'fa-spinner'), 'a failed job shows where it stopped, a red bar and a failed item');
$q("UPDATE endpoint_agent_build_jobs SET state='built', progress_pct=100 WHERE job_id = $jid"); $t = $live();
$ok(str_contains($t, 'aria-valuenow="100"') && str_contains($t, 'bg-success') && str_contains($t, 'Took '), 'a built job shows a full green bar');
$q("UPDATE endpoint_agent_build_jobs SET state='built', progress_pct=100, finished_at=NULL, claimed_at = UTC_TIMESTAMP() - INTERVAL 14 HOUR, last_status_at = UTC_TIMESTAMP() - INTERVAL 14 HOUR + INTERVAL 9 MINUTE WHERE job_id = $jid"); $t = $live();
$ok(str_contains($t, 'Took 9m') && !str_contains($t, 'Took 14h'), 'a built job that nobody has published yet keeps its real build time (the clock stops at the last report, it does not run on for hours)');
$q("UPDATE endpoint_agent_build_jobs SET state='queued', progress_stage='', progress_pct=0, claimed_at=NULL, finished_at=NULL WHERE job_id = $jid"); $t = $live();
$ok(str_contains($t, 'Waiting for the build host to pick it up') && !str_contains($t, 'role="progressbar"'), 'a queued job shows no bar');
$q("UPDATE endpoint_agent_build_jobs SET state='queued' WHERE job_id = $jid");
$q("UPDATE endpoint_agent_build_jobs SET state='failed', note='<img src=x onerror=1>', log_tail='line1\n<script>alert(2)</script>\nline3' WHERE job_id = $jid");
[$c, $html] = $page(); $ok(!str_contains($html, '<script>alert(2)</script>') && !str_contains($html, '<img src=x') && str_contains($html, '&lt;script&gt;alert(2)&lt;/script&gt;') && str_contains($html, 'Log tail') && str_contains($html, 'Failed'), 'a failed job shows its log tail and note, escaped');
$q("UPDATE endpoint_agent_build_jobs SET state='building', note='x', log_tail='' WHERE job_id = $jid");
foreach (['tech', 'viewer', 'normo'] as $who) { [$c, $b] = $adm(['agent_feed' => 'bh_cancel', 'job_id' => (string) $jid], $who, $jsn); $ok(job($jid)['state'] === 'building', "$who: cannot cancel ($c)"); }
[$c, $b] = $adm(['agent_feed' => 'bh_cancel', 'job_id' => (string) $jid], 'admin', $jsn);
$ok($c === 200 && job($jid)['state'] === 'cancelled' && $audit('Agent Build Host Job Cancelled') === 1, 'an administrator cancels it (audited)');
[$c, $b] = $adm(['agent_feed' => 'bh_cancel', 'job_id' => (string) $jid], 'admin', $jsn); $ok($c === 422, 'cancelling a finished job is refused');
[$c, $b] = $adm(['agent_feed' => 'bh_publish', 'job_id' => (string) $jid], 'admin', $jsn); $ok($c === 422 && $cnt('endpoint_agent_binaries') === 0, 'publishing a cancelled job is refused');
foreach (['tech', 'viewer'] as $who) { [$c, $b] = $adm(['agent_feed' => 'bh_publish', 'job_id' => (string) $jid], $who, $jsn); $ok(($jd($b)['success'] ?? null) !== true, "$who: cannot publish"); }
[$c, $b] = $adm(['agent_feed' => 'bh_status'], 'admin', $jsn); $j = $jd($b); $ok($c === 200 && isset($j['active']) && str_contains($j['html'], 'id="af-bh-live"') && !str_contains($j['html'], 'rbh_'), 'the live status answer carries the hosts and jobs only');

echo "== no-JavaScript path: the token on the next page, once\n";
[$c, $b] = $adm(['agent_feed' => 'bh_rotate', 'host_id' => (string) $hid]);   // ordinary form post
$ok($c === 302 || $c === 200, 'a plain form post is accepted (status ' . $c . ')');
[$c, $html1] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S['admin']); [$c, $html2] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S['admin']);
$ok(preg_match('/id="af-bh-token" readonly value="rbh_/', $html1) === 1 && !str_contains($html2, 'id="af-bh-token"'), 'the new token appears on the very next page and not on the one after');
echo "== delete\n";
[$c, $b] = $adm(['agent_feed' => 'bh_delete', 'host_id' => (string) $hid], 'admin', $jsn); $ok($c === 200 && $cnt('endpoint_agent_build_hosts') === 0 && $cnt('endpoint_agent_build_jobs') === 0, 'delete removes the host and its jobs');
function job(int $id): array { global $db; return $db->query("SELECT * FROM endpoint_agent_build_jobs WHERE job_id = $id")->fetch_assoc() ?: []; }
exit($fails > 0 ? 1 : 0);
