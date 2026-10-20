<?php
/*
 * Fragment of tests/rmm_ui.php (RivetIT 26.10.52): the "Add device / Build installer" page (agent/rmm_add_device.php, js/rmm_add_device.js) and the two endpoint actions it uses
 * (agent/post/rmm_installer.php action=windows_commands and the Linux action with recorded Linux assets). Shares that file's variables.
 *
 *   1. who sees the page (administrators only: rmm.token.manage), the module-off notice, what the page says before anything is published;
 *   2. the page with published files: the version chips, the Linux packages (https links only, hostile names escaped, hashes);
 *   3. windows_commands: the silent-install commands (.exe, and with a template the MSI block), every refusal and that a refusal leaves no token;
 *   4. the Linux action: Core's own text when nothing is recorded, a self-contained command (valid shell, hashes, quoting) when it is;
 *   5. END TO END: the stamped .exe and the stamped .msi downloaded through the endpoint carry a payload whose token really enrolls a device (once; the second use is refused).
 *
 * The module is ON here and ends ON; the files, rows and the service address this fragment set are put back.
 */

use RivetCore\Rmm\Installer\InstallerStamp as AdStamp;

require_once dirname(__DIR__) . '/includes/rmm_agent_feed.php';

function ad_fake_pe(int $machine, int $size, string $fill): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0");
    $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $need = $size - strlen($b);

    return $b . substr(str_repeat(hash('sha256', $fill . $machine, true), intdiv($need, 32) + 1), 0, $need);
}

$adSvc0 = (string) ($rmm->settings()->get(true)['service_url'] ?? '');
$rmm->admin()->enable($admin);
$rmm->settings()->set(['service_url' => $base]);   // the loopback http address is allowed by EA_ALLOW_INSECURE_HTTP (scratch config only)
$fresh();
$q('DELETE FROM endpoint_agent_binaries');
$q('DELETE FROM endpoint_agent_msi_templates');
if (rivetAgentFeedReady($db)) { $q('DELETE FROM endpoint_agent_linux_assets'); }
$adPage = fn (string $who = 'admin', string $qs = '') => web($wb, 'GET', '/agent/rmm_add_device.php' . $qs, $SS[$who]);
$adRef = ['Referer: ' . $wb . '/agent/rmm_add_device.php'];
$adEp = fn (array $f, string $who = 'admin', bool $csrf = true, ?array $h = null) => web($wb, 'POST', '/agent/post/rmm_installer.php', $SS[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), $h ?? array_merge(['Accept: application/json'], $adRef));
$adJson = fn (string $b) => json_decode($b, true) ?: [];
$adTokens = fn () => (int) $one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens');
$adNoInline = fn (string $html) => preg_match('/\son[a-z]+\s*=\s*["\']|javascript:/i', $html) !== 1;

