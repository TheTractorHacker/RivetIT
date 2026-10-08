<?php
/*
 * "Add device" / "Download installer" (Agent Fleet page, department page, Endpoints menu, agent/post/rmm_installer.php) and the "Publish the agent"
 * drag-and-drop upload in Administration. REAL HTTP against php -S on a scratch database; same harness and rules as tests/endpoint_agent_lib.php.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... EA_TEST_NON_WINDOWS=1 RMM_GATE_STATE_DIR= php tests/endpoint_agent_installer_ui.php
 *
 * Covers: who sees the button and the dialog (and that nothing renders for anyone else or with the module off), the endpoint's method / CSRF /
 * permission / module-off / department-scope refusals (and that a refusal creates no token), the streamed .exe (MZ header, the current binary byte for
 * byte, a trailer InstallerStamp verifies, exact length, file name, token lifetime / uses / audit row), the Linux command (token, audit, no binary), and
 * the multi-file publish (architecture from the file's own header, version from the file name, both architectures in one upload).
 */
require __DIR__ . '/endpoint_agent_lib.php';
use ITFlow\EndpointAgent\Binaries; use ITFlow\EndpointAgent\Config;
use ITFlow\EndpointAgent\InstallerStamp as S;

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

$sdir = sys_get_temp_dir() . '/ea_isessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$webSrv = ea_start_php($root . '/tests/mobile_api_router.php', [], ["session.save_path=$sdir", 'upload_max_filesize=64M', 'post_max_size=70M', 'memory_limit=16M']);
$wb = "http://127.0.0.1:{$webSrv['port']}";
$base = $wb;   // the service URL the installer carries (http is allowed on loopback by EA_ALLOW_INSECURE_HTTP)
$S_ = []; foreach ([1 => 'admin', 10 => 'tech', 12 => 'viewer', 14 => 'moduleonly', 15 => 'normo', 16 => 'deptb'] as $uid => $n) { $S_[$n] = ea_forge_session($sdir, $uid); }

function wpost(string $baseUrl, string $path, string $sid, array $fields, array $files = [], array $headers = []): array
{
    $ch = curl_init($baseUrl . $path);
    foreach ($files as $k => $f) { $fields[$k] = new CURLFile($f[0], 'application/octet-stream', $f[1]); }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers]);
    $raw = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, substr((string) $raw, $hs), substr((string) $raw, 0, $hs)];
}
$tmp = sys_get_temp_dir() . '/ea_inst_' . bin2hex(random_bytes(4)); mkdir($tmp);
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) { @unlink($f); } @rmdir($tmp); });
$put = function (string $name, string $bytes) use ($tmp) { file_put_contents("$tmp/$name", $bytes); return "$tmp/$name"; };
$hdr = function (string $headers, string $name): ?string { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', $headers, $m) ? $m[1] : null; };
$EP = '/agent/post/rmm_installer.php';
$REFA = ['Referer: ' . $wb . '/agent/rmm_fleet.php'];
$JSON = ['Accept: application/octet-stream, application/json', 'Referer: ' . $wb . '/agent/rmm_fleet.php'];
$ep = fn(array $f, string $who = 'admin', array $h = null, bool $csrf = true) => wpost($wb, $EP, $S_[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), [], $h ?? $JSON);
$toks = fn() => (int) $one("SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens");
$bins = fn() => (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries");
$fleet = fn(string $who = 'admin', string $qs = '') => web($wb, 'GET', '/agent/rmm_fleet.php' . $qs, $S_[$who]);
$jd = fn(string $b) => json_decode($b, true);

Config::enable();
Config::set(['service_url' => $base]);

// ============================================================ before any binary exists: the empty state, and no way to build an installer
[$c, $b] = $fleet();
$ok($c === 200 && strpos($b, 'id="rmmInstallerModal"') !== false && strpos($b, 'Add device') !== false, 'admin: the Agent Fleet page has the Add device button and the dialog');
$ok(strpos($b, 'data-have-amd64="0"') !== false && strpos($b, 'data-have-arm64="0"') !== false && strpos($b, '/admin/settings_endpoint_agent.php#binaries') !== false && strpos($b, 'rmm-inst-nobinary') !== false, 'no binary yet: the dialog knows it and links to Agent binaries');
$ok(strpos($b, '/js/rmm_installer.js') !== false, 'the dialog script is linked on the page that renders the dialog');
$t0 = $toks();
[$c, $body] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'amd64']);
$d = $jd($body);
$ok($c === 422 && $d['success'] === false && strpos($d['error'], 'No agent binary') !== false && $toks() === $t0, 'no binary: the endpoint refuses with the reason and creates no token');

