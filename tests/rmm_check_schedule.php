<?php
/*
 * Plain-language check schedule (RivetIT 26.10.53): Administration > Endpoint agent used to take the check list as JSON text. Scratch database; real HTTP against php -S with forged sessions.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... EA_TEST_NON_WINDOWS=1 RMM_TEST_STATE_DIR=none RMM_GATE_STATE_DIR=/nonexistent php tests/rmm_check_schedule.php
 *
 * Covers: the translation (stored list -> options -> list) with round trips, every refusal in plain words, hostile input (unknown fields, wrong types, injection, oversize), the live
 * preview endpoint (administrator only, CSRF), the save handler (what is stored, signed output unchanged and verifying, a refusal saves nothing, a raw `checks_json` is ignored, Restore
 * defaults), checks the options cannot express (kept / removed by choice), the page (no JSON box), plus the other forms that used to take JSON: policy check parameters (Tactical
 * integration), the older check types in a policy, and the Mesh agent id / install argument corrections.
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_check_schedule.php";
require_once "$root/includes/rmm_check_schedule_ui.php";
require_once "$root/includes/rmm_policy_check_params.php";
require_once "$root/includes/rmm_mesh_cards.php";
require_once "$root/includes/rmm_pol_ui.php";

use RivetCore\Rmm\Settings\RmmSettings;

ea_reset();
$U = ea_seed_users();
$rmm = rivetRmmModule($db);
$def = RmmSettings::defaultChecks();
$byKey = static function (array $l): array { $o = []; foreach ($l as $c) { $o[$c['key']] = $c; } return $o; };
$base = ['disk_on' => '1', 'disk_warn' => '15', 'reboot_on' => '1', 'svc' => ['EventLog'], 'svc_extra' => '', 'interval' => '900'];

// ---------------------------------------------------------------- the built-in list read as options
$st = rivetCsStateFromChecks($def);
$ok($st['disk']['on'] && $st['disk']['warn'] === 20 && $st['disk']['mount'] === 'C:' && $st['reboot']['on'] && $st['services'] === ['EventLog'] && $st['kept'] === [], 'built-in list: disk (20% free, C:), restart and the event log service are recognised; nothing is "kept"');
$ok($st['interval'] === 'keep', 'built-in list: its mixed timing (5 min, hourly, 5 min) is offered as "keep the current timing"');
$r = rivetCsBuild(['disk_on' => '1', 'disk_warn' => '20', 'reboot_on' => '1', 'svc' => ['EventLog'], 'interval' => 'keep'], $def);
$ok($r['list'] !== null && $r['errors'] === [] && array_map(fn ($c) => [$c['key'], $c['type'], $c['interval_s']], $r['list']) === [['disk_c', 'disk', 300], ['pending_reboot', 'pending_reboot', 3600], ['svc_eventlog', 'service', 300]],
    'saving the built-in options unchanged keeps the keys and the timing');
$ok($r['list'][0]['params'] === ['mount' => 'C:', 'warn_free_pct' => 20, 'fail_free_pct' => 10] && $r['list'][2]['params'] === ['name' => 'EventLog', 'expected' => 'running'],
    'the agent\'s real parameter names are written (warn_free_pct / expected), which equal what the agent applies to the built-in list');

// ---------------------------------------------------------------- build: what each option makes
$r = rivetCsBuild($base, $def);
$ok($r['errors'] === [] && count($r['list']) === 3 && $r['list'][0]['params']['warn_free_pct'] === 15 && $r['list'][0]['params']['fail_free_pct'] === 7 && $r['list'][0]['interval_s'] === 900, 'disk 15% free: warn 15, critical 7 (half), every 15 minutes');
$r = rivetCsBuild(['disk_on' => '0', 'reboot_on' => '0', 'svc' => [], 'interval' => '3600'], $def);
$ok($r['errors'] === [] && $r['list'] === [] && count($r['warnings']) >= 1 && rivetCsSummary($r['list']) === 'No checks: agents will not run any checks.', 'everything off: an empty list with a plain warning');
$r = rivetCsBuild(['svc' => ['wuauserv', 'WinDefend'], 'svc_extra' => "nginx\n  Spooler , mysql\nspooler", 'interval' => '300'], $def);
$names = array_map(fn ($c) => $c['params']['name'], $r['list']);
$ok($r['errors'] === [] && $names === ['wuauserv', 'WinDefend', 'nginx', 'Spooler', 'mysql'], 'services: ticked ones then the extra box (one per line or comma), case-insensitive duplicates dropped');
$ok(rivetCsSummary($r['list']) === '5 checks every 5 minutes', 'summary line: "5 checks every 5 minutes"');
$keys = array_column($r['list'], 'key');
$ok($keys === ['svc_wuauserv', 'svc_windefend', 'svc_nginx', 'svc_spooler', 'svc_mysql'], 'service keys are derived from the name');
// stable keys across edits
$r1 = rivetCsBuild(['svc' => ['Spooler'], 'interval' => '900'], $def);
$r2 = rivetCsBuild(['svc' => ['Spooler', 'wuauserv'], 'interval' => '900'], $r1['list']);
$ok(array_column($r2['list'], 'key') === ['svc_spooler', 'svc_wuauserv'], 'a service keeps its key when others are added (history stays attached)');
// scripts
$sc = ['scripts_on' => '1', 'scripts' => [0 => ['name' => 'backup_share', 'script' => "Test-Path \\\\nas\\b\r\nexit 0", 'timeout_s' => '30'], 1 => ['name' => '', 'script' => '', 'timeout_s' => '30']], 'interval' => '900'];
$r = rivetCsBuild($sc, $def);
$ok($r['errors'] === [] && count($r['list']) === 1 && $r['list'][0] === ['key' => 'backup_share', 'type' => 'script', 'params' => ['script' => "Test-Path \\\\nas\\b\nexit 0", 'timeout_s' => 30], 'interval_s' => 900], 'a script: line endings normalised, blank row ignored, timeout kept');
$st2 = rivetCsStateFromChecks($r['list']);
$ok($st2['scripts_on'] && $st2['scripts'][0]['name'] === 'backup_share' && $st2['kept'] === [], 'a stored script comes back as a script row');
$r = rivetCsBuild(['scripts_on' => '0', 'interval' => '900'], $r['list']);
$ok($r['list'] === [], 'turning "Run my own scripts" off removes the scripts (the form says so)');
foreach ([
    'too long' => [['name' => 'a', 'script' => str_repeat('x', 8193), 'timeout_s' => '30'], '8 KiB'],
    'timeout 61' => [['name' => 'a', 'script' => 'x', 'timeout_s' => '61'], 'at most 1 minute'],
    'timeout text' => [['name' => 'a', 'script' => 'x', 'timeout_s' => 'abc'], 'at most 1 minute'],
    'bad name' => [['name' => 'has space', 'script' => 'x', 'timeout_s' => '30'], 'short name'],
    'no commands' => [['name' => 'a', 'script' => '  ', 'timeout_s' => '30'], 'type the commands'],
    'extra field' => [['name' => 'a', 'script' => 'x', 'timeout_s' => '30', 'signature' => 'forged'], 'not sent correctly'],
] as $label => [$row, $needle]) {
    $r = rivetCsBuild(['scripts_on' => '1', 'scripts' => [$row], 'interval' => '900'], $def);
    $ok($r['list'] === null && str_contains(implode(' ', $r['errors']), $needle), "script refused ($label): " . ($r['errors'][0] ?? ''));
}
$r = rivetCsBuild(['scripts_on' => '1', 'scripts' => [['name' => 'x', 'script' => 'a', 'timeout_s' => '30'], ['name' => 'x', 'script' => 'b', 'timeout_s' => '30']], 'interval' => '900'], $def);
$ok($r['list'] === null && str_contains(implode(' ', $r['errors']), 'already used'), 'two scripts with the same name: refused in plain words');

// ---------------------------------------------------------------- hostile input
$hostile = [
    'unknown top-level field' => ['cs' => $base + ['checks_json' => '[]'], 'needle' => 'does not know'],
    'type: interval array' => ['cs' => ['interval' => ['900']] + $base, 'needle' => 'not sent correctly'],
    'type: svc string' => ['cs' => ['svc' => 'EventLog'] + $base, 'needle' => 'not sent correctly'],
    'type: cs string' => ['cs' => 'x', 'needle' => 'not sent correctly'],
    'interval seconds' => ['cs' => ['interval' => '10'] + $base, 'needle' => 'how often'],
    'interval 900.5' => ['cs' => ['interval' => '900.5'] + $base, 'needle' => 'how often'],
    'disk 0' => ['cs' => ['disk_warn' => '0'] + $base, 'needle' => 'Low disk space'],
    'disk 91' => ['cs' => ['disk_warn' => '91'] + $base, 'needle' => 'Low disk space'],
    'disk 1e1' => ['cs' => ['disk_warn' => '1e1'] + $base, 'needle' => 'Low disk space'],
    'disk text' => ['cs' => ['disk_warn' => "15'; DROP TABLE x"] + $base, 'needle' => 'Low disk space'],
    'service not in list' => ['cs' => ['svc' => ['EventLog', 'Evil']] + $base, 'needle' => 'not in the list'],
    'service injection (extra)' => ['cs' => ['svc_extra' => 'a"; rm -rf /'] + $base, 'needle' => 'not a service name'],
    'service newline in name' => ['cs' => ['svc_extra' => "ok\x00bad"] + $base, 'needle' => 'not a service name'],
    'too many services' => ['cs' => ['svc_extra' => implode("\n", array_map(fn ($i) => "svc$i", range(1, 40)))] + $base, 'needle' => 'at most 30'],
    'huge extra box' => ['cs' => ['svc_extra' => str_repeat('a', 5000)] + $base, 'needle' => 'too long'],
];
foreach ($hostile as $label => $h) {
    $r = rivetCsBuild($h['cs'], $def);
    $ok($r['list'] === null && stripos(implode(' ', $r['errors']), $h['needle']) !== false, "hostile: $label -> refused (" . substr($r['errors'][0] ?? '(none)', 0, 80) . ')');
}
$r = rivetCsBuild($base + ['remove' => ['no_such_key', ['x']]], $def);
$ok($r['errors'] === [] || $r['list'] === null, 'remove[] with unknown keys does not crash');
$r = rivetCsBuild($base, $def);
$ok(!str_contains(json_encode($r['list']), '"signature"'), 'the builder never emits a signature (signing is server-side per device)');

// ---------------------------------------------------------------- checks the options cannot express are kept, shown in words, removable
$custom = array_merge($def, [
    ['key' => 'cpu_hi', 'type' => 'cpu', 'params' => ['warn_pct' => 90, 'fail_pct' => 98, 'thresholds' => ['warn' => ['op' => 'gt', 'value' => 80], 'hysteresis' => 0, 'for_samples' => 1, 'for_minutes' => 0]], 'interval_s' => 60],
    ['key' => 'svc_stopped', 'type' => 'service', 'params' => ['name' => 'Foo', 'expected' => 'stopped'], 'interval_s' => 120],
    ['key' => 'disk_d', 'type' => 'disk', 'params' => ['mount' => 'D:', 'warn_free_gb' => 50], 'interval_s' => 600],
]);
$st = rivetCsStateFromChecks($custom);
$ok(array_keys($st['kept']) === ['cpu_hi', 'svc_stopped', 'disk_d'], 'cpu with thresholds, a stopped-expected service and a second disk are "kept"');
$r = rivetCsBuild(['disk_on' => '1', 'disk_warn' => '20', 'reboot_on' => '1', 'svc' => ['EventLog'], 'interval' => 'keep'], $custom);
$ok($r['errors'] === [] && $byKey($r['list'])['cpu_hi'] === $custom[3] && $byKey($r['list'])['disk_d'] === $custom[5], 'kept checks come back byte-for-byte from the STORED list');
$r = rivetCsBuild(['disk_on' => '1', 'disk_warn' => '20', 'reboot_on' => '1', 'svc' => ['EventLog'], 'interval' => 'keep', 'remove' => ['cpu_hi']], $custom);
$ok(!isset($byKey($r['list'])['cpu_hi']) && isset($byKey($r['list'])['disk_d']), 'ticking Remove drops exactly that kept check');
$ok(str_contains(rivetCsDescribe($custom[3]), 'Other check "cpu_hi" (cpu)') && str_contains(rivetCsDescribe($custom[5]), 'on D:'), 'kept checks are described in words');

// ---------------------------------------------------------------- the page and the handlers (real HTTP)
$sdir = sys_get_temp_dir() . '/ea_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$S = []; foreach ([1 => 'admin', 10 => 'tech', 12 => 'viewer'] as $uid => $n) { $S[$n] = ea_forge_session($sdir, $uid); }
$ref = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$postAdmin = fn(array $f, string $who = 'admin', bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $S[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), $ref);
$stored = fn() => $one('SELECT checks_json FROM endpoint_agent_settings WHERE id=1');
$rmm->settings()->enable();   // mints the instance signing key

[$c, $body] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S['admin']);
$ok($c === 200 && !str_contains($body, 'name="checks_json"') && !str_contains($body, 'delivered to agents (JSON)') && str_contains($body, 'id="cs_editor"') && str_contains($body, 'name="cs[disk_warn]"') && str_contains($body, 'Advanced: run your own script'), 'the page has the plain-language editor and no JSON box');
$ok(str_contains($body, 'data-cs-preset="recommended"') && str_contains($body, 'data-cs-preset="light"') && str_contains($body, 'data-cs-preset="strict"') && str_contains($body, 'data-cs-defaults'), 'presets Recommended, Light, Strict and Restore defaults are offered');
$ok(!preg_match('/<details[^>]*id="cs_adv"[^>]*\bopen\b/', $body) && !preg_match('/id="cs_scripts_on"[^>]*checked/', $body), 'custom scripts are collapsed and off by default');
$ok(str_contains($body, '8 KiB') && str_contains($body, '1 minute') && str_contains($body, 'SYSTEM on Windows'), 'the script section states the limits and the risk');
$ok(str_contains($body, 'id="af-installer-link"') && str_contains($body, 'href="/agent/rmm_add_device.php"'), 'the Agent updates card has a prominent Add device / Build installer button');

// preview endpoint
$prev = fn(array $cs, string $who = 'admin', bool $csrf = true, array $more = []) => web($wb, 'POST', '/admin/post.php', $S[$who], ['cs_preview' => '1', 'cs' => $cs] + $more + ($csrf ? ['csrf_token' => 'csrftok1'] : []), $ref);
[$c, $b] = $prev($base);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false) === true && $j['summary'] === '3 checks every 15 minutes' && count($j['lines']) === 3 && str_contains($j['json'], '"warn_free_pct": 15'), 'preview: summary, plain lines and the technical view for good options');
$ok($stored() === null, 'preview saves nothing');
[$c, $b] = $prev(['disk_warn' => '0'] + $base); $j = json_decode($b, true);
$ok($c === 200 && $j['success'] === false && str_contains($j['errors'][0], 'Low disk space'), 'preview: a plain-language error for bad options');
[$c, $b] = $prev($base, 'admin', false); $ok($c === 403 && str_contains($b, 'CSRF'), 'preview without a CSRF token -> 403');
[$c, $b] = $prev($base, 'tech'); $ok($c === 403 || !str_contains($b, '"summary"'), 'preview as a non-administrator -> denied');
[$c, $b] = $prev($base, 'viewer'); $ok($c === 403 || !str_contains($b, '"summary"'), 'preview as a viewer -> denied');
[$c, $b] = $prev([], 'admin', true, ['cs_defaults' => '1']); $j = json_decode($b, true);
$ok(($j['success'] ?? false) && $j['summary'] === '3 checks every 5 minutes to every hour', 'preview with Restore defaults shows the built-in list');
[$c, $b] = web($wb, 'POST', '/admin/post.php', $S['admin'], ['cs_preview' => '1', 'cs' => 'notanarray', 'csrf_token' => 'csrftok1'], $ref); $j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? true) === false, 'preview with cs as a string is refused, not a crash');

// save
$save = fn(array $cs, array $more = [], string $who = 'admin', bool $csrf = true) => $postAdmin(['save_agent_settings' => 1, 'enabled' => 1, 'cs' => $cs] + $more, $who, $csrf);
$save(['disk_warn' => '0'] + $base);
$ok($stored() === null, 'a refused option set saves NOTHING (the stored list is unchanged)');
$save($base, [], 'admin', false);
$ok($stored() === null, 'save without a CSRF token changes nothing');
$save($base, [], 'tech');
$ok($stored() === null, 'save as a non-administrator changes nothing');
$save($base, ['failure_debounce' => 4]);
$list = json_decode((string) $stored(), true);
$ok(is_array($list) && count($list) === 3 && $list[0]['key'] === 'disk_c' && $list[0]['params'] === ['mount' => 'C:', 'warn_free_pct' => 15, 'fail_free_pct' => 7] && (int) $one('SELECT failure_debounce FROM endpoint_agent_settings') === 4, 'save: the list is stored as built from the options (the built-in disk key and C: are kept), other settings still save');
$ok(count($rmm->settings()->checks()) === 3, 'the module reads the stored list back');
// signed output
$signed = $rmm->settings()->signedChecks(null);
$ok(count($signed) === 3 && isset($signed[0]['signature']), 'checks are delivered signed (same path as before)');
// raw checks_json ignored
$postAdmin(['save_agent_settings' => 1, 'enabled' => 1, 'checks_json' => '[{"key":"evil","type":"script","params":{"script":"rm -rf /","timeout_s":10},"interval_s":60}]']);
$ok(!str_contains((string) $stored(), 'evil') && count(json_decode((string) $stored(), true)) === 3, 'a raw checks_json field posted by a client is ignored (nothing is read from it)');
// unknown field inside cs
$save($base + ['checks_json' => '[]', 'signature' => 'x']);
$ok(count(json_decode((string) $stored(), true)) === 3, 'unknown fields inside cs: refused, stored list unchanged');
// scripts through the handler, then signed + verifying
$save(['scripts_on' => '1', 'scripts' => [0 => ['name' => 'share_check', 'script' => '(Get-Date).Year', 'timeout_s' => '10']]] + $base);
$list = $byKey(json_decode((string) $stored(), true));
$ok(isset($list['share_check']) && $list['share_check']['params'] === ['script' => '(Get-Date).Year', 'timeout_s' => 10] && count($list) === 4, 'a script saved through the form is stored with its limits');
$pubKey = (string) $one('SELECT signing_public_key FROM endpoint_agent_settings WHERE id=1');
$signed = $rmm->settings()->signCheckList(json_decode((string) $stored(), true), null);   // the same code signedChecks() runs (that one caches the list it read first)
$sc = array_values(array_filter($signed, fn ($c) => $c['key'] === 'share_check'))[0] ?? null;
$scSig = $sc['signature'] ?? '';
unset($sc['signature']);
$ok($sc !== null && $scSig !== '' && \RivetCore\Rmm\Crypto\Signer::verify(\RivetCore\Rmm\Crypto\Signer::checkMessage($sc), $scSig, $pubKey), 'the script check is signed and the signature verifies against the instance public key');
$sc['params']['script'] = 'tampered';
$ok(!\RivetCore\Rmm\Crypto\Signer::verify(\RivetCore\Rmm\Crypto\Signer::checkMessage($sc), $scSig, $pubKey), 'a changed script no longer verifies');
// kept checks survive a save of the page
$q("UPDATE endpoint_agent_settings SET checks_json=" . "'" . $esc(json_encode($custom)) . "' WHERE id=1");
[$c, $body] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S['admin']);
$ok(str_contains($body, 'Other checks set up earlier') && str_contains($body, 'Other check &quot;cpu_hi&quot;') && str_contains($body, 'name="cs[remove][]" value="cpu_hi"'), 'the page lists checks the options cannot show, with a Remove tick-box');
$save(['disk_on' => '1', 'disk_warn' => '20', 'reboot_on' => '1', 'svc' => ['EventLog'], 'interval' => 'keep']);
$ok($byKey(json_decode((string) $stored(), true))['cpu_hi'] === $custom[3], 'an untouched save keeps the earlier custom check exactly');
$save(['disk_on' => '1', 'disk_warn' => '20', 'reboot_on' => '1', 'svc' => ['EventLog'], 'interval' => 'keep', 'remove' => ['cpu_hi', 'svc_stopped', 'disk_d']]);
$ok(count(json_decode((string) $stored(), true)) === 3, 'ticking Remove on all three removes them');
// restore defaults
$postAdmin(['save_agent_settings' => 1, 'enabled' => 1, 'cs_defaults' => '1', 'cs' => ['interval' => '60']]);
$ok($stored() === null, 'Restore defaults clears the stored list (the built-in one applies again)');
$ok($rmm->settings()->checks() === $def, 'and the module serves the built-in list');
// a save without cs leaves the list alone
$save($base); $before = $stored();
$postAdmin(['save_agent_settings' => 1, 'enabled' => 1, 'failure_debounce' => 3]);
$ok($stored() === $before, 'a settings save that carries no check options leaves the check list alone');
// html in names is escaped on the page
$q("UPDATE endpoint_agent_settings SET checks_json='" . $esc(json_encode([['key' => 'k', 'type' => 'script', 'params' => ['script' => '</textarea><script>alert(1)</script>', 'timeout_s' => 10], 'interval_s' => 60],
    ['key' => 'svc_x', 'type' => 'service', 'params' => ['name' => 'a<b'], 'interval_s' => 60]])) . "' WHERE id=1");
[$c, $body] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S['admin']);
$ok($c === 200 && !str_contains($body, '<script>alert(1)</script>') && str_contains($body, '&lt;/textarea&gt;&lt;script&gt;alert(1)'), 'a stored script body is escaped on the page');
$q("UPDATE endpoint_agent_settings SET checks_json=NULL WHERE id=1");

// ---------------------------------------------------------------- policy check parameters of the Tactical integration (no JSON)
foreach ([
    ['diskspace', ['disk' => 'd'], ['disk' => 'D']], ['diskspace', ['disk' => '/'], ['disk' => '/']], ['cpuload', [], []],
    ['ping', ['ip' => '10.0.0.1', 'failures' => '3'], ['ip' => '10.0.0.1', 'failures' => 3]],
    ['winsvc', ['svc_name' => 'Spooler', 'restart' => '1'], ['svc_name' => 'Spooler', 'restart_if_stopped' => true]],
    ['winsvc', ['svc_name' => 'Spooler'], ['svc_name' => 'Spooler', 'restart_if_stopped' => false]],
    ['eventlog', ['log_name' => 'System', 'event_type' => 'WARNING', 'event_id' => '', 'fail_count' => '2', 'search_last_days' => '3'], ['log_name' => 'System', 'event_type' => 'WARNING', 'fail_count' => 2, 'search_last_days' => 3]],
    ['eventlog', ['event_id' => '41'], ['log_name' => 'Application', 'event_type' => 'ERROR', 'fail_count' => 1, 'search_last_days' => 1, 'event_id' => 41]],
] as [$t, $pp, $want]) {
    [$out, $e] = rivetPolicyCheckParams($t, $pp);
    $ok($e === null && $out === $want, "policy params ($t): built from fields -> " . json_encode($out));
}
foreach ([
    ['diskspace', ['disk' => 'C:\\Windows']], ['diskspace', ['disk' => '']], ['diskspace', ['bogus' => '1']], ['ping', ['ip' => 'a b; rm']], ['ping', ['ip' => '1.1.1.1', 'failures' => '99']],
    ['winsvc', ['svc_name' => '']], ['winsvc', ['svc_name' => 'x"y']], ['eventlog', ['log_name' => 'Evil']], ['eventlog', ['fail_count' => '0']], ['eventlog', ['event_id' => '70000']],
    ['cpuload', ['ip' => '1.1.1.1']], ['nonsense', []], ['diskspace', 'string'], ['winsvc', ['svc_name' => ['a']]],
] as [$t, $pp]) {
    [$out, $e] = rivetPolicyCheckParams($t, $pp);
    $ok($out === null && is_string($e), "policy params hostile ($t " . json_encode($pp) . ') refused: ' . $e);
}
$src = file_get_contents("$root/agent/rmm_checks.php");
$ok(!str_contains($src, 'Extra Parameters (JSON)') && !str_contains($src, 'id="p_params"'), 'the Tactical policy page has no JSON box');
$post = file_get_contents("$root/agent/post/rmm_check.php");
$ok(!str_contains($post, "_POST['check_params']") && str_contains($post, 'rivetPolicyCheckParams'), 'the policy handler builds the settings from fields and never reads a raw check_params');

// ---------------------------------------------------------------- the older check types in a RivetCore policy
foreach ([
    ['service', ['name' => 'Spooler'], ['name' => 'Spooler']], ['service', ['name' => 'Spooler', 'expected' => 'stopped', 'startup' => 'manual'], ['name' => 'Spooler', 'expected' => 'stopped', 'startup' => 'manual']],
    ['service', ['name' => 'x', 'startup' => 'any'], ['name' => 'x']], ['disk', ['mount' => 'C:', 'warn_free_pct' => '15', 'fail_free_pct' => '5'], ['mount' => 'C:', 'warn_free_pct' => 15, 'fail_free_pct' => 5]],
    ['disk', [], []], ['pending_reboot', [], []], ['script', ['script' => "a\r\nb", 'timeout_s' => '10'], ['script' => "a\nb", 'timeout_s' => 10]],
] as [$t, $in, $want]) {
    [$out, $e] = rivetRmmPolLegacyParams($t, $in);
    $ok($e === null && $out === $want, "policy legacy ($t): fields -> " . json_encode($out));
}
foreach ([
    ['service', []], ['service', ['name' => 'a;b']], ['service', ['name' => 'a', 'expected' => 'bogus']], ['service', ['name' => 'a', 'extra' => 'x']], ['disk', ['warn_free_pct' => '0']], ['disk', ['warn_free_pct' => '1e1']],
    ['script', []], ['script', ['script' => 'x', 'timeout_s' => '99']], ['script', ['script' => str_repeat('x', 8193)]], ['pending_reboot', ['x' => 'y']], ['disk', ['mount' => "C:\x00x"]],
] as [$t, $in]) {
    [$out, $e] = rivetRmmPolLegacyParams($t, $in);
    $ok($e !== null, "policy legacy hostile ($t " . json_encode($in) . ') refused: ' . $e);
}
$polSrc = file_get_contents("$root/includes/rmm_pol_ui.php");
$ok(!str_contains($polSrc, 'Extra settings of this type (JSON object)') && !str_contains($polSrc, 'params_json'), 'the policy editor has no JSON box any more');
$ok(str_contains(rivetRmmPolLegacyFields('x', 'chk[0][lg][service]', 'service', ['name' => '"><b>', 'weird' => 1]), '&quot;&gt;&lt;b&gt;') && str_contains(rivetRmmPolLegacyFields('x', 'n', 'service', ['weird' => 1]), 'dropped when you save'), 'legacy fields escape values and say which old settings would be dropped');

// ---------------------------------------------------------------- Mesh corrections (the handler is exercised in tests/rmm_ui_rc13_mesh.php)
$meshSrc = file_get_contents("$root/includes/rmm_mesh_cards.php");
$ok(!str_contains($meshSrc, 'name="mesh_agent_ids"') && !str_contains($meshSrc, '(JSON, empty = none)') && str_contains($meshSrc, 'name="mesh_aid['), 'the Mesh card has plain fields, no JSON boxes');