// ================================================================ 1. before anything is published
[$c, $p] = $adPage();
$ok($c === 200 && str_contains($p, 'id="ad-root"') && str_contains($p, 'Add device / Build installer') && $adNoInline($p), 'the page renders for the administrator, without inline handlers');
$ok(str_contains($p, 'data-have-exe-amd64="0"') && str_contains($p, 'data-have-exe-arm64="0"') && str_contains($p, 'data-have-msi-amd64="0"') && str_contains($p, 'data-have-linux="0"'), 'nothing published: the page knows it (no exe, no msi, no Linux files)');
$ok(substr_count($p, '/admin/settings_endpoint_agent.php#agent-updates') >= 3 && str_contains($p, 'not published'), 'it sends the administrator to Agent updates to publish');
foreach (['id="ad-dl-exe"', 'id="ad-dl-msi"', 'id="ad-w-cmds"', 'id="ad-l-go"', 'name="platform" id="ad-p-wx64"', 'name="platform" id="ad-p-warm"', 'name="platform" id="ad-p-lx64"', 'name="platform" id="ad-p-larm"', 'name="client_id"', 'name="ring"', 'name="ttl_hours"', 'name="max_uses"', 'name="csrf_token"', '/js/rmm_add_device.js'] as $needle) {
    $ok(str_contains($p, $needle), "add device page contains $needle");
}
$ok(str_contains($p, '<option value="1">Dept A</option>') && str_contains($p, '<option value="2">Dept B</option>'), 'the department select lists the departments the administrator can see');
[, $fleet] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok(str_contains($fleet, 'href="/agent/rmm_add_device.php"') && preg_match('~href="/agent/rmm_add_device.php"[^>]*>\s*<span class="dropdown-item-icon">.*?<span class="text-truncate">Add device</span>~s', $fleet) === 1 && !str_contains($fleet, '<span class="text-truncate">Download installer</span>') && !str_contains($fleet, 'href="/agent/rmm_fleet.php?installer=1"'), 'the Endpoints menu has ONE entry, "Add device" (the full page); the duplicate "Download installer" item is gone');
$ok(str_contains($fleet, 'Open the full Add device page') , 'the Add device dialog links to the full page');
foreach (['tech', 'viewer', 'moduleonly', 'normo', 'deptb'] as $who) {
    [$c, $b] = $adPage($who);
    $ok(!str_contains($b, 'id="ad-root"') && !str_contains($b, 'rmm_add_device.js'), "$who: no installer page (status $c)");
    [$c, $b] = $adEp(['action' => 'windows_commands', 'client_id' => 1, 'arch' => 'amd64'], $who);
    $ok($c === 403 && !str_contains($b, 'rvte1.'), "$who: windows_commands is refused (403)");
}
$adT0 = $adTokens();
[$c, $b] = $adEp(['action' => 'windows_commands', 'client_id' => 1, 'arch' => 'amd64'], 'admin', false);
$ok($c === 403 && ($adJson($b)['code'] ?? '') === 'csrf' && $adTokens() === $adT0, 'windows_commands without a CSRF token: 403 and no token');
[$c] = web($wb, 'GET', '/agent/post/rmm_installer.php?action=windows_commands&client_id=1', $SS['admin']);
$ok($c === 405, 'windows_commands by GET: 405');
$rmm->admin()->disable($admin); $fresh();
[$c, $b] = $adPage();
$ok(!str_contains($b, 'id="ad-root"') && str_contains($b, 'switched off'), 'module off: the page says so and draws nothing');
[$c, $b] = $adEp(['action' => 'windows_commands', 'client_id' => 1, 'arch' => 'amd64']);
$ok($c === 404 && ($adJson($b)['code'] ?? '') === 'module_off' && $adTokens() === $adT0, 'module off: windows_commands answers 404 and creates nothing');
$rmm->admin()->enable($admin); $fresh();
// no agent published yet: the endpoint refuses with the reason and leaves no token
[$c, $b] = $adEp(['action' => 'windows_commands', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'exe']);
$ok($c === 422 && str_contains((string) ($adJson($b)['error'] ?? ''), 'No agent binary is published') && $adTokens() === $adT0, 'windows_commands with no agent published: refused with the reason, no token');
// Linux with nothing recorded or hosted (26.10.64): a clear message with the fix, no token, no broken command
[$c, $b] = $adEp(['action' => 'linux', 'client_id' => 1, 'arch' => 'amd64']);
$j = $adJson($b);
$ok($c === 409 && ($j['success'] ?? true) === false && str_contains((string) $j['error'], 'Build or publish the agent first') && !str_contains($b, 'install-linux.sh --server') && $adTokens() === $adT0, 'Linux with nothing recorded or hosted: the fix message, no command, no token');