// ============================================================ publish the agent: two files in one upload (drag-and-drop form)
$exeAmd = fakePe(0x8664, 300000, 'amd'); $exeArm = fakePe(0xAA64, 200000, 'arm');
$admin = fn(array $f, array $files = [], string $who = 'admin') => wpost($wb, '/admin/post.php', $S_[$who], $f + ['csrf_token' => 'csrftok1'], $files, ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$adminPage = fn(string $who = 'admin') => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S_[$who]);
$msg = function () use ($adminPage) { [, $b] = $adminPage(); return html_entity_decode(strip_tags($b)); };
$multi = function (array $files, array $fields = [], string $who = 'admin') use ($wb, $S_) {
    $ch = curl_init($wb . '/admin/post.php');
    $f = $fields + ['csrf_token' => 'csrftok1', 'upload_agent_binaries' => '1'];
    $i = 0; foreach ($files as [$path, $name]) { $f["agent_binaries[$i]"] = new CURLFile($path, 'application/octet-stream', $name); $i++; }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $f, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => "PHPSESSID={$S_[$who]}", CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return $code;
};
[$c, $b] = $adminPage();
$ok(strpos($b, 'id="bn_drop"') !== false && strpos($b, 'name="agent_binaries[]"') !== false && strpos($b, 'multiple') !== false && strpos($b, 'name="activate" value="1" checked') !== false, 'admin page: drag-and-drop publish zone, several files, "make current" on by default');
foreach (['tech', 'viewer', 'deptb'] as $who) { $multi([[$put('rivetit-agent-9.9.9-windows-amd64.exe', $exeAmd), 'rivetit-agent-9.9.9-windows-amd64.exe']], ['activate' => 1], $who); }
$ok($bins() === 0, 'publish denied to a technician, a viewer and a department user (no row)');
$multi([[$put('a.exe', $exeAmd), 'rivetit-agent-1.4.0-windows-amd64.exe'], [$put('b.exe', $exeArm), 'rivetit-agent-1.4.0-windows-arm64.exe']], ['activate' => 1]);
$ok($bins() === 2 && $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version='1.4.0' AND arch='amd64' AND is_current=1") == 1 && $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version='1.4.0' AND arch='arm64' AND is_current=1") == 1,
    'both architectures published in one upload, version 1.4.0 read from the file names, both current');
