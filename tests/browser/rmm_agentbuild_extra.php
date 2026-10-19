<?php
/*
 * Fixtures for tests/browser/rmm_agentbuild_smoke.mjs (Agent updates > Build a new version; RivetIT 26.10.55), on the same throwaway install, next to the MOCK GitHub.
 *
 *   php tests/browser/rmm_agentbuild_extra.php <seed.json> fixtures <mockdir> <token>   releases agent-v0.9.1 and 0.9.0 on the mock, the mock accepts only <token>, no build, no token saved
 *   php tests/browser/rmm_agentbuild_extra.php <seed.json> runs <mockdir> <token> <status> <conclusion|-> [created offset s]   one workflow run (id 4711) with that state
 *   php tests/browser/rmm_agentbuild_extra.php <seed.json> appear <mockdir>   add the release agent-v0.9.2 (Windows x64 agent + SHA256SUMS) to the mock's list
 *   php tests/browser/rmm_agentbuild_extra.php <seed.json> state <mockdir>    JSON: the build row (no token), whether a token is stored and how, dispatch calls seen by the mock, binaries, releases
 *   php tests/browser/rmm_agentbuild_extra.php <seed.json> cron               run cron/agent_build_check.php once (with the mock origin from the environment)
 *
 * Prints one JSON line. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';

$mode = $argv[2] ?? 'state';
$dir = rtrim((string) ($argv[3] ?? ''), '/');
$pe = function (string $fill): string {
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0");
    $b .= "PE\0\0" . pack('v', 0x8664) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $need = 40000 - strlen($b);

    return $b . substr(str_repeat(hash('sha256', $fill, true), intdiv($need, 32) + 1), 0, $need);
};
$release = function (string $v) use ($dir, $pe): array {
    $exe = $pe($v);
    @mkdir("$dir/assets/agent-v$v", 0775, true);
    file_put_contents("$dir/assets/agent-v$v/rivetit-agent-windows-amd64.exe", $exe);
    $sums = hash('sha256', $exe) . "  rivetit-agent-windows-amd64.exe\n";
    file_put_contents("$dir/assets/agent-v$v/SHA256SUMS", $sums);

    return ['tag_name' => "agent-v$v", 'name' => $v, 'draft' => false, 'prerelease' => false, 'published_at' => gmdate('Y-m-d\TH:i:s\Z'), 'body' => '',
        'assets' => [['name' => 'rivetit-agent-windows-amd64.exe', 'size' => strlen($exe), 'digest' => 'sha256:' . hash('sha256', $exe)], ['name' => 'SHA256SUMS', 'size' => strlen($sums), 'digest' => 'sha256:' . hash('sha256', $sums)]]];
};
$out = ['ok' => true];
if ($mode === 'fixtures') {
    file_put_contents("$dir/releases.json", json_encode([$release('0.9.1'), $release('0.9.0')]));
    file_put_contents("$dir/scenario.json", json_encode(['token' => (string) ($argv[4] ?? '')]));
    @unlink("$dir/actions.log");
    $q("UPDATE endpoint_agent_feed SET repo='TheTractorHacker/rivet-core', releases_json=NULL, etag='', fetched_at=NULL, checked_at=NULL, last_error='', rate_reset_at=NULL");
    $q('DELETE FROM endpoint_agent_binaries'); $q('DELETE FROM endpoint_agent_msi_templates'); $q('DELETE FROM endpoint_agent_releases'); $q('DELETE FROM endpoint_agent_linux_assets');
    $q("UPDATE endpoint_agent_build SET gh_token='', token_set_at=NULL, token_status='', build_version='', build_repo='', requested_at=NULL, requested_by=0, run_id=0, run_state='', run_note='', checked_at=NULL, finished_at=NULL, auto_publish=0, auto_opts='', auto_state='', auto_note=''");
} elseif ($mode === 'runs') {
    $conc = ($argv[6] ?? '-') === '-' ? null : $argv[6];
    file_put_contents("$dir/scenario.json", json_encode(['token' => (string) ($argv[4] ?? ''), 'runs' => [['id' => 4711, 'status' => (string) $argv[5], 'conclusion' => $conc, 'created_at' => gmdate('Y-m-d\TH:i:s\Z', time() + (int) ($argv[7] ?? 0))]]]));
    $q('UPDATE endpoint_agent_build SET checked_at = NULL');
} elseif ($mode === 'appear') {
    $l = json_decode((string) file_get_contents("$dir/releases.json"), true);
    array_unshift($l, $release('0.9.2'));
    file_put_contents("$dir/releases.json", json_encode($l));
    $q('UPDATE endpoint_agent_feed SET checked_at = NULL, rate_reset_at = NULL');
} elseif ($mode === 'cron') {
    $out['out'] = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' cron/agent_build_check.php 2>&1'));
} else {
    $r = $rows('SELECT * FROM endpoint_agent_build WHERE id = 1')[0] ?? [];
    $enc = (string) ($r['gh_token'] ?? '');
    unset($r['gh_token']);
    $out['build'] = $r;
    $out['token_stored'] = $enc !== '';
    $out['token_prefix'] = substr($enc, 0, 3);
    $out['token_len'] = strlen($enc);
    $calls = [];
    foreach (is_file("$dir/actions.log") ? file("$dir/actions.log", FILE_IGNORE_NEW_LINES) : [] as $l) { $j = json_decode($l, true); $calls[] = ['m' => $j['method'], 'p' => $j['path'], 'auth' => $j['auth'] !== '' ? 'bearer' : '', 'body' => $j['body']]; }
    $out['calls'] = $calls;
    $out['binaries'] = $rows('SELECT version, arch, is_current FROM endpoint_agent_binaries');
    $out['releases'] = $rows('SELECT version, ring, rollout_pct FROM endpoint_agent_releases');
    $out['audit'] = array_column($rows("SELECT log_action, COUNT(*) c FROM logs WHERE log_action LIKE 'Agent Build%' GROUP BY log_action"), 'c', 'log_action');
    $out['token_in_logs'] = (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%github_pat_%'");
}
echo json_encode($out) . "\n";
