<?php
/*
 * Administration > Endpoint agent > Agent updates (RivetIT 26.10.52): the GitHub release feed and the one-click publish. Scratch database + a MOCK GitHub (tests/support/mock_github.php on a
 * loopback port); nothing here touches the network.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... EA_TEST_NON_WINDOWS=1 RMM_TEST_STATE_DIR=none RMM_GATE_STATE_DIR=/nonexistent php tests/endpoint_agent_feed.php
 *
 * The scratch config.php defines EA_ALLOW_INSECURE_HTTP; together with RIVET_AGENT_FEED_TEST_ORIGIN (set below) that is the only thing that points the feed at a loopback http server.
 * Optional: FEED_REAL_DIR=<dir holding the real agent-v0.9.1 files: SHA256SUMS, SHA256SUMS.msi, install-linux.sh, rivetit-agent-windows-*.exe/.msi> adds a section that publishes the REAL release
 * (served by the mock, so the network is still not touched).
 *
 * Covers: the URL policy (SSRF), the cache (ETag, TTL, forced checks, rate limit, offline, malformed and oversized answers, hostile release data), the status of each release, every refusal of a
 * publish (tamper, checksum missing / disagreeing, wrong size, redirects to other hosts, loops, oversized), what a good publish stores (through RmmAdmin: audit, current, offered), the
 * recorded Linux assets and the self-contained Linux command, and the HTTP handler (administrator only, CSRF, hostile input, escaping, new code on an old schema).
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_agent_feed_ui.php";

use RivetCore\Rmm\Authz\RmmPrincipal;

ea_reset();
$U = ea_seed_users();
$q('DELETE FROM endpoint_agent_binaries');
$q('DELETE FROM endpoint_agent_msi_templates');
$q('DELETE FROM endpoint_agent_releases');
$q('DELETE FROM endpoint_agent_linux_assets');
$q("UPDATE endpoint_agent_feed SET repo='TheTractorHacker/rivet-core', releases_json=NULL, etag='', fetched_at=NULL, checked_at=NULL, last_error='', rate_reset_at=NULL");
$rmm = rivetRmmModule($db);
$binDir = $rmm->binaryStore()->storageDir();
register_shutdown_function(function () use ($binDir, $db) {
    foreach ($db->query('SELECT storage_name FROM endpoint_agent_binaries UNION SELECT storage_name FROM endpoint_agent_msi_templates') ?: [] as $r) { @unlink("$binDir/{$r['storage_name']}"); }
});

// ---------------------------------------------------------------- the fixtures and the mock GitHub
function fx_pe(int $machine, int $size, string $fill): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0");
    $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $need = $size - strlen($b);

    return $b . substr(str_repeat(hash('sha256', $fill . $machine, true), intdiv($need, 32) + 1), 0, $need);
}
$mockDir = sys_get_temp_dir() . '/mockgh_' . bin2hex(random_bytes(4));
mkdir("$mockDir/assets/agent-v0.9.1", 0775, true);
mkdir("$mockDir/assets/agent-v0.9.0", 0775, true);
register_shutdown_function(function () use ($mockDir) { exec('rm -rf ' . escapeshellarg($mockDir)); });
$F = [   // v0.9.1
    'rivetit-agent-windows-amd64.exe' => fx_pe(0x8664, 60000, 'x'),
    'rivetit-agent-windows-arm64.exe' => fx_pe(0xAA64, 50000, 'x'),
    'rivetit-agent-windows-amd64.msi' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 12000),   // an OLE-signature file: Core accepts it with the architecture declared (arch_source "declared"); the REAL package is in section 7
    'install-linux.sh' => "#!/usr/bin/env bash\n# fake install-linux.sh for the tests\necho \"ARGS: \$*\" > \"\${FAKE_INSTALL_OUT:?}\"\n",
    'rivetit-agent-linux-amd64' => "\x7fELF" . str_repeat('L', 3000),
    'rivetit-agent-linux-arm64' => "\x7fELF" . str_repeat('M', 3000),
    'rivetit-agent-linux-amd64.tar.gz' => str_repeat('T', 2000),
    'rivetit-agent-linux-arm64.tar.gz' => str_repeat('U', 2000),
    'rivetit-agent_0.9.1_amd64.deb' => str_repeat('D', 2000),
    'rivetit-agent_0.9.1_arm64.deb' => str_repeat('E', 2000),
    'rivetit-agent-0.9.1-1.x86_64.rpm' => str_repeat('R', 2000),
    'rivetit-agent-0.9.1-1.aarch64.rpm' => str_repeat('S', 2000),
];
$F0 = ['rivetit-agent-windows-amd64.exe' => fx_pe(0x8664, 55000, 'old')];   // v0.9.0
$ok(strlen($F['rivetit-agent-windows-amd64.msi']) > 8192 && str_starts_with($F['rivetit-agent-windows-amd64.msi'], "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"), 'the MSI fixture is an OLE-signature file (' . strlen($F['rivetit-agent-windows-amd64.msi']) . ' bytes)');
foreach ($F as $n => $b) { file_put_contents("$mockDir/assets/agent-v0.9.1/$n", $b); }
foreach ($F0 as $n => $b) { file_put_contents("$mockDir/assets/agent-v0.9.0/$n", $b); }
$sums = function (array $files, array $names): string { $o = ''; foreach ($names as $n) { $o .= hash('sha256', $files[$n]) . '  ' . $n . "\n"; } return $o; };
$sumsMain = $sums($F, ['rivetit-agent-windows-amd64.exe', 'rivetit-agent-windows-arm64.exe', 'rivetit-agent-linux-amd64', 'rivetit-agent-linux-arm64', 'rivetit-agent-linux-amd64.tar.gz', 'rivetit-agent-linux-arm64.tar.gz', 'install-linux.sh']);
$sumsMsi = $sums($F, ['rivetit-agent-windows-amd64.msi']);   // like the real release: deb and rpm are in neither file
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS", $sumsMain);
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS.msi", $sumsMsi);
file_put_contents("$mockDir/assets/agent-v0.9.0/SHA256SUMS", $sums($F0, array_keys($F0)));
$asset = fn (string $n, array $files, bool $digest = true) => ['name' => $n, 'size' => strlen($files[$n]), 'digest' => $digest ? 'sha256:' . hash('sha256', $files[$n]) : null, 'browser_download_url' => 'https://evil.example.test/' . $n];
$assets091 = [];
foreach (array_keys($F) as $n) { $assets091[] = $asset($n, $F); }
foreach (['SHA256SUMS' => $sumsMain, 'SHA256SUMS.msi' => $sumsMsi] as $n => $b) { $assets091[] = ['name' => $n, 'size' => strlen($b), 'digest' => 'sha256:' . hash('sha256', $b), 'browser_download_url' => 'https://evil.example.test/' . $n]; }
$rel091 = ['tag_name' => 'agent-v0.9.1', 'name' => 'RivetIT agent 0.9.1 <script>alert(1)</script>', 'draft' => false, 'prerelease' => true, 'published_at' => '2026-10-18T17:16:53Z', 'body' => "Notes <img src=x onerror=alert(1)>\nline two", 'assets' => $assets091];
$rel090 = ['tag_name' => 'agent-v0.9.0', 'name' => '0.9.0', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-10-10T10:00:00Z', 'body' => '', 'assets' => [$asset('rivetit-agent-windows-amd64.exe', $F0), ['name' => 'SHA256SUMS', 'size' => 10, 'digest' => null]]];
$noise = [
    ['tag_name' => 'v1.0.0-rc.21', 'name' => 'core', 'draft' => false, 'prerelease' => true, 'published_at' => '2026-10-17T21:50:46Z', 'body' => '', 'assets' => []],
    ['tag_name' => 'agent-v9.9.9', 'name' => 'draft', 'draft' => true, 'prerelease' => false, 'published_at' => '2026-10-19T00:00:00Z', 'body' => '', 'assets' => []],
    ['tag_name' => 'agent-v1.0.0; rm -rf /', 'name' => 'hostile tag', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-10-19T00:00:00Z', 'body' => '', 'assets' => []],
    ['tag_name' => 'agent-v../../etc', 'name' => 'hostile tag 2', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-10-19T00:00:00Z', 'body' => '', 'assets' => []],
    ['tag_name' => 'agent-v1.2.3', 'name' => 'hostile assets', 'draft' => false, 'prerelease' => false, 'published_at' => 'yesterday <b>', 'body' => '', 'assets' => [
        ['name' => '../../etc/passwd', 'size' => 1], ['name' => 'rivetit-agent-windows-amd64.exe/../../x', 'size' => 1], ['name' => str_repeat('a', 300), 'size' => 1], ['name' => "evil\nname", 'size' => 1], ['name' => 'rivetit-agent-windows-amd64.exe', 'size' => 70000]]],
];
$releases = array_merge($noise, [$rel091, $rel090]);
$scn = function (array $s) use ($mockDir) { file_put_contents("$mockDir/scenario.json", json_encode($s)); };
$reqs = function (string $needle = '') use ($mockDir): int { $f = "$mockDir/requests.log"; if (!is_file($f)) { return 0; } return $needle === '' ? count(file($f, FILE_IGNORE_NEW_LINES)) : substr_count((string) file_get_contents($f), $needle); };
$reqReset = function () use ($mockDir) { @unlink("$mockDir/requests.log"); };
file_put_contents("$mockDir/releases.json", json_encode($releases));
$scn([]);
$mock = ea_start_php("$root/tests/support/mock_github.php", ['MOCK_GH_DIR' => $mockDir]);
$origin = 'http://127.0.0.1:' . $mock['port'];
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=' . $origin);
$sdir = sys_get_temp_dir() . '/ea_feed_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$webSrv = ea_start_php("$root/tests/mobile_api_router.php", ['RIVET_AGENT_FEED_TEST_ORIGIN' => $origin, 'RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir", 'upload_max_filesize=16M', 'post_max_size=32M', 'memory_limit=512M', 'max_execution_time=120']);
$wb = 'http://127.0.0.1:' . $webSrv['port'];
$S_ = []; foreach ([1 => 'admin', 10 => 'tech', 12 => 'viewer', 14 => 'moduleonly', 15 => 'normo', 16 => 'deptb'] as $uid => $n) { $S_[$n] = ea_forge_session($sdir, $uid); }
$admin = rivetRmmPrincipal(1, 'admin');
$techP = rivetRmmPrincipal(10, 'tech');
$feedRow = fn () => $rows('SELECT * FROM endpoint_agent_feed WHERE id = 1')[0] ?? [];
$audit = fn (string $action) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = '" . $esc($action) . "'");
$binRows = fn () => $rows('SELECT * FROM endpoint_agent_binaries ORDER BY binary_id');
$msiRows = fn () => $rows('SELECT * FROM endpoint_agent_msi_templates ORDER BY template_id');
$linRows = fn () => $rows('SELECT * FROM endpoint_agent_linux_assets ORDER BY asset_id');
$relRows = fn () => $rows('SELECT * FROM endpoint_agent_releases ORDER BY release_id');
$wipe = function () use ($q, $db, $rmm) { foreach ($db->query('SELECT storage_name FROM endpoint_agent_binaries UNION SELECT storage_name FROM endpoint_agent_msi_templates') ?: [] as $r) { @unlink($rmm->binaryStore()->storageDir() . '/' . $r['storage_name']); } $q('DELETE FROM endpoint_agent_binaries'); $q('DELETE FROM endpoint_agent_msi_templates'); $q('DELETE FROM endpoint_agent_releases'); $q('DELETE FROM endpoint_agent_linux_assets'); };
$opt = fn (array $o = []) => rivetAgentFeedOptions($o + ['ring' => 'stable', 'rollout_pct' => 100, 'activate' => true, 'offer' => false]);
$step = fn (string $tag, string $asset, array $o = [], ?RmmPrincipal $who = null) => rivetAgentFeedStep($db, $rmm, $who ?? $admin, $tag, $asset, $opt($o));
\ITFlow\EndpointAgent\Config::enable();
\ITFlow\EndpointAgent\Config::set(['service_url' => $wb]);

// ================================================================ 1. the URL policy (SSRF)
$chk = fn (string $u, string $role = 'asset', bool $first = true) => is_array(rivetAgentFeedCheckUrl($u, $role, $first));
$ok($chk('https://api.github.com/repos/o/r/releases', 'api') && $chk('https://github.com/o/r/releases/download/agent-v1.0.0/x.exe'), 'policy: the API host and github.com are allowed');
$ok(!$chk('https://github.com/x', 'api') && !$chk('https://api.github.com/x', 'asset'), 'policy: the API role only api.github.com, the asset role only github.com for the first request');
foreach (['https://objects.githubusercontent.com/x', 'https://release-assets.githubusercontent.com/x', 'https://github-releases.githubusercontent.com/x'] as $u) {
    $ok(!$chk($u, 'asset', true) && $chk($u, 'asset', false), "policy: $u is allowed only as a redirect target");
}
$bad = ['http://github.com/x' => 'plain http', 'https://github.com:8443/x' => 'other port', 'https://evil.example.test/x' => 'other host', 'https://github.com.evil.test/x' => 'suffix trick', 'https://evilgithub.com/x' => 'prefix trick',
    'https://user:pw@github.com/x' => 'credentials', 'https://github.com@evil.test/x' => 'userinfo trick', 'https://127.0.0.1/x' => 'loopback address', 'https://[::1]/x' => 'ipv6 loopback', 'https://169.254.169.254/latest' => 'metadata address',
    'https://10.0.0.5/x' => 'private address', 'ftp://github.com/x' => 'ftp', 'file:///etc/passwd' => 'file', 'gopher://github.com/x' => 'gopher', "https://github.com/x\r\nX: y" => 'header injection', 'https://github.com/x y' => 'space',
    'https://github.com\\@evil.test/' => 'backslash', '//github.com/x' => 'scheme-relative', '' => 'empty', 'https://github.com/x#frag' => 'fragment', 'https://github.com./x' => 'trailing dot (resolved as github.com is fine, but only as exact host)'];
foreach ($bad as $u => $why) {
    if ($why === 'trailing dot (resolved as github.com is fine, but only as exact host)') { continue; }
    $ok(!$chk($u, 'asset', false) && !$chk($u, 'api', false), "policy refuses $why");
}
$ok(rivetAgentFeedTestOrigin() !== null && rivetAgentFeedTestOrigin()['origin'] === $origin, 'the test origin is active only because the install constant and the environment variable are both set');
$o1 = rivetAgentFeedTestOrigin();
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=http://evil.example.test:80');
$ok(rivetAgentFeedTestOrigin() === null, 'the test origin must be loopback (a non-loopback value is ignored)');
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=https://127.0.0.1:9');
$ok(rivetAgentFeedTestOrigin() === null, 'the test origin must be plain http on loopback');
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=' . $origin);
$ok(preg_match(RIVET_AGENT_FEED_REPO_RE, 'TheTractorHacker/rivet-core') === 1 && preg_match(RIVET_AGENT_FEED_REPO_RE, 'a/b') === 1, 'repository pattern: owner/name');
foreach (['', 'x', 'a/b/c', '../etc', 'a b/c', 'a/b?x=1', "a/b\n", '-a/b', 'a/' . str_repeat('b', 101), 'a/..', 'a/.', 'a/.hidden', 'a/b#x', 'a/b@c', 'https://github.com/a/b'] as $r) { $ok(preg_match(RIVET_AGENT_FEED_REPO_RE, $r) !== 1, 'repository pattern refuses ' . json_encode($r)); }
foreach (['agent-v0.9.1', 'agent-v10.20.30-rc1', 'agent-v1.2.3-beta.2'] as $t) { $ok(preg_match(RIVET_AGENT_FEED_TAG_RE, $t) === 1, "tag pattern accepts $t"); }
foreach (['v0.9.1', 'agent-v0.9', 'agent-v0.9.1.2', 'agent-v0.9.1+build', "agent-v0.9.1\n", 'agent-v0.9.1; id', 'agent-v../1', 'agent-v1.2.3-' . str_repeat('a', 25), 'AGENT-v1.2.3'] as $t) { $ok(preg_match(RIVET_AGENT_FEED_TAG_RE, $t) !== 1, 'tag pattern refuses ' . json_encode($t)); }
$ok(rivetAgentFeedVersionCmp('0.9.1', '0.9.0') > 0 && rivetAgentFeedVersionCmp('0.10.0', '0.9.9') > 0 && rivetAgentFeedVersionCmp('1.0.0', '1.0.0-rc1') > 0 && rivetAgentFeedVersionCmp('1.0.0-rc2', '1.0.0-rc1') > 0 && rivetAgentFeedVersionCmp('1.2.3', '1.2.3') === 0, 'version order: numeric, a release after its pre-release');

// the HTTP client itself
$tmpf = sys_get_temp_dir() . '/feedget_' . bin2hex(random_bytes(3));
$g = rivetAgentFeedGet($origin . '/nothing/here', ['role' => 'asset']);
$ok(!$g['ok'] && $g['status'] === 404, 'client: a 404 is a failure with the status');
$scn(['asset_mode' => 'evil_redirect']);
$g = rivetAgentFeedGet($origin . '/o/r/releases/download/agent-v0.9.1/install-linux.sh', ['role' => 'asset', 'to_file' => $tmpf]);
$ok(!$g['ok'] && str_contains($g['error'], 'Redirect refused') && !is_file($tmpf), 'client: a redirect to another host is refused and no file is left (' . $g['error'] . ')');
foreach (['redirect_private' => 'private', 'redirect_http' => 'plain http', 'redirect_userinfo' => 'credentials'] as $mode => $why) {
    $scn(['asset_mode' => $mode]);
    $g = rivetAgentFeedGet($origin . '/o/r/releases/download/agent-v0.9.1/install-linux.sh', ['role' => 'asset', 'to_file' => $tmpf]);
    $ok(!$g['ok'] && str_contains($g['error'], 'Redirect refused') && !is_file($tmpf), "client: a redirect to a $why address is refused");
}
$scn(['asset_mode' => 'redirect_loop']);
$g = rivetAgentFeedGet($origin . '/o/r/releases/download/agent-v0.9.1/install-linux.sh', ['role' => 'asset', 'to_file' => $tmpf]);
$ok(!$g['ok'] && str_contains($g['error'], 'Too many redirects'), 'client: a redirect loop stops after 5 hops (' . $g['error'] . ')');
$scn(['asset_mode' => 'huge']);
$g = rivetAgentFeedGet($origin . '/o/r/releases/download/agent-v0.9.1/install-linux.sh', ['role' => 'asset', 'to_file' => $tmpf, 'max_bytes' => 1000000]);
$ok(!$g['ok'] && str_contains($g['error'], 'larger than the limit') && !is_file($tmpf), 'client: a body announced larger than the cap is refused before it is read');
$scn([]);
$g = rivetAgentFeedGet($origin . '/o/r/releases/download/agent-v0.9.1/install-linux.sh', ['role' => 'asset', 'to_file' => $tmpf, 'max_bytes' => 20]);
$ok(!$g['ok'] && str_contains($g['error'], 'larger than the limit') && !is_file($tmpf), 'client: a body that grows past the cap is cut off and removed');
$g = rivetAgentFeedGet($origin . '/o/r/releases/download/agent-v0.9.1/install-linux.sh', ['role' => 'asset', 'to_file' => $tmpf, 'max_bytes' => 100000]);
$ok($g['ok'] && is_file($tmpf) && file_get_contents($tmpf) === $F['install-linux.sh'] && $g['bytes'] === strlen($F['install-linux.sh']), 'client: the normal path follows the CDN redirect and writes the bytes');
@unlink($tmpf);
$g = rivetAgentFeedGet('http://127.0.0.1:1/x', ['role' => 'api']);
$ok(!$g['ok'] && $g['status'] === 0, 'client: a closed port is a clean failure');
$scn(['asset_mode' => 'slow']);
$t0 = microtime(true);
$g = rivetAgentFeedGet($origin . '/o/r/releases/download/agent-v0.9.1/install-linux.sh', ['role' => 'asset', 'to_file' => $tmpf, 'timeout' => 5]);
$ok($g['ok'] && microtime(true) - $t0 < 20, 'client: a slow answer within the timeout still works');
$scn([]); @unlink($tmpf);

// ================================================================ 2. the cache
$c0 = rivetAgentFeedCached($db);
$ok($c0['ready'] && $c0['repo'] === RIVET_AGENT_FEED_DEFAULT_REPO && $c0['releases'] === [] && $c0['fetched_at'] === null, 'before the first check: ready, the default repository, no releases, never fetched');
$reqReset();
$r = rivetAgentFeedRefresh($db, true);
$c1 = rivetAgentFeedCached($db);
$ok($r['ok'] && $reqs('/releases') === 1 && count($c1['releases']) === 3, 'the first check asks GitHub once and keeps only agent releases (' . count($c1['releases']) . ': ' . implode(',', array_column($c1['releases'], 'tag')) . ')');
$ok(array_column($c1['releases'], 'tag') === ['agent-v1.2.3', 'agent-v0.9.1', 'agent-v0.9.0'], 'newest version first; the core release, the draft and both hostile tags are dropped');
$h123 = $c1['releases'][0];
$ok($h123['published_at'] === '' && count($h123['assets']) === 1 && $h123['assets'][0]['name'] === 'rivetit-agent-windows-amd64.exe', 'hostile data is dropped: a bad date becomes empty and only the asset with a safe name stays');
$ok($feedRow()['etag'] === '' && $feedRow()['last_error'] === '' && $feedRow()['fetched_at'] !== null, 'the cache row has a fetch time and no error');
$reqReset();
$r = rivetAgentFeedRefresh($db, false);
$ok($r['ok'] && $r['from_cache'] && $reqs() === 0, 'a second look inside the time to live is answered from the cache (no request)');
$r = rivetAgentFeedRefresh($db, true);
$ok($r['ok'] && $r['from_cache'] && $reqs() === 0, '"Check for updates" twice in a row is limited to once per ' . RIVET_AGENT_FEED_MIN_FORCE_S . ' s (no request)');
$q("UPDATE endpoint_agent_feed SET checked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
$scn(['api_etag' => 'W/"v1"']);
$r = rivetAgentFeedRefresh($db, true);   // no etag stored yet: a full answer carrying one
$ok($r['ok'] && $feedRow()['etag'] === 'W/"v1"' && $reqs('/releases 200') === 1, 'the answer\'s ETag is kept');
$q("UPDATE endpoint_agent_feed SET checked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
$reqReset();
$r = rivetAgentFeedRefresh($db, true);
$ok($r['ok'] && !$r['changed'] && $reqs('/releases 304') === 1 && count(rivetAgentFeedCached($db)['releases']) === 3, 'with the ETag GitHub answers 304 and the saved list stays');
// rate limit
$scn(['api_status' => 403, 'api_headers' => ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 1800)]]);
$q("UPDATE endpoint_agent_feed SET checked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), etag=''");
$r = rivetAgentFeedRefresh($db, true);
$ok(!$r['ok'] && str_contains($r['message'], 'rate limit') && count(rivetAgentFeedCached($db)['releases']) === 3 && $feedRow()['rate_reset_at'] !== null, 'rate limit: said in words, the saved list is kept, the reset time is stored');
$reqReset();
$q("UPDATE endpoint_agent_feed SET checked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
$r = rivetAgentFeedRefresh($db, true);
$ok(!$r['ok'] && $r['from_cache'] && $reqs() === 0 && str_contains($r['message'], 'rate limit'), 'while the limit lasts GitHub is not asked again (no request)');
$q('UPDATE endpoint_agent_feed SET rate_reset_at = NULL, checked_at = NULL');
// other failures keep the list
foreach ([['api_status' => 500, 'api_raw' => 'oops'], ['api_raw' => 'not json at all'], ['api_raw' => '{"message":"Not Found"}'], ['api_status' => 404, 'api_raw' => '{"message":"Not Found"}']] as $i => $sc) {
    $scn($sc);
    $q('UPDATE endpoint_agent_feed SET checked_at = NULL');
    $r = rivetAgentFeedRefresh($db, true);
    $ok(!$r['ok'] && count(rivetAgentFeedCached($db)['releases']) === 3 && $feedRow()['last_error'] !== '', "failure #$i (" . json_encode($sc) . ') keeps the saved list and records why: ' . $r['message']);
}
$scn(['api_raw' => str_repeat('x', 2200000)]);
$q('UPDATE endpoint_agent_feed SET checked_at = NULL');
$r = rivetAgentFeedRefresh($db, true);
$ok(!$r['ok'] && count(rivetAgentFeedCached($db)['releases']) === 3, 'an oversized answer (over 2 MiB) is refused and the saved list stays');
// offline
$real = getenv('RIVET_AGENT_FEED_TEST_ORIGIN');
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=http://127.0.0.1:1');
$q('UPDATE endpoint_agent_feed SET checked_at = NULL');
$r = rivetAgentFeedRefresh($db, true);
$ok(!$r['ok'] && str_contains($r['message'], 'could not reach GitHub') && count(rivetAgentFeedCached($db)['releases']) === 3, 'offline: "could not reach GitHub", the saved list is shown (' . $r['message'] . ')');
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=' . $real);
// pagination: three pages, the third page holds an extra agent release; an evil next link is ignored
$page1 = [$noise[0]]; $page2 = [$rel091]; $page3 = [$rel090];
$scn(['api_pages' => [$page1, $page2, $page3]]);
$q('UPDATE endpoint_agent_feed SET checked_at = NULL, releases_json = NULL, etag = \'\'');
$reqReset();
$r = rivetAgentFeedRefresh($db, true);
$ok($r['ok'] && $reqs('/releases 200') === 3 && array_column(rivetAgentFeedCached($db)['releases'], 'tag') === ['agent-v0.9.1', 'agent-v0.9.0'], 'pagination: the Link rel=next pages are followed (3 requests)');
$scn(['api_pages' => [$page1, $page2, $page3], 'api_page_link_evil' => 'https://evil.example.test/repos/x/y/releases']);
$q('UPDATE endpoint_agent_feed SET checked_at = NULL, releases_json = NULL, etag = \'\'');
$reqReset();
$r = rivetAgentFeedRefresh($db, true);
$ok($r['ok'] && $reqs('/releases') === 1 && array_column(rivetAgentFeedCached($db)['releases'], 'tag') === [], 'pagination: a next link to another host is not followed');
$scn([]);
$q('UPDATE endpoint_agent_feed SET checked_at = NULL, releases_json = NULL, etag = \'\'');
$r = rivetAgentFeedRefresh($db, true);
$ok($r['ok'] && count(rivetAgentFeedCached($db)['releases']) === 3, 'back to the normal list (3 agent releases)');
// repository setting
$ok(rivetAgentFeedSetRepo($db, 'a/b c') !== null && rivetAgentFeedSetRepo($db, '../x') !== null && rivetAgentFeedRepo($db) === RIVET_AGENT_FEED_DEFAULT_REPO, 'a bad repository name is refused and nothing changes');
$ok(rivetAgentFeedSetRepo($db, 'someone/rivet-core-fork') === null && rivetAgentFeedRepo($db) === 'someone/rivet-core-fork' && rivetAgentFeedCached($db)['releases'] === [] && $feedRow()['etag'] === '', 'a new repository is saved and the saved list of the old one is dropped');
rivetAgentFeedSetRepo($db, RIVET_AGENT_FEED_DEFAULT_REPO);
$q('UPDATE endpoint_agent_feed SET checked_at = NULL');
rivetAgentFeedRefresh($db, true);

// ================================================================ 3. status
$st = rivetAgentFeedStatus($db, rivetAgentFeedCached($db)['releases']);
$rows091 = array_values(array_filter($st['rows'], fn ($r) => $r['release']['tag'] === 'agent-v0.9.1'))[0];
$ok($st['latest'] === '1.2.3' && $st['published_max'] === null && $rows091['state'] === 'new' && count($rows091['plan']) === 12 && $rows091['want'] === ['exe' => 2, 'msi' => 1, 'linux' => 9], 'status: nothing published yet; 12 usable files in 0.9.1 (2 exe, 1 msi, 9 Linux): ' . json_encode($rows091['want']));
$names = array_column($rows091['plan'], 'asset');
$ok(!in_array('SHA256SUMS', $names, true) && !in_array('SHA256SUMS.msi', $names, true), 'the checksum files are not publishable files');
$rows123 = array_values(array_filter($st['rows'], fn ($r) => $r['release']['tag'] === 'agent-v1.2.3'))[0];
$ok($rows123['want']['exe'] === 1, 'a release with one usable file lists one');
foreach (['rivetit-agent-windows-amd64.exe' => 'exe', 'rivetit-agent-windows-arm64.exe' => 'exe', 'rivetit-agent-windows-amd64.msi' => 'msi', 'install-linux.sh' => 'script', 'rivetit-agent-linux-amd64' => 'binary', 'rivetit-agent-linux-arm64.tar.gz' => 'tarball',
    'rivetit-agent_0.9.1_amd64.deb' => 'deb', 'rivetit-agent-0.9.1-1.aarch64.rpm' => 'rpm'] as $n => $kind) { $c = rivetAgentFeedClassify($n, '0.9.1'); $ok($c !== null && $c['kind'] === $kind, "classify: $n is $kind"); }
foreach (['rivetit-agent-windows-ppc64.exe', 'rivetit-agent_0.9.0_amd64.deb', 'rivetit-agent-windows-amd64.exe.sig', 'x/rivetit-agent-windows-amd64.exe', 'rivetit-agent-0.9.1-1.s390x.rpm', 'rivetit-agent-windows-amd64.EXE', 'random.bin'] as $n) { $ok(rivetAgentFeedClassify($n, '0.9.1') === null, "classify: $n is ignored"); }

// ================================================================ 4. publishing
$tag = 'agent-v0.9.1';
$a0 = $audit('Binary Uploaded');
$wipe();
$r = $step($tag, 'rivetit-agent-windows-amd64.exe');
$b = $binRows();
$ok($r['ok'] && $r['status'] === 'stored' && count($b) === 1 && $b[0]['version'] === '0.9.1' && $b[0]['arch'] === 'amd64' && $b[0]['sha256'] === hash('sha256', $F['rivetit-agent-windows-amd64.exe']) && (int) $b[0]['is_current'] === 1, 'x64 agent: downloaded, verified and stored as 0.9.1 amd64, current (' . $r['message'] . ')');
$ok(hash_file('sha256', $rmm->binaryStore()->pathFor($b[0])) === hash('sha256', $F['rivetit-agent-windows-amd64.exe']) && $audit('Binary Uploaded') === $a0 + 1, 'the stored file is byte for byte the release file, and Core audited the upload');
$ok($relRows() === [], 'nothing is offered to enrolled agents unless asked (no release row)');
$r = $step($tag, 'rivetit-agent-windows-amd64.exe');
$ok($r['ok'] && $r['status'] === 'already' && count($binRows()) === 1 && $audit('Binary Uploaded') === $a0 + 1, 're-publishing the same file is a no-op: "already stored" (no second row, no second upload)');
// the pilot ring offer on a file that is already there
$r = $step($tag, 'rivetit-agent-windows-amd64.exe', ['offer' => true, 'ring' => 'pilot', 'rollout_pct' => 40]);
$rr = $relRows();
$ok($r['ok'] && count($rr) === 1 && $rr[0]['ring'] === 'pilot' && (int) $rr[0]['rollout_pct'] === 40 && $rr[0]['arch'] === 'amd64' && $rr[0]['version'] === '0.9.1' && (int) $rr[0]['active'] === 1, 'offering an already stored file creates the pilot release at 40% (' . $r['message'] . ')');
$r = $step($tag, 'rivetit-agent-windows-arm64.exe', ['offer' => true, 'ring' => 'stable', 'rollout_pct' => 100, 'activate' => false]);
$b = $binRows();
$ok($r['ok'] && count($b) === 2 && (int) $b[1]['is_current'] === 0 && count($relRows()) === 2 && $relRows()[1]['ring'] === 'stable' && (int) $relRows()[1]['rollout_pct'] === 100, 'ARM64 agent with "use for new installers" unticked: stored, not current, offered to stable at 100%');
$r = $step($tag, 'rivetit-agent-windows-amd64.msi');
$m = $msiRows();
$ok($r['ok'] && $r['status'] === 'stored' && count($m) === 1 && $m[0]['arch'] === 'amd64' && $m[0]['version'] === '0.9.1' && $m[0]['sha256'] === hash('sha256', $F['rivetit-agent-windows-amd64.msi']) && (int) $m[0]['is_current'] === 1 && $m[0]['arch_source'] === 'declared', 'MSI template: verified against SHA256SUMS.msi and stored through the MSI template code (the architecture is the declared one for this fixture), current');
$ok($audit('MSI Template Uploaded') >= 1, 'the MSI upload is audited by Core');
// Linux assets
$r = $step($tag, 'install-linux.sh');
$ok($r['ok'] && $r['status'] === 'recorded' && count($linRows()) === 1 && $linRows()[0]['kind'] === 'script' && $linRows()[0]['sha256'] === hash('sha256', $F['install-linux.sh']) && (int) $linRows()[0]['is_current'] === 1, 'install-linux.sh: downloaded once, verified and recorded');
$ok(str_starts_with($linRows()[0]['url'], $origin . '/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/install-linux.sh'), 'the recorded address is built from the repository, the tag and the file name (not taken from the API\'s URL)');
foreach (['rivetit-agent-linux-amd64', 'rivetit-agent-linux-arm64', 'rivetit-agent-linux-amd64.tar.gz', 'rivetit-agent-linux-arm64.tar.gz', 'rivetit-agent_0.9.1_amd64.deb', 'rivetit-agent_0.9.1_arm64.deb', 'rivetit-agent-0.9.1-1.x86_64.rpm', 'rivetit-agent-0.9.1-1.aarch64.rpm'] as $n) {
    $reqReset();
    $r = $step($tag, $n);
    $ok($r['ok'] && $r['status'] === 'recorded' && $reqs("/cdn/agent-v0.9.1/$n ") === 0, "Linux file $n is recorded without downloading it");
}
$lin = array_column($linRows(), null, 'filename');
$ok(count($lin) === 9 && $lin['rivetit-agent-linux-amd64']['sha_source'] === 'SHA256SUMS' && $lin['rivetit-agent_0.9.1_amd64.deb']['sha_source'] === 'github-digest' && $lin['rivetit-agent-0.9.1-1.aarch64.rpm']['sha256'] === hash('sha256', $F['rivetit-agent-0.9.1-1.aarch64.rpm']) && $lin['rivetit-agent-0.9.1-1.x86_64.rpm']['arch'] === 'amd64' && $lin['rivetit-agent-0.9.1-1.aarch64.rpm']['arch'] === 'arm64',
    '9 Linux rows: the sums come from SHA256SUMS, the .deb and .rpm (not in any sums file, like the real release) from GitHub\'s digest; rpm architectures are mapped');
$st = rivetAgentFeedStatus($db, rivetAgentFeedCached($db)['releases']);
$rows091 = array_values(array_filter($st['rows'], fn ($r) => $r['release']['tag'] === 'agent-v0.9.1'))[0];
$ok($rows091['state'] === 'published' && $st['published_max'] === '0.9.1' && $rows091['offered'] !== [], 'status: 0.9.1 is Published, the highest published version is 0.9.1, the offers are listed');
$ok($audit('Agent Feed Published') === 3 && $audit('Agent Feed Linux Asset Recorded') === 9, 'the app audit log has a row per stored Windows file (3) and per recorded Linux file (9)');
// the version a stored binary carries on the device counts
$q("UPDATE endpoint_agent_devices SET agent_version='0.9.1' WHERE 1=0");
// refusals
$wipe();
$a1 = $audit('Agent Feed Verify Failed');
$scn(['asset_mode' => 'tamper', 'tamper' => 'rivetit-agent-windows-amd64.exe']);
$r = $step($tag, 'rivetit-agent-windows-amd64.exe');
$ok(!$r['ok'] && $r['status'] === 'refused' && str_contains($r['message'], 'does not match') && $binRows() === [] && $audit('Agent Feed Verify Failed') === $a1 + 1, 'a download that does not match SHA256SUMS stores NOTHING and is audited (' . $r['message'] . ')');
$leftover = glob(sys_get_temp_dir() . '/rivet-agent-feed-*') ?: [];
$ok($leftover === [], 'no temporary folder is left behind after a refusal');
$scn(['asset_mode' => 'tamper', 'tamper' => 'install-linux.sh']);
$r = $step($tag, 'install-linux.sh');
$ok(!$r['ok'] && $linRows() === [], 'a tampered install-linux.sh is not recorded');
$scn([]);
// a checksum file that lies
$goodSums = $sumsMain;
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS", str_replace(hash('sha256', $F['rivetit-agent-windows-arm64.exe']), str_repeat('0', 64), $sumsMain));
$r = $step($tag, 'rivetit-agent-windows-arm64.exe');
$ok(!$r['ok'] && str_contains($r['message'], 'do not agree') && $binRows() === [], 'SHA256SUMS and GitHub\'s digest disagree: refused before any download');
// no digest from the API, wrong sums: the download is compared and refused
$rel = $rel091; foreach ($rel['assets'] as &$aa) { $aa['digest'] = null; } unset($aa);
file_put_contents("$mockDir/releases.json", json_encode([$rel, $rel090]));
$q('UPDATE endpoint_agent_feed SET checked_at = NULL'); rivetAgentFeedRefresh($db, true);
$r = $step($tag, 'rivetit-agent-windows-arm64.exe');
$ok(!$r['ok'] && str_contains($r['message'], 'does not match') && $binRows() === [], 'only a wrong line in SHA256SUMS: the download is compared and refused');
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS", $goodSums);
// a Windows file with no line in the sums
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS", str_replace(hash('sha256', $F['rivetit-agent-windows-arm64.exe']) . '  rivetit-agent-windows-arm64.exe', '', $goodSums));
$r = $step($tag, 'rivetit-agent-windows-arm64.exe');
$ok(!$r['ok'] && str_contains($r['message'], 'no checksum') && $binRows() === [], 'a Windows file with no line in the checksum files is refused (nothing to verify)');
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS", $goodSums);
// SHA256SUMS and SHA256SUMS.msi disagree
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS.msi", $sumsMsi . hash('sha256', 'x') . "  rivetit-agent-windows-amd64.exe\n");
$r = $step($tag, 'rivetit-agent-windows-amd64.exe');
$ok(!$r['ok'] && str_contains($r['message'], 'disagree') && $binRows() === [], 'SHA256SUMS and SHA256SUMS.msi that contradict each other refuse everything');
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS.msi", $sumsMsi);
// a release without a sums file
$relNoSums = $rel091; $relNoSums['assets'] = array_values(array_filter($relNoSums['assets'], fn ($a) => !str_starts_with($a['name'], 'SHA256SUMS')));
file_put_contents("$mockDir/releases.json", json_encode([$relNoSums, $rel090]));
$q('UPDATE endpoint_agent_feed SET checked_at = NULL'); rivetAgentFeedRefresh($db, true);
$r = $step($tag, 'rivetit-agent-windows-amd64.exe');
$ok(!$r['ok'] && str_contains($r['message'], 'no SHA256SUMS') && $binRows() === [], 'a release without SHA256SUMS: refused');
// a wrong size in the listing
$relSize = $rel091; foreach ($relSize['assets'] as &$aa) { if ($aa['name'] === 'rivetit-agent-windows-amd64.exe') { $aa['size'] = 70000; } } unset($aa);
file_put_contents("$mockDir/releases.json", json_encode([$relSize, $rel090]));
$q('UPDATE endpoint_agent_feed SET checked_at = NULL'); rivetAgentFeedRefresh($db, true);
$r = $step($tag, 'rivetit-agent-windows-amd64.exe');
$ok(!$r['ok'] && $binRows() === [], 'a file bigger than the release lists is refused and nothing is stored (' . $r['message'] . ')');
file_put_contents("$mockDir/releases.json", json_encode([$rel091, $rel090]));
$q('UPDATE endpoint_agent_feed SET checked_at = NULL'); rivetAgentFeedRefresh($db, true);
// not a PE file / wrong architecture, with a CORRECT checksum: Core's validation still refuses
$bogus = str_repeat('Z', 5000);
file_put_contents("$mockDir/assets/agent-v0.9.1/rivetit-agent-windows-arm64.exe", $bogus);
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS", str_replace(hash('sha256', $F['rivetit-agent-windows-arm64.exe']), hash('sha256', $bogus), $goodSums));
$relB = $rel091; foreach ($relB['assets'] as &$aa) { if ($aa['name'] === 'rivetit-agent-windows-arm64.exe') { $aa['size'] = 5000; $aa['digest'] = 'sha256:' . hash('sha256', $bogus); } } unset($aa);
file_put_contents("$mockDir/releases.json", json_encode([$relB, $rel090]));
$q('UPDATE endpoint_agent_feed SET checked_at = NULL'); rivetAgentFeedRefresh($db, true);
$r = $step($tag, 'rivetit-agent-windows-arm64.exe');
$ok(!$r['ok'] && $binRows() === [], 'a file that matches its checksum but is not a Windows agent is refused by the ordinary publish checks (' . $r['message'] . ')');
file_put_contents("$mockDir/assets/agent-v0.9.1/rivetit-agent-windows-arm64.exe", $F['rivetit-agent-windows-arm64.exe']);
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS", $goodSums);
file_put_contents("$mockDir/releases.json", json_encode([$rel091, $rel090]));
$q('UPDATE endpoint_agent_feed SET checked_at = NULL'); rivetAgentFeedRefresh($db, true);
// redirects and downloads that go wrong
foreach (['evil_redirect' => 'another host', 'redirect_private' => 'a private address', 'redirect_http' => 'plain http', 'redirect_loop' => 'a loop', 'status_404' => 'a missing file'] as $mode => $why) {
    $scn(['asset_mode' => $mode, 'only' => 'rivetit-agent-windows-amd64.exe']);
    $r = $step($tag, 'rivetit-agent-windows-amd64.exe');
    $ok(!$r['ok'] && $r['status'] === 'failed' && $binRows() === [], "download redirected to $why: nothing stored (" . $r['message'] . ')');
}
$scn(['asset_mode' => 'huge', 'only' => 'rivetit-agent-windows-amd64.exe']);
$r = $step($tag, 'rivetit-agent-windows-amd64.exe');
$ok(!$r['ok'] && $binRows() === [], 'a download announced much larger than the release lists is cut off');
$scn([]);
// what is not in the plan or the cache
$r = $step('agent-v7.7.7', 'rivetit-agent-windows-amd64.exe');
$ok(!$r['ok'] && $r['status'] === 'refused' && str_contains($r['message'], 'not in the list'), 'an unknown tag is refused');
foreach (['agent-v0.9.1; id', '../agent-v0.9.1', 'v0.9.1', ''] as $t) { $r = $step($t, 'rivetit-agent-windows-amd64.exe'); $ok(!$r['ok'] && $binRows() === [], 'hostile tag ' . json_encode($t) . ' is refused'); }
foreach (['SHA256SUMS', 'nothing.exe', '../../etc/passwd', 'rivetit-agent-windows-amd64.exe/../x', '', "rivetit-agent-windows-amd64.exe\n"] as $a) { $r = $step($tag, $a); $ok(!$r['ok'] && $r['status'] === 'refused' && $binRows() === [], 'asset ' . json_encode($a) . ' is not part of the release: refused'); }
// who may publish
$r = $step($tag, 'rivetit-agent-windows-amd64.exe', [], $techP);
$ok(!$r['ok'] && str_contains($r['message'], 'Administrator') && $binRows() === [], 'a technician cannot publish (rmm.binary.publish)');
$r = $step($tag, 'rivetit-agent-windows-amd64.exe', [], rivetRmmPrincipal(12, 'viewer'));
$ok(!$r['ok'] && $binRows() === [], 'a viewer cannot publish');
// a different file for an existing version
$wipe();
$step($tag, 'rivetit-agent-windows-amd64.exe');
$rowBefore = $binRows()[0];
$other = fx_pe(0x8664, 61000, 'changed');
file_put_contents("$mockDir/assets/agent-v0.9.1/rivetit-agent-windows-amd64.exe", $other);
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS", str_replace(hash('sha256', $F['rivetit-agent-windows-amd64.exe']), hash('sha256', $other), $goodSums));
$relC = $rel091; foreach ($relC['assets'] as &$aa) { if ($aa['name'] === 'rivetit-agent-windows-amd64.exe') { $aa['size'] = strlen($other); $aa['digest'] = 'sha256:' . hash('sha256', $other); } } unset($aa);
file_put_contents("$mockDir/releases.json", json_encode([$relC, $rel090]));
$q('UPDATE endpoint_agent_feed SET checked_at = NULL'); rivetAgentFeedRefresh($db, true);
$r = $step($tag, 'rivetit-agent-windows-amd64.exe');
$ok(!$r['ok'] && str_contains($r['message'], 'different contents') && count($binRows()) === 1 && $binRows()[0]['sha256'] === $rowBefore['sha256'], 'a released version never changes: a different file for the same version is refused and the stored one stays');
file_put_contents("$mockDir/assets/agent-v0.9.1/rivetit-agent-windows-amd64.exe", $F['rivetit-agent-windows-amd64.exe']);
file_put_contents("$mockDir/assets/agent-v0.9.1/SHA256SUMS", $goodSums);
file_put_contents("$mockDir/releases.json", json_encode([$rel091, $rel090]));
$q('UPDATE endpoint_agent_feed SET checked_at = NULL'); rivetAgentFeedRefresh($db, true);
$wipe();
// publish all
$rb = $reqs();
$all = rivetAgentFeedPublishAll($db, $rmm, $admin, $tag, $opt(['offer' => true, 'ring' => 'pilot', 'rollout_pct' => 100]));
$ok($all['ok'] && count($all['steps']) === 12 && count($binRows()) === 2 && count($msiRows()) === 1 && count($linRows()) === 9 && count($relRows()) === 2 && $relRows()[0]['ring'] === 'pilot', 'publish all: 12 files done (2 agents, 1 MSI, 9 Linux), both agents offered to the pilot ring (' . $all['message'] . ')');
$all2 = rivetAgentFeedPublishAll($db, $rmm, $admin, $tag, $opt());
$ok($all2['ok'] && count(array_filter($all2['steps'], fn ($s) => $s['status'] === 'already')) === 3 && count($binRows()) === 2 && count($linRows()) === 9, 'publish all again: the three stored files say "already", the rows stay');
$lockOk = (int) mysqli_fetch_row(mysqli_query($db, "SELECT IS_FREE_LOCK('" . RIVET_AGENT_FEED_LOCK . "')"))[0];
$ok($lockOk === 1, 'the publish lock is released afterwards');
// two publishes at once: the second is told to wait
$conn2 = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$conn2->query("SELECT GET_LOCK('" . RIVET_AGENT_FEED_LOCK . "', 0)");
$busy = rivetAgentFeedPublishAll($db, $rmm, $admin, $tag, $opt());
$ok(!$busy['ok'] && str_contains($busy['message'], 'Another publish is running'), 'a second publish while one runs is refused with a clear message');
$conn2->close();

// ================================================================ 5. the Linux command
$assets = rivetAgentFeedLinuxAssets($db);
$core = \RivetCore\Rmm\Installer\InstallerService::linuxSnippet('https://rmm.example.test', 'rvte1.AAAA.BBBB', 'amd64', "Dept 'A'", null, null);
$cmd = rivetAgentFeedLinuxCommand($core, $assets, 'amd64');
$ok($assets['version'] === '0.9.1' && count($assets['items']) === 9 && $cmd['self_contained'], 'the Linux command is built from the recorded assets (0.9.1)');
$ok(!str_contains($cmd['command'], 'unpacked') && str_contains($cmd['command'], 'sha256sum -c') && substr_count($cmd['command'], 'sha256sum -c') === 2 && str_contains($cmd['command'], 'bash "$S" --binary "$B" --server') && str_contains($cmd['command'], "trap 'rm -f \"\$T\" \"\$S\" \"\$B\"' EXIT"), 'the command downloads the script and the agent, checks both SHA-256 values, passes --binary and cleans up all temporary files');
$ok(str_contains($cmd['command'], $lin['rivetit-agent-linux-amd64']['sha256']) && str_contains($cmd['command'], hash('sha256', $F['install-linux.sh'])) && !str_contains($cmd['command'], 'rivetit-agent-linux-arm64'), 'the command carries the recorded hashes and only the chosen architecture');
$ok(substr_count($cmd['command'], 'rvte1.AAAA.BBBB') === 1 && !preg_match('/--token[ =]/', $cmd['command']) && str_contains($cmd['command'], '--token-file "$T"'), 'the token appears once, written to a root-only temporary file, never on a command line');
$sh = sys_get_temp_dir() . '/feedcmd_' . bin2hex(random_bytes(3)) . '.sh';
file_put_contents($sh, $cmd['command']);
exec('bash -n ' . escapeshellarg($sh) . ' 2>&1', $o, $rc);
$ok($rc === 0, 'the command is valid shell (bash -n)');
// run it for real with a shim curl that serves the mock's files from disk, and the fake installer that records its arguments
$shim = sys_get_temp_dir() . '/feedshim_' . bin2hex(random_bytes(3)); mkdir($shim);
file_put_contents("$shim/curl", "#!/bin/bash\nout=''; url=''\nwhile [ \$# -gt 0 ]; do case \"\$1\" in -o) out=\"\$2\"; shift 2;; --*) shift;; *) url=\"\$1\"; shift;; esac; done\nname=\"\$(basename \"\$url\")\"\ncp \"$mockDir/assets/agent-v0.9.1/\$name\" \"\$out\" || exit 22\n");
chmod("$shim/curl", 0755);
$runCmd = function (string $command, array $env = []) use ($shim): array {
    $f = tempnam(sys_get_temp_dir(), 'fcmd'); file_put_contents($f, $command);
    $out = tempnam(sys_get_temp_dir(), 'fout');
    $e = array_merge(getenv(), ['PATH' => $shim . ':' . getenv('PATH'), 'FAKE_INSTALL_OUT' => $out], $env);
    $p = proc_open(['bash', $f], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, $e);
    $so = stream_get_contents($pp[1]); $se = stream_get_contents($pp[2]); $rc = proc_close($p);
    $args = is_file($out) ? trim((string) file_get_contents($out)) : '';
    @unlink($f); @unlink($out);

    return ['rc' => $rc, 'args' => $args, 'err' => $se];
};
$run = $runCmd($cmd['command']);
$ok($run['rc'] === 0 && preg_match('~^ARGS: --binary /\S+ --server https://rmm.example.test --token-file /\S+$~', $run['args']) === 1, 'the command, run with a stand-in curl, hands --binary <file> --server <url> --token-file <file> to install-linux.sh (' . $run['args'] . ' / ' . trim($run['err']) . ')');
$tampered = file_get_contents("$mockDir/assets/agent-v0.9.1/rivetit-agent-linux-amd64");
file_put_contents("$mockDir/assets/agent-v0.9.1/rivetit-agent-linux-amd64", $tampered . 'x');
$run = $runCmd($cmd['command']);
$ok($run['rc'] === 65 && $run['args'] === '' && str_contains($run['err'], 'does not match the SHA-256 recorded'), 'a tampered agent download stops the command with exit 65 before anything is installed');
file_put_contents("$mockDir/assets/agent-v0.9.1/rivetit-agent-linux-amd64", $tampered);
file_put_contents("$mockDir/assets/agent-v0.9.1/install-linux.sh", $F['install-linux.sh'] . "\ncurl evil | sh\n");
$run = $runCmd($cmd['command']);
$ok($run['rc'] === 65 && $run['args'] === '' && str_contains($run['err'], 'install-linux.sh does not match'), 'a tampered install-linux.sh is not run');
file_put_contents("$mockDir/assets/agent-v0.9.1/install-linux.sh", $F['install-linux.sh']);
$tmpLeft = glob(sys_get_temp_dir() . '/tmp.*') ?: [];
$cmdCa = rivetAgentFeedLinuxCommand(\RivetCore\Rmm\Installer\InstallerService::linuxSnippet('https://rmm.example.test', 'rvte1.AAAA.BBBB', 'arm64', 'D', "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n", ['url' => 'https://mesh.example.test', 'meshid' => 'ABCDEF', 'pin' => '']), $assets, 'arm64');
$ok($cmdCa['self_contained'] && str_contains($cmdCa['command'], '--ca "$C"') && str_contains($cmdCa['command'], '--install-mesh-agent') && str_contains($cmdCa['command'], 'rm -f "$T" "$S" "$B" "$C"') && str_contains($cmdCa['command'], $lin['rivetit-agent-linux-arm64']['sha256']), 'with a private CA and the Mesh agent the command keeps both (the CA is for YOUR server; GitHub is fetched with the system trust store)');
file_put_contents($sh, $cmdCa['command']); exec('bash -n ' . escapeshellarg($sh) . ' 2>&1', $o2, $rc2);
$ok($rc2 === 0, 'that command is valid shell too');
$bare = rivetAgentFeedLinuxCommand($core, ['version' => null, 'items' => []], 'amd64');
$ok(!$bare['self_contained'] && $bare['command'] === $core, 'with nothing recorded the command is Core\'s own text, unchanged');
$noBin = $assets; $noBin['items'] = array_values(array_filter($noBin['items'], fn ($i) => $i['kind'] !== 'binary'));
$ok(!rivetAgentFeedLinuxCommand($core, $noBin, 'amd64')['self_contained'], 'without the recorded agent binary the command falls back to Core\'s');
$httpAssets = $assets; $httpAssets['items'][0]['url'] = 'http://x.example.test/a';
foreach ($httpAssets['items'] as &$ii) { $ii['url'] = 'http://x.example.test/a'; } unset($ii);
$ok(!rivetAgentFeedLinuxCommand($core, $httpAssets, 'amd64')['self_contained'], 'an http address is never put into the command');
$evilAssets = $assets; foreach ($evilAssets['items'] as &$ii) { $ii['sha256'] = "x'; touch /tmp/pwned; '"; } unset($ii);
$ok(!rivetAgentFeedLinuxCommand($core, $evilAssets, 'amd64')['self_contained'], 'a recorded hash that is not 64 hex characters is never put into the command');
$qAssets = $assets; foreach ($qAssets['items'] as &$ii) { if ($ii['kind'] !== 'sha') { $ii['url'] = "https://github.com/x'; touch /tmp/pwned2; '"; } } unset($ii);
$cq = rivetAgentFeedLinuxCommand($core, $qAssets, 'amd64');
file_put_contents($sh, $cq['command']);
$ok(!$cq['self_contained'] || str_contains($cq['command'], "'https://github.com/x'\\''; touch /tmp/pwned2; '\\'''"), 'a hostile address is shell-quoted if used at all');
@unlink($sh); exec('rm -rf ' . escapeshellarg($shim));

// ================================================================ 6. the HTTP handler and the page
$refPage = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$adm = fn (array $f, string $who = 'admin', array $h = [], bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $S_[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), array_merge($refPage, $h));
$jsn = ['Accept: application/json'];
$jd = fn (string $b) => json_decode($b, true) ?: [];
$wipe();
$q('UPDATE endpoint_agent_feed SET checked_at = NULL, rate_reset_at = NULL, last_error = \'\'');
[$c, $page] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$card = (function (string $html): string { $a = strpos($html, 'id="agent-updates"'); $b = $a === false ? false : strpos($html, 'id="binaries"', $a); return $a === false ? '' : substr($html, $a, $b === false ? 40000 : $b - $a); })($page);
$ok($c === 200 && $card !== '' && str_contains($card, 'Check for updates') && str_contains($card, 'agent-v0.9.1') && str_contains($card, '0.9.1') && str_contains($card, 'Pre-release') && str_contains($card, 'Not published') && str_contains($card, 'New version available: 0.9.1'),
    'the page has the Agent updates card with the saved releases, the pre-release flag, "Not published" and the new-version badge');
$ok(str_contains($page, '/js/rmm_agent_feed.js') && !preg_match('/\son[a-z]+\s*=\s*["\']|javascript:/i', $card), 'the card\'s script is linked and the card has no inline handlers');
$ok(!str_contains($card, '<script>alert(1)') && !str_contains($card, '<img') && str_contains($card, '&lt;img src=x onerror=alert(1)&gt;'), 'release notes and names from GitHub are escaped');
$ok(str_contains($card, 'name="ring"') && str_contains($card, 'type="range"') && str_contains($card, 'name="rollout_pct"') && str_contains($card, '<option value="stable" selected>') && str_contains($card, 'no pilot devices yet'), 'the Publish form has the ring dropdown and the rollout slider; with no pilot device the default ring is stable');
[$c, $ptmp] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['tech']);
$ok($c !== 200 || !str_contains($ptmp, 'id="agent-updates"'), 'a technician does not get the Agent updates card (' . $c . ')');
// a pilot device exists: the default flips to pilot
$q("INSERT INTO endpoint_agent_devices SET install_id='" . ea_uuid() . "', hostname='PILOT1', agent_version='0.9.0', ring='pilot', client_id=1, last_checkin_at=UTC_TIMESTAMP(), link_state='linked'");
$q("INSERT INTO endpoint_agent_devices SET install_id='" . ea_uuid() . "', hostname='OLDER', agent_version='0.7.1', ring='stable', client_id=1, last_checkin_at=UTC_TIMESTAMP(), link_state='linked'");
$q("INSERT INTO endpoint_agent_devices SET install_id='" . ea_uuid() . "', hostname='RETIRED', agent_version='0.9.0', ring='stable', client_id=1, retired_at=UTC_TIMESTAMP(), link_state='linked'");
$q("INSERT INTO endpoint_agent_devices SET install_id='" . ea_uuid() . "', hostname='WEIRD', agent_version='dev<b>', ring='stable', client_id=1, last_checkin_at=UTC_TIMESTAMP(), link_state='linked'");
[, $page] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$card = (function (string $html): string { $a = strpos($html, 'id="agent-updates"'); $b = $a === false ? false : strpos($html, 'id="binaries"', $a); return $a === false ? '' : substr($html, $a, $b === false ? 40000 : $b - $a); })($page);
$ok(str_contains($card, '<option value="pilot" selected>') && !str_contains($card, 'no pilot devices yet'), 'with a pilot device the Pilot ring is preselected');
$ok(preg_match('~<tr data-tag="agent-v0.9.0">.*?<td class="text-end"><a[^>]*>1</a></td>~s', $card) === 1 && str_contains($card, 'on versions not in this list: 0.7.1 (1), unknown (1)') && str_contains($card, '3 enrolled devices'), 'per-version device counts: 0.9.0 has 1 (the retired one is not counted), 0.7.1 is listed as not in the list, a strange version string is "unknown"');
// the handler
[$c, $b] = $adm(['agent_feed' => 'check'], 'admin', $jsn, false);
$ok($c === 403 && ($jd($b)['success'] ?? null) === false && str_contains($b, 'CSRF'), 'check without a CSRF token: 403 JSON');
[$c, $b] = web($wb, 'POST', '/admin/post.php', $S_['admin'], ['agent_feed' => 'check', 'csrf_token' => 'wrong'], array_merge($refPage, $jsn));
$ok($c === 403, 'check with a wrong CSRF token: 403');
foreach (['tech', 'viewer', 'moduleonly', 'normo', 'deptb'] as $who) {
    $reqReset();
    [$c, $b] = $adm(['agent_feed' => 'check'], $who, $jsn);
    $ok($reqs() === 0 && ($jd($b)['success'] ?? null) !== true, "$who: cannot check for updates (no request to GitHub, status $c)");
    [$c, $b] = $adm(['agent_feed' => 'step', 'tag' => $tag, 'asset' => 'rivetit-agent-windows-amd64.exe', 'activate' => '1'], $who, $jsn);
    $ok($binRows() === [] && ($jd($b)['success'] ?? null) !== true, "$who: cannot publish through the handler (status $c)");
    [$c, $b] = $adm(['agent_feed' => 'save_repo', 'repo' => 'x/y', 'confirm_repo' => '1'], $who, $jsn);
    $ok(rivetAgentFeedRepo($db) === RIVET_AGENT_FEED_DEFAULT_REPO, "$who: cannot change the repository");
}
$reqReset();
$scn([]);
$q('UPDATE endpoint_agent_feed SET checked_at = NULL');
[$c, $b] = $adm(['agent_feed' => 'check'], 'admin', $jsn);
$j = $jd($b);
$ok($c === 200 && ($j['success'] ?? false) === true && isset($j['html']) && str_contains($j['html'], 'id="agent-updates"') && $audit('Agent Feed Checked') >= 1, 'admin: check answers JSON with the new card and writes an audit row');
[$c, $b] = $adm(['agent_feed' => 'plan', 'tag' => $tag], 'admin', $jsn);
$j = $jd($b);
$ok($c === 200 && count($j['steps'] ?? []) === 12 && $j['steps'][0]['asset'] === 'rivetit-agent-windows-amd64.exe' && $j['steps'][0]['store'] === true, 'plan: 12 steps for 0.9.1, the Windows agent first');
foreach (['agent-v7.7.7', 'x', '../../x', 'agent-v0.9.1; id', str_repeat('a', 500)] as $t) { [$c, $b] = $adm(['agent_feed' => 'plan', 'tag' => $t], 'admin', $jsn); $ok($c === 404 && ($jd($b)['success'] ?? null) === false, 'plan for ' . json_encode(substr($t, 0, 20)) . ' is refused (' . $c . ')'); }
[$c, $b] = $adm(['agent_feed' => 'step', 'tag' => $tag, 'asset' => 'rivetit-agent-windows-amd64.exe', 'activate' => '1', 'offer' => '1', 'ring' => 'pilot', 'rollout_pct' => '30'], 'admin', $jsn);
$j = $jd($b);
$ok($c === 200 && ($j['status'] ?? '') === 'stored' && count($binRows()) === 1 && count($relRows()) === 1 && $relRows()[0]['ring'] === 'pilot' && (int) $relRows()[0]['rollout_pct'] === 30, 'step through the handler: stored, offered to pilot at 30%');
foreach ([['ring' => 'gold'], ['ring' => 'pilot; DROP TABLE x'], ['rollout_pct' => '9999'], ['rollout_pct' => '-5'], ['rollout_pct' => 'abc']] as $bad) {
    $wipe();
    [$c, $b] = $adm(['agent_feed' => 'step', 'tag' => $tag, 'asset' => 'rivetit-agent-windows-arm64.exe', 'offer' => '1'] + $bad, 'admin', $jsn);
    $rr = $relRows();
    $good = $rr === [] || (in_array($rr[0]['ring'], ['pilot', 'stable'], true) && (int) $rr[0]['rollout_pct'] >= 0 && (int) $rr[0]['rollout_pct'] <= 100);
    $ok($good && $c === 200, 'hostile ring/rollout ' . json_encode($bad) . ' cannot create an invalid release row (' . json_encode(array_map(fn ($r) => [$r['ring'], $r['rollout_pct']], $rr)) . ')');
}
[$c, $b] = $adm(['agent_feed' => 'step', 'tag' => $tag, 'asset' => '../../../etc/passwd'], 'admin', $jsn);
$ok($c === 422 && ($jd($b)['status'] ?? '') === 'refused', 'step with a hostile asset name: refused');
[$c, $b] = $adm(['agent_feed' => 'nonsense'], 'admin', $jsn);
$ok($c === 422, 'an unknown agent_feed action is refused');
// the repository form
[$c, $b] = $adm(['agent_feed' => 'save_repo', 'repo' => 'someone/fork'], 'admin', $jsn);
$ok($c === 422 && rivetAgentFeedRepo($db) === RIVET_AGENT_FEED_DEFAULT_REPO, 'a different repository without "I trust this repository" is refused');
foreach (['bad name', '../x/y', 'a/b/c', "a/b\nc", 'https://github.com/a/b', 'a/b;id'] as $rp) { [$c, $b] = $adm(['agent_feed' => 'save_repo', 'repo' => $rp, 'confirm_repo' => '1'], 'admin', $jsn); $ok($c === 422 && rivetAgentFeedRepo($db) === RIVET_AGENT_FEED_DEFAULT_REPO, 'hostile repository ' . json_encode($rp) . ' is refused'); }
$a2 = $audit('Agent Feed Repository Changed');
[$c, $b] = $adm(['agent_feed' => 'save_repo', 'repo' => 'someone/fork', 'confirm_repo' => '1'], 'admin', $jsn);
$ok($c === 200 && rivetAgentFeedRepo($db) === 'someone/fork' && $audit('Agent Feed Repository Changed') === $a2 + 1, 'with the confirmation the repository is changed and audited');
$reqReset();
$q('UPDATE endpoint_agent_feed SET checked_at = NULL');
$adm(['agent_feed' => 'check'], 'admin', $jsn);
$ok($reqs('/repos/someone/fork/releases') === 1, 'the next check asks GitHub about the new repository');
$adm(['agent_feed' => 'save_repo', 'repo' => RIVET_AGENT_FEED_DEFAULT_REPO], 'admin', $jsn);
$q('UPDATE endpoint_agent_feed SET checked_at = NULL');
$adm(['agent_feed' => 'check'], 'admin', $jsn);
// the no-JavaScript path: publish in one request and a flash message
$wipe();
$ref = web($wb, 'POST', '/admin/post.php', $S_['admin'], ['agent_feed' => 'publish', 'tag' => $tag, 'activate' => '1', 'csrf_token' => 'csrftok1'], $refPage);
$ok($ref[0] === 302 && count($binRows()) === 2 && count($msiRows()) === 1 && count($linRows()) === 9, 'the plain form post (no JavaScript) publishes every file in one request and redirects (' . $ref[0] . ')');
[, $page] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$ok(str_contains(str_replace('\\/', '/', $page), 'All 12 files of agent-v0.9.1 are in place'), 'the flash message says what happened');
[, $card] = [0, (function (string $html): string { $a = strpos($html, 'id="agent-updates"'); $b = $a === false ? false : strpos($html, 'id="binaries"', $a); return $a === false ? '' : substr($html, $a, $b === false ? 40000 : $b - $a); })($page)];
$ok(preg_match('~<tr data-tag="agent-v0.9.1">.*?Published~s', $card) === 1 && str_contains($card, 'exe: x64, ARM64') && str_contains($card, 'msi: x64') && str_contains($card, 'Linux files: 9'), 'the card now shows 0.9.1 as Published with what is stored');
// the Agent binaries and MSI cards show what the publish stored (same rows)
$ok(str_contains($page, '>0.9.1<') && str_contains($page, hash('sha256', $F['rivetit-agent-windows-amd64.exe'])), 'Agent binaries lists the published file with its SHA-256');
// new code, old schema
$q('DROP TABLE endpoint_agent_linux_assets');
[$c, $page] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_['admin']);
$ok($c === 200 && str_contains($page, 'Run the database update first') && str_contains($page, 'id="agent-updates"') && str_contains($page, 'id="binaries"'), 'new code, old schema: the page still loads and the card says to run the update');
[$c, $b] = $adm(['agent_feed' => 'check'], 'admin', $jsn);
$ok($c === 200 && ($jd($b)['success'] ?? null) === false && str_contains($b, 'database update'), 'new code, old schema: Check says to run the update (no exception)');
$q("CREATE TABLE IF NOT EXISTS `endpoint_agent_linux_assets` (`asset_id` int(11) NOT NULL AUTO_INCREMENT, `version` varchar(40) NOT NULL, `kind` varchar(12) NOT NULL, `arch` varchar(10) NOT NULL DEFAULT '', `filename` varchar(160) NOT NULL, `url` varchar(500) NOT NULL, `sha256` char(64) NOT NULL, `size_bytes` bigint(20) NOT NULL DEFAULT 0, `sha_source` varchar(20) NOT NULL DEFAULT 'SHA256SUMS', `active` tinyint(1) NOT NULL DEFAULT 1, `is_current` tinyint(1) NOT NULL DEFAULT 0, `created_by` int(11) NOT NULL DEFAULT 0, `created_at` datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`asset_id`), UNIQUE KEY `uniq_version_kind_arch` (`version`,`kind`,`arch`), KEY `idx_current` (`is_current`,`active`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ================================================================ 7. the real 0.9.1 release (optional)
$realDir = (string) getenv('FEED_REAL_DIR');
if ($realDir !== '' && is_file("$realDir/SHA256SUMS")) {
    $wipe();
    $rt = 'agent-v0.9.1';
    mkdir("$mockDir/assets/$rt-real", 0775, true);
    $realFiles = ['SHA256SUMS', 'SHA256SUMS.msi', 'install-linux.sh', 'rivetit-agent-windows-amd64.exe', 'rivetit-agent-windows-arm64.exe', 'rivetit-agent-windows-amd64.msi'];
    @unlink("$mockDir/assets/$rt/dummy");
    foreach ($realFiles as $n) { copy("$realDir/$n", "$mockDir/assets/$rt/$n"); }
    $ra = [];
    foreach ($realFiles as $n) { $ra[] = ['name' => $n, 'size' => filesize("$realDir/$n"), 'digest' => 'sha256:' . hash_file('sha256', "$realDir/$n")]; }
    file_put_contents("$mockDir/releases.json", json_encode([['tag_name' => $rt, 'name' => 'real', 'draft' => false, 'prerelease' => true, 'published_at' => '2026-10-18T17:16:53Z', 'body' => '', 'assets' => $ra]]));
    $q('UPDATE endpoint_agent_feed SET checked_at = NULL'); rivetAgentFeedRefresh($db, true);
    $all = rivetAgentFeedPublishAll($db, $rmm, $admin, $rt, $opt());
    $ok($all['ok'] && count($binRows()) === 2 && count($msiRows()) === 1 && (string) $msiRows()[0]['arch_source'] === 'summary', 'REAL 0.9.1 files: x64 and ARM64 agents and the MSI template pass the checksums and Core\'s checks (' . $all['message'] . ' ' . json_encode(array_column($all['steps'], 'message')) . ')');
    $real = array_column($binRows(), null, 'arch');
    $ok(($real['amd64']['sha256'] ?? '') === 'f05f20d7c38041679b21f0d4463b18e651a2db60685ba02ea37698ee9018610d' && ($real['arm64']['sha256'] ?? '') === '866671e2573121c3527b075edf4d3da29e956c5a9c2c33c4790d0f1b663c8cbd' && $msiRows()[0]['sha256'] === '8db83652d4c7edea40785a6387605967df0090000ef03f86bbe8eafcad9cb0b0', 'the stored hashes are the ones GitHub published for 0.9.1');
}
echo "\nfeed suite done\n";