$m_ = $msg();
$ok(strpos($m_, 'Published') !== false && strpos($m_, '(x64)') !== false && strpos($m_, '(ARM64)') !== false && strpos($m_, 'now used for new installers') !== false, 'the flash message names each file, its version and architecture');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Binary Uploaded' AND log_user_id=1") === 2, 'each file has its own audit entry');
// arch comes from the file, never from its name
$multi([[$put('c.exe', fakePe(0xAA64, 150000, 'x')), 'rivetit-agent-1.5.0-amd64.exe']], ['activate' => 1]);
$ok($one("SELECT arch FROM endpoint_agent_binaries WHERE version='1.5.0'") === 'arm64', 'the architecture is read from the executable header, not trusted from the file name');
$multi([[$put('d.exe', $exeAmd), 'agent.exe']]);
$ok($bins() === 3 && strpos($msg(), 'version is not in the file name') !== false, 'no version in the name and none typed: refused with the fix');
$multi([[$put('e.exe', str_repeat('not an exe ', 500)), 'rivetit-agent-2.0.0.exe']]);
$ok($bins() === 3 && strpos($msg(), 'Not published') !== false, 'a non-executable is refused');
$multi([[$put('f.exe', fakePe(0x8664, 160000, 'f')), 'rivetit-agent-2.0.0-a.exe'], [$put('g.exe', fakePe(0x8664, 170000, 'g')), 'rivetit-agent-2.0.0-b.exe']]);
$ok($bins() === 4 && strpos($msg(), 'a second amd64 file') !== false, 'two files of the same architecture in one upload: the first is published, the second explained');
$multi([[$put('h.exe', fakePe(0x8664, 160000, 'h')), 'x.exe']], ['version' => '3.1.0', 'activate' => 1]);
$ok($one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version='3.1.0' AND is_current=1") == 1, 'a typed version wins over the file name');
// back to the 1.4.0 pair as the current binaries for the rest of the suite
foreach (['amd64', 'arm64'] as $a) { $id = (int) $one("SELECT binary_id FROM endpoint_agent_binaries WHERE version='1.4.0' AND arch='$a'"); $admin(['binary_action' => 'make_current', 'binary_id' => $id]); }
$cur = Binaries::current('amd64'); $curBytes = file_get_contents("$binDir/{$cur['storage_name']}");
$curArm = Binaries::current('arm64'); $curArmBytes = file_get_contents("$binDir/{$curArm['storage_name']}");
$ok($cur['version'] === '1.4.0' && $curArm['version'] === '1.4.0', 'the 1.4.0 pair is current');

// ============================================================ who sees the button
[$c, $b] = $fleet();
$ok(strpos($b, 'data-have-amd64="1"') !== false && strpos($b, 'data-have-arm64="1"') !== false && strpos($b, 'id="rmm-inst-nobinary"') !== false, 'with binaries: the dialog says both architectures are available');
$ok(preg_match('~Download installer</span>\s*</a>~', $b) === 1 && strpos($b, '/agent/rmm_fleet.php?installer=1') !== false, 'the Endpoints menu has a Download installer entry for the administrator');
$ok(strpos($b, '<option value="1">Dept A</option>') !== false && strpos($b, '<option value="2">Dept B</option>') !== false, 'the department select lists every department the administrator can see');
[$c, $b] = $fleet('admin', '?client_id=2');
$ok(strpos($b, '<option value="2" selected>Dept B</option>') !== false, 'the department is preselected from the page context (?client_id=)');
[$c, $b] = $fleet('admin', '?client_id=999');
$ok(strpos($b, ' selected>Dept') === false, 'an unknown department is not preselected');
foreach (['tech', 'viewer', 'moduleonly'] as $who) {
    [$c, $b] = $fleet($who);
    $ok(strpos($b, 'rmmInstallerModal') === false && strpos($b, 'rmm_installer.js') === false && strpos($b, 'installer=1') === false && strpos($b, 'Add device') === false && strpos($b, 'Download installer') === false, "$who: no button, no dialog, no script, no menu entry (status $c)");
}

// the department page
[$c, $b] = web($wb, 'GET', '/agent/client_overview.php?client_id=1', $S_['admin']);
$ok($c === 200 && strpos($b, 'data-rmm-installer-client="1"') !== false && strpos($b, 'Download agent installer') !== false && strpos($b, 'id="rmmInstallerModal"') !== false && strpos($b, 'data-fixed="1"') !== false && strpos($b, '/js/rmm_installer.js') !== false, 'the department page has the action in its menu and the dialog fixed to that department');
$ok(strpos($b, 'id="rmm-inst-client" name="client_id" required') === false, 'on the department page the department is not a select');
[$c, $b] = web($wb, 'GET', '/agent/client_overview.php?client_id=1', $S_['tech']);
$ok(strpos($b, 'rmmInstallerModal') === false && strpos($b, 'Download agent installer') === false, 'the department page shows a technician nothing');

// ============================================================ the endpoint: refusals create nothing
$t0 = $toks(); $log0 = (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Installer Created'");
[$c, $b] = web($wb, 'GET', $EP, $S_['admin']);
$ok($c === 405 && $toks() === $t0, 'GET is refused (405)');
[$c, $b, $h] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'amd64'], 'admin', null, false);
$ok($c === 403 && strpos((string) $hdr($h, 'Content-Type'), 'octet-stream') === false && $toks() === $t0, 'no CSRF token: 403, no file, no token (browser form post)');
[$c, $b] = wpost($wb, $EP, $S_['admin'], ['action' => 'download', 'client_id' => 1, 'arch' => 'amd64', 'csrf_token' => 'wrong'], [], $JSON);
$ok($c === 403 && ($jd($b)['code'] ?? '') === 'csrf' && $toks() === $t0, 'wrong CSRF token: 403 with a JSON reason for the dialog');
foreach (['tech', 'viewer', 'moduleonly', 'normo', 'deptb'] as $who) {
    [$c, $b, $h] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'amd64'], $who);
    $ok($c === 403 && strpos((string) $hdr($h, 'Content-Type'), 'octet-stream') === false && $toks() === $t0, "download denied to $who (403, no token)");
}
[$c, $b] = $ep(['action' => 'linux', 'client_id' => 1, 'arch' => 'amd64'], 'tech');
$ok($c === 403 && $toks() === $t0 && strpos($b, 'rvte1') === false, 'Linux command denied to a technician (403, no token)');
$t0 = $toks();
[$c, $b] = $ep(['action' => 'locations', 'client_id' => 1]);
$ok($c === 200 && $jd($b)['success'] === true && is_array($jd($b)['locations']), 'locations for a department the user can see');
[$c, $b] = $ep(['action' => 'frobnicate', 'client_id' => 1]);
$ok($c === 422 && $toks() === $t0, 'an unknown action is refused');
[$c, $b] = $ep(['action' => 'download', 'client_id' => 999, 'arch' => 'amd64']);
$ok(in_array($c, [403, 422], true) && $toks() === $t0, 'an unknown department creates no token');
[$c, $b] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'sparc']);
$ok($c === 200 && $toks() === $t0 + 1, 'an unknown architecture falls back to x64 (never reaches Core as-is)');
$t0 = $toks();