// ================================================================ 2. published files
$adTmp = sys_get_temp_dir() . '/ad_' . bin2hex(random_bytes(3)); mkdir($adTmp);
register_shutdown_function(function () use ($adTmp) { foreach (glob("$adTmp/*") ?: [] as $f) { @unlink($f); } @rmdir($adTmp); });
$adBytes = ['amd64' => ad_fake_pe(0x8664, 70000, 'ad'), 'arm64' => ad_fake_pe(0xAA64, 65000, 'ad')];
foreach ($adBytes as $arch => $bytes) {
    file_put_contents("$adTmp/$arch.exe", $bytes);
    $r = $rmm->binaryStore()->publish("$adTmp/$arch.exe", '0.9.1', $arch, 1, ['activate' => true]);
    $ok(!empty($r['ok']), "fixture: agent 0.9.1 $arch published");
}
$adMsi = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16000);
file_put_contents("$adTmp/t.msi", $adMsi);
$r = $rmm->binaryStore()->publishMsi("$adTmp/t.msi", '0.9.1', 'amd64', 1, ['activate' => true]);
$ok(!empty($r['ok']), 'fixture: MSI template 0.9.1 amd64 published');
$adSha = fn (string $s) => hash('sha256', $s);
$adLin = [   // kind, arch, filename, url, content-for-hash
    ['script', '', 'install-linux.sh', 'https://github.com/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/install-linux.sh', "#!/bin/bash\necho ARGS \"\$@\" > \"\${FAKE_OUT:?}\"\n"],
    ['binary', 'amd64', 'rivetit-agent-linux-amd64', 'https://github.com/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/rivetit-agent-linux-amd64', "\x7fELF-amd64"],
    ['binary', 'arm64', 'rivetit-agent-linux-arm64', 'https://github.com/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/rivetit-agent-linux-arm64', "\x7fELF-arm64"],
    ['tarball', 'amd64', 'rivetit-agent-linux-amd64.tar.gz', 'https://github.com/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/rivetit-agent-linux-amd64.tar.gz', 'tar-amd64'],
    ['deb', 'amd64', 'rivetit-agent_0.9.1_amd64.deb', 'https://github.com/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/rivetit-agent_0.9.1_amd64.deb', 'deb-amd64'],
    ['rpm', 'amd64', 'rivetit-agent-0.9.1-1.x86_64.rpm', 'https://github.com/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/rivetit-agent-0.9.1-1.x86_64.rpm', 'rpm-amd64'],
    ['rpm', 'arm64', 'evil<script>alert(1)</script>.rpm', 'http://plain.example.test/x.rpm', 'rpm-arm64'],
];
foreach ($adLin as [$kind, $arch, $fn, $url, $content]) {
    $q("INSERT INTO endpoint_agent_linux_assets SET version='0.9.1', kind='" . $esc($kind) . "', arch='" . $esc($arch) . "', filename='" . $esc($fn) . "', url='" . $esc($url) . "', sha256='" . $adSha($content) . "', size_bytes=" . strlen($content) . ", sha_source='SHA256SUMS', active=1, is_current=1, created_by=1");
}
[$c, $p] = $adPage();
$ok($c === 200 && str_contains($p, 'data-have-exe-amd64="1"') && str_contains($p, 'data-have-exe-arm64="1"') && str_contains($p, 'data-have-msi-amd64="1"') && str_contains($p, 'data-have-msi-arm64="0"') && str_contains($p, 'data-have-linux="1"'), 'with files published the page knows: both exe, msi for x64 only, Linux files');
$ok(str_contains($p, '>0.9.1</span>') && str_contains($p, 'Windows x64 MSI template'), 'the "what this server can build" list shows 0.9.1');
$ok(str_contains($p, $adSha('deb-amd64')) && str_contains($p, 'sudo apt install ./rivetit-agent_0.9.1_amd64.deb') && str_contains($p, 'sudo dnf install ./rivetit-agent-0.9.1-1.x86_64.rpm') && str_contains($p, 'tar xzf rivetit-agent-linux-amd64.tar.gz'), 'the Linux packages are listed with their SHA-256 and the install command for each');
$ok(str_contains($p, 'href="https://github.com/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/rivetit-agent_0.9.1_amd64.deb"') && str_contains($p, 'rel="noopener noreferrer"') && str_contains($p, 'data-ad-copy-value='), 'https package links open safely and have a copy-link button');
$ok(!str_contains($p, '<script>alert(1)') && str_contains($p, 'evil&lt;script&gt;alert(1)&lt;/script&gt;.rpm') && !str_contains($p, 'href="http://plain.example.test'), 'a hostile file name is escaped and an http address is never linked');
$ok($adNoInline($p), 'still no inline handlers');
// the page with a preselected department
[, $p2] = $adPage('admin', '?client_id=2');
$ok(str_contains($p2, '<option value="2" selected>Dept B</option>'), '?client_id= preselects the department');
[, $p3] = $adPage('admin', '?client_id=999');
$ok(!str_contains($p3, ' selected>Dept') , 'an unknown department is not preselected');

