<?php
/*
 * Fixtures for tests/browser/rmm_agentfeed_smoke.mjs (Agent updates card, Add device / Build installer page; RivetIT 26.10.52), run on the same throwaway install after
 * tests/browser/rmm_seed.php, next to a MOCK GitHub (tests/support/mock_github.php) that the install's web server is pointed at (RIVET_AGENT_FEED_TEST_ORIGIN).
 *
 *   php tests/browser/rmm_agentfeed_extra.php <seed.json> fixtures <mockdir>   write the mock GitHub's releases and files: agent-v0.9.2 (its x64 .exe can be tampered with by the scenario) and agent-v0.9.1
 *   php tests/browser/rmm_agentfeed_extra.php <seed.json> scenario <mockdir> '<json>'   write the mock's scenario.json (see tests/support/mock_github.php)
 *   php tests/browser/rmm_agentfeed_extra.php <seed.json> state       print what the install holds: binaries, MSI templates, Linux files, releases offered, audit rows
 *   php tests/browser/rmm_agentfeed_extra.php <seed.json> clear       no agent published, no pilot device, no saved release list
 *   php tests/browser/rmm_agentfeed_extra.php <seed.json> age         clear the forced-check limit and a stored rate-limit time
 *   php tests/browser/rmm_agentfeed_extra.php <seed.json> pilot       enroll nothing; put one device of the seed on the pilot ring (the ring preselected by Publish)
 *
 * Prints one JSON line. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

$mode = $argv[2] ?? 'state';
if ($mode === 'fixtures') {
    $dir = rtrim((string) ($argv[3] ?? ''), '/');
    if ($dir === '' || !is_dir($dir)) { fwrite(STDERR, "mock dir missing\n"); exit(1); }
    $pe = function (int $machine, int $size, string $fill): string {
        $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0");
        $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
        $need = $size - strlen($b);

        return $b . substr(str_repeat(hash('sha256', $fill . $machine, true), intdiv($need, 32) + 1), 0, $need);
    };
    $releases = [];
    foreach (['0.9.2' => 'b', '0.9.1' => 'a'] as $ver => $fill) {
        $tag = "agent-v$ver";
        @mkdir("$dir/assets/$tag", 0775, true);
        $F = [
            'rivetit-agent-windows-amd64.exe' => $pe(0x8664, 60000, $fill), 'rivetit-agent-windows-arm64.exe' => $pe(0xAA64, 50000, $fill),
            'rivetit-agent-windows-amd64.msi' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat($fill, 12000),
            'install-linux.sh' => "#!/usr/bin/env bash\necho fake $ver\n", 'rivetit-agent-linux-amd64' => "\x7fELF" . str_repeat($fill, 3000), 'rivetit-agent-linux-arm64' => "\x7fELF" . str_repeat($fill, 3001),
            'rivetit-agent-linux-amd64.tar.gz' => str_repeat($fill, 2000), 'rivetit-agent-linux-arm64.tar.gz' => str_repeat($fill, 2001),
            "rivetit-agent_{$ver}_amd64.deb" => str_repeat($fill, 2002), "rivetit-agent_{$ver}_arm64.deb" => str_repeat($fill, 2003),
            "rivetit-agent-$ver-1.x86_64.rpm" => str_repeat($fill, 2004), "rivetit-agent-$ver-1.aarch64.rpm" => str_repeat($fill, 2005),
        ];
        $sumsMain = ''; $sumsMsi = ''; $assets = [];
        foreach ($F as $n => $b) {
            file_put_contents("$dir/assets/$tag/$n", $b);
            $assets[] = ['name' => $n, 'size' => strlen($b), 'digest' => 'sha256:' . hash('sha256', $b)];
            if (str_ends_with($n, '.msi')) { $sumsMsi .= hash('sha256', $b) . "  $n\n"; }
            elseif (!str_ends_with($n, '.deb') && !str_ends_with($n, '.rpm')) { $sumsMain .= hash('sha256', $b) . "  $n\n"; }
        }
        file_put_contents("$dir/assets/$tag/SHA256SUMS", $sumsMain);
        file_put_contents("$dir/assets/$tag/SHA256SUMS.msi", $sumsMsi);
        $assets[] = ['name' => 'SHA256SUMS', 'size' => strlen($sumsMain), 'digest' => 'sha256:' . hash('sha256', $sumsMain)];
        $assets[] = ['name' => 'SHA256SUMS.msi', 'size' => strlen($sumsMsi), 'digest' => 'sha256:' . hash('sha256', $sumsMsi)];
        $releases[] = ['tag_name' => $tag, 'name' => "RivetIT agent $ver", 'draft' => false, 'prerelease' => true, 'published_at' => $ver === '0.9.2' ? '2026-10-19T09:00:00Z' : '2026-10-18T17:16:53Z',
            'body' => "Notes for $ver <b>bold?</b>", 'assets' => $assets];
    }
    $releases[] = ['tag_name' => 'v1.0.0-rc.21', 'name' => 'core', 'draft' => false, 'prerelease' => true, 'published_at' => '2026-10-17T21:50:46Z', 'body' => '', 'assets' => []];
    file_put_contents("$dir/releases.json", json_encode($releases));
    file_put_contents("$dir/scenario.json", '{}');
    echo json_encode(['ok' => true]), "\n";
    exit(0);
}
if ($mode === 'scenario') {
    file_put_contents(rtrim((string) $argv[3], '/') . '/scenario.json', (string) ($argv[4] ?? '{}'));
    echo json_encode(['ok' => true]), "\n";
    exit(0);
}
if ($mode === 'clear') {   // start from an install with no agent published and no pilot device (the seed publishes placeholder 1.0.0 binaries and may use a pilot ring)
    foreach ($rows('SELECT * FROM endpoint_agent_binaries') as $b) { @unlink(rivetRmmModule($db)->binaryStore()->pathFor($b)); }
    foreach (['endpoint_agent_binaries', 'endpoint_agent_msi_templates', 'endpoint_agent_releases', 'endpoint_agent_linux_assets'] as $t) { $q("DELETE FROM $t"); }
    $q("UPDATE endpoint_agent_devices SET ring='stable'");
    $q("UPDATE endpoint_agent_feed SET releases_json = NULL, etag = '', fetched_at = NULL, checked_at = NULL, last_error = '', rate_reset_at = NULL");
    echo json_encode(['ok' => true]), "\n";
    exit(0);
}
if ($mode === 'age') {   // let the next "Check for updates" go to the mock again (the forced-check limit and a stored rate-limit time are cleared)
    $q('UPDATE endpoint_agent_feed SET checked_at = NULL, rate_reset_at = NULL');
    echo json_encode(['ok' => true]), "\n";
    exit(0);
}
if ($mode === 'pilot') {
    $q("UPDATE endpoint_agent_devices SET ring='pilot' WHERE device_id = (SELECT m FROM (SELECT MIN(device_id) m FROM endpoint_agent_devices) x)");
    echo json_encode(['ok' => true]), "\n";
    exit(0);
}
if ($mode === 'state') {
    $cnt = fn (string $t, string $w = '1') => (int) $one("SELECT COUNT(*) FROM $t WHERE $w");
    echo json_encode(['binaries' => $rows('SELECT version, arch, is_current, active FROM endpoint_agent_binaries ORDER BY binary_id'), 'msi' => $cnt('endpoint_agent_msi_templates'), 'linux' => $cnt('endpoint_agent_linux_assets'),
        'releases' => $rows('SELECT version, arch, ring, rollout_pct, active FROM endpoint_agent_releases ORDER BY release_id'), 'audit_check' => $cnt('logs', "log_action='Agent Feed Checked'"),
        'audit_verify' => $cnt('logs', "log_action='Agent Feed Verify Failed'"), 'audit_published' => $cnt('logs', "log_action='Agent Feed Published'"), 'tokens' => $cnt('endpoint_agent_enrollment_tokens')]), "\n";
    exit(0);
}
fwrite(STDERR, "unknown mode\n");
exit(1);