// ============================================================ the download: the real file
$q("DELETE FROM logs");
[$c, $body, $h] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 1, 'label' => 'Front <b>desk</b>', 'location_id' => 0]);
$sp = S::read($body); $d = $sp['data'] ?? [];
$ok($c === 200 && strpos((string) $hdr($h, 'Content-Type'), 'application/octet-stream') === 0, 'authorized administrator gets an octet-stream response');
$ok(substr($body, 0, 2) === 'MZ', 'the file starts with the PE signature MZ');
$ok($hdr($h, 'Content-Disposition') === 'attachment; filename="RivetIT-Agent-Setup-dept-a-x64.exe"', 'file name RivetIT-Agent-Setup-<department>-x64.exe: ' . $hdr($h, 'Content-Disposition'));
$ok((int) $hdr($h, 'Content-Length') === strlen($body) && $hdr($h, 'X-Content-Type-Options') === 'nosniff' && strpos((string) $hdr($h, 'Cache-Control'), 'no-store') !== false, 'exact Content-Length, nosniff, no-store');
$ok($sp !== null && substr($body, 0, $sp['exe_length']) === $curBytes, 'the stamped file is the CURRENT x64 binary byte for byte plus a trailer InstallerStamp verifies');
$ok(($d['department'] ?? '') === 'Dept A' && ($d['server_url'] ?? '') === $base && preg_match('/^rvte1\.[0-9a-f]{12}\.[0-9a-f]{40}$/', (string) ($d['enrollment_token'] ?? '')) === 1, 'the payload carries the department, the server URL and a fresh enrollment token');
$row = $rows("SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1")[0];
$ok($toks() === $t0 + 1 && (int) $row['max_uses'] === 1 && $row['ring'] === 'stable' && (int) $row['client_id'] === 1 && abs(strtotime($row['expires_at'] . ' UTC') - (time() + 24 * 3600)) < 60 && strpos($row['label'], 'Front') === 0, 'one token: 1 use, 24 hours, stable ring, the department, the label');
$ok((string) $one("SELECT COUNT(*) FROM logs WHERE log_action='Installer Created' AND log_user_id=1 AND log_description LIKE '%{$d['installer_id']}%' AND log_description LIKE '%Dept A%'") === '1', 'one audit entry naming the actor, installer id and department (same path as the Administration card)');
// several PCs, pilot ring, ARM64
[$c, $body, $h] = $ep(['action' => 'download', 'client_id' => 2, 'arch' => 'arm64', 'ring' => 'pilot', 'ttl_hours' => 48, 'max_uses' => 25]);
$sp = S::read($body); $row = $rows("SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1")[0];
$ok($c === 200 && $sp && substr($body, 0, $sp['exe_length']) === $curArmBytes && substr($body, 0, 2) === 'MZ' && strpos((string) $hdr($h, 'Content-Disposition'), 'RivetIT-Agent-Setup-dept-b-arm64.exe') !== false, 'ARM64: the arm64 binary, Dept B, the arm64 file name');
$ok((int) $row['max_uses'] === 25 && $row['ring'] === 'pilot' && abs(strtotime($row['expires_at'] . ' UTC') - (time() + 48 * 3600)) < 60, 'several PCs: 25 uses, 48 hours, pilot ring');
// defaults when the dialog sends no advanced fields
$t0 = $toks();
[$c, $body] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'amd64']);
$row = $rows("SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1")[0];
$ok($c === 200 && (int) $row['max_uses'] === 1 && abs(strtotime($row['expires_at'] . ' UTC') - (time() + 24 * 3600)) < 60, 'defaults: one PC, 24 hours');
// a plain browser form post (no JSON Accept) also streams the file
[$c, $body, $h] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'amd64'], 'admin', $REFA);
$ok($c === 200 && substr($body, 0, 2) === 'MZ' && S::read($body) !== null, 'a plain form post (no JavaScript) downloads the same file');