// ================================================================ 3. windows_commands
$t0 = $adTokens();
[$c, $b] = $adEp(['action' => 'windows_commands', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'exe', 'ttl_hours' => 6, 'max_uses' => 3, 'label' => 'commands <b>test</b>']);
$j = $adJson($b);
$ok($c === 200 && ($j['success'] ?? null) === true && array_key_exists('msi', $j) && $j['msi'] === null && str_contains((string) $j['powershell'], '/api/v1/agent_installer') && preg_match("/\\\$Token  = 'rvte1\\.[0-9a-f]{12}\\.[0-9a-f]{40}'/", (string) $j['powershell']) === 1 && str_contains((string) $j['powershell'], "\$Server = '$base'") && $j['department'] === 'Dept A' && $adTokens() === $t0 + 1 && (int) $j['max_uses'] === 3,
    'windows_commands (exe): the PowerShell with the server, the department and one fresh token (3 uses), no msi block');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Installer Created' AND log_description LIKE '%Dept A%' AND log_description NOT LIKE '%, msi)%'") >= 1, 'it is audited like every installer');
[$c, $b] = $adEp(['action' => 'windows_commands', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi']);
$j = $adJson($b);
$ok($c === 200 && is_array($j['msi'] ?? null) && str_contains((string) $j['msi']['msiexec'], 'msiexec /i "RivetIT-Agent-dept-a-x64.msi" /qn /norestart') && str_contains((string) $j['msi']['powershell'], "format = 'msi'") && str_contains((string) $j['msi']['properties'], 'ENROLL_TOKEN=') && str_contains((string) $j['msi']['uninstall'], 'RivetIT Agent') && str_contains((string) $j['powershell'], 'agent_installer'),
    'windows_commands (msi): the msiexec line for the stamped file, the PowerShell downloader, the properties form and the uninstall, plus the exe PowerShell');
$t1 = $adTokens();
foreach ([['arch' => 'arm64', 'format' => 'msi', 'why' => 'ARM64 + MSI (no ARM64 template)'], ['arch' => 'amd64', 'format' => 'zip', 'why' => 'an unknown format'], ['arch' => 'amd64', 'format' => 'exe', 'client_id' => 0, 'why' => 'no department'],
    ['arch' => 'amd64', 'format' => 'exe', 'client_id' => 9999, 'why' => 'a department that does not exist']] as $bad) {
    $f = ['action' => 'windows_commands', 'client_id' => 1] + ['arch' => $bad['arch'], 'format' => $bad['format']];
    if (isset($bad['client_id'])) { $f['client_id'] = $bad['client_id']; }
    [$c, $b] = $adEp($f);
    $ok($c === 422 && ($adJson($b)['success'] ?? null) === false && $adTokens() === $t1, "windows_commands with {$bad['why']}: 422 and no token left behind (" . ($adJson($b)['error'] ?? '') . ')');
}
[$c, $b] = $adEp(['action' => 'windows_commands', 'client_id' => 1, 'arch' => 'riscv; id', 'format' => 'exe']);
$j = $adJson($b);
$ok($c === 200 && ($j['arch'] ?? '') === 'amd64', 'a hostile architecture is coerced to amd64, never used as text');
[$c, $b] = $adEp(['action' => 'windows_commands', 'client_id' => 1, 'arch' => 'amd64', 'ttl_hours' => 99999, 'max_uses' => 999999]);
$rowT = $rows('SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1')[0];
$ok($c === 200 && (int) $rowT['max_uses'] <= 5000 && strtotime($rowT['expires_at'] . ' UTC') - time() <= 721 * 3600, 'absurd token lifetime and uses are clamped (' . $rowT['max_uses'] . ' uses)');

// ================================================================ 4. the Linux action with recorded assets
$t0 = $adTokens();
[$c, $b] = $adEp(['action' => 'linux', 'client_id' => 1, 'arch' => 'amd64', 'max_uses' => 2]);
$j = $adJson($b);
$cmd = (string) ($j['command'] ?? '');
$ok($c === 200 && $j['self_contained'] === true && $j['agent_version'] === '0.9.1' && $adTokens() === $t0 + 1 && !str_contains($cmd, 'unpacked'), 'Linux with recorded assets: a self-contained command for 0.9.1, one token');
$ok(str_contains($cmd, $adSha("\x7fELF-amd64")) && str_contains($cmd, $adSha($adLin[0][4])) && str_contains($cmd, 'bash "$S" --binary "$B" --server') && !str_contains($cmd, 'arm64') && substr_count($cmd, 'sha256sum -c') === 2, 'it carries the recorded hashes of this architecture only and checks both downloads');
$ok(array_column($j['packages'], 'kind') === ['script', 'binary', 'tarball', 'deb', 'rpm'] && $j['packages'][3]['sha256'] === $adSha('deb-amd64') && $j['packages'][4]['filename'] === 'rivetit-agent-0.9.1-1.x86_64.rpm', 'the answer lists the packages of that architecture in order');
$adSh = tempnam(sys_get_temp_dir(), 'adc'); file_put_contents($adSh, $cmd); exec('bash -n ' . escapeshellarg($adSh) . ' 2>&1', $o, $rc); unlink($adSh);
$ok($rc === 0, 'the command is valid shell (bash -n)');
[$c, $b] = $adEp(['action' => 'linux', 'client_id' => 1, 'arch' => 'arm64']);
$j = $adJson($b);
$ok($c === 200 && $j['self_contained'] === true && str_contains((string) $j['command'], $adSha("\x7fELF-arm64")) && count($j['packages']) === 3, 'ARM64: its own hashes; the .deb is not recorded for ARM64 here, so three files (script, agent, a hostile .rpm entry)');
// a recorded address that is not https never reaches a command
$q("UPDATE endpoint_agent_linux_assets SET url='http://plain.example.test/a' WHERE kind='binary' AND arch='amd64'");
[$c, $b] = $adEp(['action' => 'linux', 'client_id' => 1, 'arch' => 'amd64']);
$j = $adJson($b);
$ok($c === 409 && !str_contains($b, 'plain.example.test'), 'a recorded http address is never put into a command (no command at all)');
$q("UPDATE endpoint_agent_linux_assets SET url='https://github.com/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/rivetit-agent-linux-amd64' WHERE kind='binary' AND arch='amd64'");
// run the command with a stand-in curl and install script
$adShim = sys_get_temp_dir() . '/adshim_' . bin2hex(random_bytes(3)); mkdir($adShim);
$adServe = ['install-linux.sh' => $adLin[0][4], 'rivetit-agent-linux-amd64' => "\x7fELF-amd64"];
foreach ($adServe as $n => $bytes) { file_put_contents("$adShim/serve_$n", $bytes); }
file_put_contents("$adShim/curl", "#!/bin/bash\nout=''; url=''\nwhile [ \$# -gt 0 ]; do case \"\$1\" in -o) out=\"\$2\"; shift 2;; --*) shift;; *) url=\"\$1\"; shift;; esac; done\ncp \"$adShim/serve_\$(basename \"\$url\")\" \"\$out\" || exit 22\n");
chmod("$adShim/curl", 0755);
[$c, $b] = $adEp(['action' => 'linux', 'client_id' => 1, 'arch' => 'amd64']);
$cmd = (string) ($adJson($b)['command'] ?? '');
$run = function (string $command) use ($adShim): array {
    $f = tempnam(sys_get_temp_dir(), 'adr'); file_put_contents($f, $command); $outf = tempnam(sys_get_temp_dir(), 'ado');
    $p = proc_open(['bash', $f], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, array_merge(getenv(), ['PATH' => $adShim . ':' . getenv('PATH'), 'FAKE_OUT' => $outf]));
    stream_get_contents($pp[1]); $err = stream_get_contents($pp[2]); $rc = proc_close($p);
    $args = is_file($outf) ? trim((string) file_get_contents($outf)) : ''; @unlink($f); @unlink($outf);

    return [$rc, $args, $err];
};
[$rc, $args, $err] = $run($cmd);
$ok($rc === 0 && preg_match('~^ARGS --binary /\S+ --server ' . preg_quote($base, '~') . ' --token-file /\S+$~', $args) === 1, 'the command run end to end with stand-ins: it fetched, verified and handed --binary / --server / --token-file to the script (' . $args . ' ' . trim($err) . ')');
file_put_contents("$adShim/serve_rivetit-agent-linux-amd64", "\x7fELF-amd64-TAMPERED");
[$rc, $args, $err] = $run($cmd);
$ok($rc === 65 && $args === '' && str_contains($err, 'does not match'), 'a tampered agent download is stopped (exit 65) before the install script runs');
exec('rm -rf ' . escapeshellarg($adShim));

// ================================================================ 5. END TO END: the stamped files enroll a real device
$adCurrent = $rows("SELECT * FROM endpoint_agent_binaries WHERE arch='amd64' AND is_current=1")[0];
foreach (['exe' => 'MZ', 'msi' => "\xD0\xCF\x11\xE0"] as $fmt => $magic) {
    $before = (int) $one('SELECT COUNT(*) FROM endpoint_agent_devices');
    [$c, $body, $hd] = web($wb, 'POST', '/agent/post/rmm_installer.php', $SS['admin'], ['action' => 'download', 'client_id' => 1, 'arch' => 'amd64', 'format' => $fmt, 'ttl_hours' => 2, 'max_uses' => 1, 'csrf_token' => 'csrftok1'], ['Accept: application/octet-stream, application/json']);
    $sp = AdStamp::read($body);
    $d = $sp['data'] ?? [];
    $ok($c === 200 && str_starts_with($body, $magic) && $sp !== null, "the stamped .$fmt downloads through the endpoint and carries a valid trailer");
    $ok(substr($body, 0, $sp['exe_length'] ?? 0) === ($fmt === 'exe' ? $adBytes['amd64'] : $adMsi), "the .$fmt is the published file byte for byte plus the trailer");
    $ok(($d['server_url'] ?? '') === $base && ($d['department'] ?? '') === 'Dept A' && preg_match('/^rvte1\.[0-9a-f]{12}\.[0-9a-f]{40}$/', (string) ($d['enrollment_token'] ?? '')) === 1, "the .$fmt payload names this server, the department and a fresh token");
    [$ec, , $ej] = ea_enroll((string) $d['enrollment_token'], ea_dev(['hostname' => 'E2E-' . strtoupper($fmt)]));
    $ok(in_array($ec, [200, 201], true) && (int) $one('SELECT COUNT(*) FROM endpoint_agent_devices') === $before + 1 && (int) $one("SELECT client_id FROM endpoint_agent_devices WHERE hostname='E2E-" . strtoupper($fmt) . "'") === 1, "END TO END: the token inside the stamped .$fmt enrolls a device into Dept A ($ec)");
    [$ec2] = ea_enroll((string) $d['enrollment_token'], ea_dev(['hostname' => 'E2E-AGAIN-' . strtoupper($fmt)]));
    $ok(!in_array($ec2, [200, 201], true) && (int) $one('SELECT COUNT(*) FROM endpoint_agent_devices') === $before + 1, "the same token a second time is refused (one use): $ec2");
}
$ok(!str_contains((string) file_get_contents($rmm->binaryStore()->pathFor($adCurrent)), 'rvte1.'), 'the stored agent file is never modified: it holds no token');

// ---- put everything back
foreach ($rows('SELECT * FROM endpoint_agent_binaries') as $b) { @unlink($rmm->binaryStore()->pathFor($b)); }
foreach ($rows('SELECT * FROM endpoint_agent_msi_templates') as $t) { @unlink($rmm->binaryStore()->pathFor($t)); }
$q('DELETE FROM endpoint_agent_binaries');
$q('DELETE FROM endpoint_agent_msi_templates');
$q('DELETE FROM endpoint_agent_linux_assets');
$q("DELETE FROM endpoint_agent_devices WHERE hostname LIKE 'E2E-%'");
$rmm->settings()->set(['service_url' => $adSvc0]);
$fresh();
$ok($rmm->enabled(), 'the add-device fragment ends with the module ON');