// ============================================================ refusals that come from Core
$t0 = $toks();
Config::set(['enabled' => 0]);
[$c, $b] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'amd64']);
$ok($c === 404 && ($jd($b)['code'] ?? '') === 'module_off' && $toks() === $t0, 'module switched off: the endpoint answers 404 and creates nothing');
[$c, $b] = $fleet();
$ok(strpos($b, 'rmmInstallerModal') === false && strpos($b, 'Add device') === false && strpos($b, 'rmm_installer.js') === false, 'module off: the Agent Fleet page renders no button, no dialog, no script');
[$c, $b] = web($wb, 'GET', '/agent/client_overview.php?client_id=1', $S_['admin']);
$ok(strpos($b, 'rmmInstallerModal') === false && strpos($b, 'Download agent installer') === false && strpos($b, 'rmm_installer.js') === false, 'module off: the department page renders nothing either');
Config::set(['enabled' => 1]);
Config::set(['service_url' => '']);
[$c, $b] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'amd64']);
$ok($c === 422 && strpos($jd($b)['error'], 'https') !== false && $toks() === $t0, 'no service URL: refused with the reason, no token');
[$c, $b] = $fleet();
$ok(strpos($b, 'The service URL is not an https:// address') !== false && strpos($b, 'data-service-ok="0"') !== false, 'no service URL: the dialog says so up front');
Config::set(['service_url' => $base]);
$id = (int) $one("SELECT binary_id FROM endpoint_agent_binaries WHERE version='1.4.0' AND arch='arm64'");
$admin(['binary_action' => 'deactivate', 'binary_id' => $id]);
[$c, $b] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'arm64']);
$ok($c === 422 && strpos($jd($b)['error'], 'No agent binary') !== false && $toks() === $t0, 'no ARM64 binary: refused, no token');
[$c, $b] = $fleet();
$ok(strpos($b, 'data-have-amd64="1"') !== false && strpos($b, 'data-have-arm64="0"') !== false, 'the dialog knows ARM64 is unavailable while x64 is');
$admin(['binary_action' => 'activate', 'binary_id' => $id]);
// a plain form post that is refused goes back with a message, not a blank page
[$c, $b, $h] = $ep(['action' => 'download', 'client_id' => 1, 'arch' => 'arm64'], 'admin', $REFA);
$admin(['binary_action' => 'make_current', 'binary_id' => $id]);

// ============================================================ Linux: the command, no binary
$t0 = $toks(); $q("DELETE FROM logs");
[$c, $b, $h] = $ep(['action' => 'linux', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 1]);
$d = $jd($b);
$ok($c === 200 && $d['success'] === true && strpos((string) $hdr($h, 'Content-Type'), 'application/json') === 0 && strpos((string) $hdr($h, 'Cache-Control'), 'no-store') !== false, 'Linux: a JSON answer, never cached');
$ok(strpos($d['command'], './install-linux.sh --server ' ) !== false && strpos($d['command'], "'$base'") !== false && preg_match('/rvte1\.[0-9a-f]{12}\.[0-9a-f]{40}/', $d['command'], $m) === 1 && $d['department'] === 'Dept A' && substr($b, 0, 2) !== 'MZ', 'the command carries the server and a token and runs install-linux.sh; there is no binary in the answer');
$row = $rows("SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1")[0];
$ok($toks() === $t0 + 1 && strpos($d['command'], explode('.', $m[0])[1]) !== false && (int) $row['token_id'] === $d['token_id'] && (int) $row['max_uses'] === 1, 'a token was created for it (1 use)');
$ok((string) $one("SELECT COUNT(*) FROM logs WHERE log_action='Installer Created' AND log_user_id=1 AND log_description LIKE '%Dept A%'") === '1', 'one audit entry');
[$c, $b] = $ep(['action' => 'linux', 'client_id' => 1, 'arch' => 'arm64', 'max_uses' => 25]);
$ok($c === 200 && $jd($b)['arch'] === 'arm64' && $jd($b)['max_uses'] === 25, 'Linux ARM64, several machines');

// ============================================================ the menu entry opens the dialog
[$c, $b] = $fleet('admin', '?installer=1');
$ok($c === 200 && strpos($b, 'id="rmmInstallerModal"') !== false, 'the menu entry (?installer=1) lands on the page that carries the dialog');
