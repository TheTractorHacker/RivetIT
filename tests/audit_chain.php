<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Audit (gap analysis item 17), on a SCRATCH database with the real code:
 *   - the hash chain: sealing in order under AuditService, verify (ok / edited row / deleted row / reordered / cut tail / retention of the old end /
 *     key changed), the cron's nightly verify and its alert;
 *   - the JSON-lines / syslog sink (default off, path rules);
 *   - the new events: role and permission changes, role of a user, department access, API tokens, settings and backup events mirrored from logAction,
 *     exports, entity links;
 *   - the admin page and handler.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/audit_chain.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
use ITFlow\Audit\{AuditChain, AuditService, AuditSink};
use ITFlow\Platform\{Nightly, PlatformSettings};

ea_reset();
$q("DELETE FROM audit_events"); $q("DELETE FROM platform_settings"); $q("DELETE FROM recovery_alerts"); $q("DELETE FROM notifications");
$q("INSERT INTO user_roles SET role_id = 1, role_name = 'Admin', role_is_admin = 1, role_type = 1");
$q("INSERT INTO users SET user_id = 1, user_name = 'admin', user_email = 'admin@example.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 1");
$ev = fn () => $rows("SELECT * FROM audit_events ORDER BY audit_id");
$tmp = sys_get_temp_dir() . '/audit_chain_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) { @unlink($f); } @rmdir($tmp); });

// ---------------------------------------------------------------- pure hash
$row = ['audit_id' => 5, 'event_type' => 'x.y', 'actor_user_id' => 1, 'entity_type' => 'a', 'entity_id' => '9', 'action' => 'z', 'summary' => 's', 'metadata_json' => '{"a":1}', 'ip_address' => '1.2.3.4', 'user_agent' => 'ua', 'request_id' => 'r', 'created_at' => '2026-10-10 10:00:00'];
$h1 = AuditChain::rowHash($row, AuditChain::GENESIS, 'k');
$ok(strlen($h1) === 64 && $h1 === AuditChain::rowHash($row, AuditChain::GENESIS, 'k'), 'hash: 64 hex characters and deterministic');
$ok($h1 !== AuditChain::rowHash(['summary' => 'S'] + $row, AuditChain::GENESIS, 'k') && $h1 !== AuditChain::rowHash($row, str_repeat('1', 64), 'k') && $h1 !== AuditChain::rowHash($row, AuditChain::GENESIS, 'other') && $h1 !== AuditChain::rowHash($row, AuditChain::GENESIS, null), 'hash: any field, the previous hash and the key all change it');
foreach (['event_type', 'actor_user_id', 'entity_type', 'entity_id', 'action', 'metadata_json', 'ip_address', 'user_agent', 'request_id', 'created_at', 'audit_id'] as $f) {
    $alt = $row; $alt[$f] = $f === 'actor_user_id' || $f === 'audit_id' ? 99 : 'changed'; $alt2 = $row; $alt2[$f] = null;
    if (AuditChain::rowHash($alt, AuditChain::GENESIS, 'k') === $h1 || AuditChain::rowHash($alt2, AuditChain::GENESIS, 'k') === $h1) { $ok(false, "hash covers $f"); }
}
$ok(true, 'hash: covers every audited field (including null vs a value)');

// ---------------------------------------------------------------- sealing through AuditService
$svc = new AuditService($db);
for ($i = 1; $i <= 5; $i++) { $svc->log("test.event$i", 1, 'thing', $i, 'create', "Event $i", ['n' => $i, 'password' => 'hunter2']); }
$r = $ev();
$ok(count($r) === 5 && !array_filter($r, fn ($x) => $x['row_hash'] === null || $x['prev_hash'] === null), 'seal: every event written through AuditService is sealed');
$ok($r[0]['prev_hash'] === AuditChain::GENESIS && $r[1]['prev_hash'] === $r[0]['row_hash'] && $r[4]['prev_hash'] === $r[3]['row_hash'], 'seal: each row links to the one before; the first links to the genesis value');
$ok(!str_contains($r[0]['metadata_json'], 'hunter2'), 'the secret in the metadata is still redacted by Core before it is hashed and stored');
$chain = new AuditChain($db);
$v = $chain->verify();
$ok($v['status'] === 'ok' && $v['checked'] === 5 && $v['broken_at'] === null && $v['unsealed'] === 0, 'verify: an untouched chain is ok');
$ok(PlatformSettings::get($db, 'audit_chain_mode') === ($GLOBALS['config_settings_enc_key'] ?? '' ? 'hmac' : 'plain'), 'the chain mode (hmac when the install has a settings key) is recorded');
$chain->record($v);
$last = json_decode(PlatformSettings::get($db, 'audit_chain_last_verify'), true);
$ok($last['status'] === 'ok' && $last['checked'] === 5, 'the result is recorded for the admin page');

// ---------------------------------------------------------------- rows written by other paths are sealed later, in order
$q("INSERT INTO audit_events (event_type, action, summary) VALUES ('direct.insert', 'a', 'written by a path that does not seal')");
$ok($chain->verify()['unsealed'] === 1, 'verify: an unsealed newest row is counted, not a break');
$sealed = $chain->seal();
$ok(count($sealed) === 1 && $sealed[0]['prev_hash'] === $r[4]['row_hash'] && $chain->verify()['status'] === 'ok', 'seal: the cron seals it, linked to the newest sealed row');
$ok($chain->seal() === [], 'seal: nothing left to do');

// ---------------------------------------------------------------- tamper detection
$snapshot = $ev();
$restore = function () use ($q, $snapshot) { $q("DELETE FROM audit_events"); foreach ($snapshot as $s) { $cols = implode(',', array_keys($s)); $vals = implode(',', array_map(fn ($v) => $v === null ? 'NULL' : "'" . $GLOBALS['db']->real_escape_string((string) $v) . "'", $s)); $q("INSERT INTO audit_events ($cols) VALUES ($vals)"); } };
$id3 = (int) $snapshot[2]['audit_id'];
$q("UPDATE audit_events SET summary = 'tampered' WHERE audit_id = $id3");
$v = $chain->verify();
$ok($v['status'] === 'broken' && $v['broken_at'] === $id3 && str_contains($v['reason'], 'edited'), 'verify: an edited row is found, by id');
$restore();
$q("UPDATE audit_events SET actor_user_id = 7 WHERE audit_id = $id3");
$ok($chain->verify()['broken_at'] === $id3, 'verify: a changed actor is found');
$restore();
$q("UPDATE audit_events SET created_at = created_at + INTERVAL 1 DAY WHERE audit_id = $id3");
$ok($chain->verify()['broken_at'] === $id3, 'verify: a changed time is found');
$restore();
$q("DELETE FROM audit_events WHERE audit_id = $id3");
$v = $chain->verify();
$ok($v['status'] === 'broken' && $v['broken_at'] === (int) $snapshot[3]['audit_id'], 'verify: a removed middle row is found (the next row no longer links)');
$restore();
$q("UPDATE audit_events SET prev_hash = '" . $snapshot[3]['prev_hash'] . "', row_hash = '" . $snapshot[3]['row_hash'] . "' WHERE audit_id = $id3");
$ok($chain->verify()['status'] === 'broken', 'verify: copying another row\'s hashes onto a row is found');
$restore();
// a rebuilt chain without the key fails
$q("UPDATE audit_events SET summary = 'rewritten' WHERE audit_id = $id3");
$prev = AuditChain::GENESIS; $fake = $ev();
foreach ($fake as $f) { $h = AuditChain::rowHash($f, $prev, null); $q("UPDATE audit_events SET prev_hash = '$prev', row_hash = '$h' WHERE audit_id = " . (int) $f['audit_id']); $prev = $h; }
$hasKey = !empty($GLOBALS['config_settings_enc_key']);
$ok(!$hasKey || $chain->verify()['status'] === 'broken', $hasKey ? 'verify: a chain re-hashed without the settings key is rejected' : 'verify: (no settings key on this install: plain SHA-256, nothing to test)');
$restore();
// cut tail: needs a head recorded by an earlier verify
$chain->record($chain->verify());
$tailId = (int) end($snapshot)['audit_id'];
$q("DELETE FROM audit_events WHERE audit_id = $tailId");
$v = $chain->verify();
$ok($v['status'] === 'broken' && $v['broken_at'] === $tailId && str_contains($v['reason'], 'deleted'), 'verify: removing the newest row is found once a verification has recorded the head');
$restore();
// retention removes the oldest rows: allowed
$q("DELETE FROM audit_events WHERE audit_id <= " . (int) $snapshot[1]['audit_id']);
$v = $chain->verify();
$ok($v['status'] === 'ok' && $v['checked'] === count($snapshot) - 2, 'verify: retention removing the OLD end keeps the chain valid');
$restore();
// key changed
if ($hasKey) {
    $other = new AuditChain($db, hash_hmac('sha256', 'a different key', 'x'));
    $ok($other->verify()['status'] === 'key_changed', 'verify: a different settings key is reported as "key changed", not as tampering');
    $nokey = new AuditChain($db, null);
    $ok($nokey->verify()['status'] === 'key_unavailable' || $nokey->verify()['status'] === 'ok', 'verify: no key with an hmac chain is reported as unavailable');
} else {
    $ok(true, 'verify: (no settings key on this install)');
}
// reorder: swap two rows' hashes positions by moving ids
$q("UPDATE audit_events SET audit_id = audit_id + 1000 WHERE audit_id = " . (int) $snapshot[1]['audit_id']);
$ok($chain->verify()['status'] === 'broken', 'verify: a reordered row is found');
$restore();

// ---------------------------------------------------------------- nightly verify, alert and the cron wiring
$ok($chain->verify()['status'] === 'ok', 'restored chain is ok again');
$q("UPDATE audit_events SET summary = 'tampered' WHERE audit_id = $id3");
$out = Nightly::run($db, $root, true);
$ok(($out['audit_chain']['status'] ?? '') === 'broken' && (int) ($out['audit_chain']['broken_at'] ?? 0) === $id3, 'nightly: the verify finds the break');
$ok((int) $one("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key = 'audit_chain.broken'") === 1, 'nightly: an alert is raised (de-duplicated by key)');
$ok((int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Recovery' AND notification LIKE '%tampering%'") >= 1, 'nightly: the in-app notification names it');
$before = (int) $one("SELECT alert_send_count FROM recovery_alerts WHERE alert_key = 'audit_chain.broken'");
Nightly::verifyAuditChain($db);
$ok((int) $one("SELECT alert_send_count FROM recovery_alerts WHERE alert_key = 'audit_chain.broken'") === $before, 'nightly: the same break does not alert again within the window');
$restore();
Nightly::verifyAuditChain($db);
$ok((int) $one("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key = 'audit_chain.broken'") === 0, 'nightly: a clean check clears the alert so the next break alerts at once');
$ok(Nightly::run($db, $root)['sealed'] === 0 && !array_key_exists('audit_chain', Nightly::run($db, $root)), 'nightly: a second run on the same day only seals');
$cron = file_get_contents($root . '/cron/cron.php');
$ok(str_contains($cron, '\\ITFlow\\Platform\\Nightly::run('), 'cron/cron.php calls the platform tasks');

// ---------------------------------------------------------------- sink
$ok(PlatformSettings::get($db, 'audit_sink_path') === '' && PlatformSettings::get($db, 'audit_sink_syslog') === '0', 'sink: off by default');
$svc->log('sink.before', 1, null, null, 'x', 'before the sink was on');
$ok(!glob("$tmp/*"), 'sink: nothing is written while it is off');
$ok(AuditSink::pathProblem('', $root) === null, 'sink: an empty path (off) is fine');
$ok(AuditSink::pathProblem('relative/audit.jsonl', $root) !== null && AuditSink::pathProblem("$tmp/../etc/x", $root) !== null && AuditSink::pathProblem("$root/uploads/audit.jsonl", $root) !== null && AuditSink::pathProblem("$tmp/nodir/audit.jsonl", $root) !== null, 'sink: relative, "..", inside the application folder and a missing directory are refused');
symlink('/etc/passwd', "$tmp/link.jsonl");
$ok(AuditSink::pathProblem("$tmp/link.jsonl", $root) !== null, 'sink: a symbolic link is refused');
$ok(AuditSink::pathProblem("$tmp", $root) !== null, 'sink: a directory is refused');
$ok(AuditSink::pathProblem("$tmp/audit.jsonl", $root) === null, 'sink: a writable file outside the application is accepted');
PlatformSettings::set($db, 'audit_sink_path', "$tmp/audit.jsonl");
$svc->log('sink.one', 7, 'thing', 42, 'create', 'First sunk event', ['a' => 'b']);
$svc->log('sink.two', null, null, null, 'x', 'Second sunk event');
$lines = array_values(array_filter(explode("\n", (string) @file_get_contents("$tmp/audit.jsonl"))));
$j = array_map(fn ($l) => json_decode($l, true), $lines);
$ok(count($lines) === 2 && $j[0]['event'] === 'sink.one' && $j[0]['actor_user_id'] === 7 && $j[0]['entity_id'] === '42' && $j[0]['metadata'] === ['a' => 'b'] && $j[1]['event'] === 'sink.two', 'sink: one JSON object per line with the event fields');
$ok(strlen($j[0]['row_hash']) === 64 && $j[1]['prev_hash'] === $j[0]['row_hash'] && substr($j[0]['ts'], -1) === 'Z', 'sink: each line carries its hashes (chained) and a UTC timestamp');
$ok(!str_contains(implode('', $lines), 'hunter2'), 'sink: nothing secret');
chmod("$tmp/audit.jsonl", 0400);
if (posix_geteuid() !== 0) { $svc->log('sink.unwritable', 1, null, null, 'x', 'read-only file'); $ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'sink.unwritable'") === 1, 'sink: a file that cannot be written never stops the audited action'); } else { $ok(true, 'sink: (running as root, skipped)'); }
chmod("$tmp/audit.jsonl", 0600);
PlatformSettings::set($db, 'audit_sink_path', '');

// ---------------------------------------------------------------- events: logAction mirror, roles, users, tokens, links, exports
$q("DELETE FROM audit_events");
$session_user_id = 1; $session_name = 'admin';
foreach ([['User Role', 'Create'], ['System', 'Backup Download'], ['Master Key', 'Download'], ['RMM Settings', 'Edit'], ['UniFi Settings', 'Create'], ['Firewall Settings', 'Edit'], ['Asset', 'Export'], ['Settings', 'Edit'], ['API Key', 'Create'], ['API Key', 'Revoke'], ['System', 'Backup Save'], ['Mailbox', 'Edit']] as [$t, $a]) {
    logAction($t, $a, "test $t $a", 0, 3);
}
$types = array_column($ev(), 'event_type');
foreach (['user_role.create', 'system.backup_download', 'master_key.download', 'rmm_settings.edit', 'unifi_settings.create', 'firewall_settings.edit', 'asset.export', 'settings.edit', 'api_key.create', 'api_key.revoke', 'system.backup_save', 'mailbox.edit'] as $t) {
    $ok(in_array($t, $types, true), "logAction mirrors $t into the audit trail");
}
$q("DELETE FROM audit_events");
logAction('System', 'Cron Task', 'not audited'); logAction('Ticket', 'Create', 'not audited');
$ok((int) $one("SELECT COUNT(*) FROM audit_events") === 0, 'ordinary activity-log entries are not mirrored');

// admin pages over HTTP
$sdir = $tmp . '/sess'; mkdir($sdir, 0700);
$q("DELETE FROM user_role_permissions"); $q("DELETE FROM modules"); $q("DELETE FROM user_settings"); $q("DELETE FROM user_client_permissions");
$q("INSERT INTO user_roles SET role_id = 40, role_name = 'Helpdesk', role_is_admin = 0, role_type = 1");
$q("INSERT INTO modules SET module_id = 5, module_name = 'module_support'"); $q("INSERT INTO modules SET module_id = 6, module_name = 'module_assets'"); $q("INSERT INTO modules SET module_id = 4, module_name = 'module_client'");
$q("INSERT INTO user_role_permissions SET user_role_id = 40, module_id = 5, user_role_permission_level = 1");
$q("INSERT INTO users SET user_id = 41, user_name = 'helper', user_email = 'helper@example.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 40");
$q("INSERT INTO user_settings SET user_id = 1"); $q("INSERT INTO user_settings SET user_id = 41");
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir"]);
$webLog0 = (int) @filesize($web['log']);   // the log name is per port: only what this run wrote counts
$wb = "http://127.0.0.1:{$web['port']}";
$sid = ea_forge_session($sdir, 1);
$wr = fn (string $m, string $p, array $post = [], array $h = []) => web($wb, $m, $p, $sid, $post, array_merge(['User-Agent: audit-chain-test'], $h));
$ref = fn (string $page) => ['Referer: ' . $wb . '/admin/' . $page];
$q("DELETE FROM audit_events");
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'edit_role' => 1, 'role_id' => 40, 'role_name' => 'Helpdesk', 'role_description' => '', 'role_is_admin' => 0, '5##module_support' => 3, '6##module_assets' => 2], $ref('roles.php'));
$e = $rows("SELECT * FROM audit_events WHERE event_type = 'role.permissions_changed'");
$meta = json_decode($e[0]['metadata_json'] ?? '{}', true);
$ok(count($e) === 1 && (int) $e[0]['actor_user_id'] === 1 && (int) $e[0]['entity_id'] === 40, 'role edit: a role.permissions_changed event with the actor and the role (' . count($e) . ' found)');
$lv = (int) $one("SELECT user_role_permission_level FROM user_role_permissions WHERE user_role_id = 40 AND module_id = 5");
$ok($lv === 3 || $lv === 1, 'role edit: the form was processed (support level now ' . $lv . ')');
if ($lv === 3) {
    $by = array_column($meta['changes'] ?? [], null, 'module');
    $ok(($by['module_support']['from'] ?? null) === 1 && ($by['module_support']['to'] ?? null) === 3, 'role edit: the event records module, before and after');
} else { $ok(false, 'role edit: permissions did not change; form fields differ: ' . json_encode($_POST)); }
$q("DELETE FROM audit_events");
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'edit_role' => 1, 'role_id' => 40, 'role_name' => 'Helpdesk', 'role_description' => 'renamed', 'role_is_admin' => 0, '5##module_support' => 3, '6##module_assets' => 2], $ref('roles.php'));
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'role.permissions_changed'") === 0 && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'user_role.edit'") === 1, 'role edit: no permission event when only the name changed, but the role edit itself is audited');
$q("DELETE FROM audit_events");
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'add_role' => 1, 'role_name' => 'Auditors', 'role_description' => '', 'role_is_admin' => 0, '5##module_support' => 1], $ref('roles.php'));
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'role.created'") === 1 && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'user_role.create'") === 1, 'role create: audited with its levels');
// pages render
[$c, $body] = $wr('GET', '/admin/audit_trail.php');
$ok($c === 200 && str_contains($body, 'id="audit-integrity"') && str_contains($body, 'name="verify_audit_chain"') && str_contains($body, 'name="audit_sink_path"'), 'audit page: the integrity panel with Verify now and the sink form');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'verify_audit_chain' => 1], $ref('audit_trail.php'));
$ok(json_decode(PlatformSettings::get($db, 'audit_chain_last_verify'), true)['status'] === 'ok', 'audit page: Verify now runs the check and records it');
[$c, $body] = $wr('GET', '/admin/audit_trail.php');
$ok(str_contains($body, 'data-audit-chain-status="ok"') && str_contains($body, 'Intact'), 'audit page: the panel shows the last result');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'save_audit_sink' => 1, 'audit_sink_path' => "$tmp/web.jsonl", 'audit_sink_syslog' => 1], $ref('audit_trail.php'));
$ok(PlatformSettings::get($db, 'audit_sink_path') === "$tmp/web.jsonl" && PlatformSettings::get($db, 'audit_sink_syslog') === '1', 'audit page: the sink settings are saved');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'save_audit_sink' => 1, 'audit_sink_path' => "$root/uploads/x.jsonl"], $ref('audit_trail.php'));
$ok(PlatformSettings::get($db, 'audit_sink_path') === "$tmp/web.jsonl", 'audit page: a path inside the application folder is refused and nothing changes');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'save_audit_sink' => 1, 'audit_sink_path' => ''], $ref('audit_trail.php'));
$ok(PlatformSettings::get($db, 'audit_sink_path') === '' && PlatformSettings::get($db, 'audit_sink_syslog') === '0', 'audit page: clearing the path and the switch turns it off');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'settings.edit'") >= 3, 'audit page: its own setting changes are audited');
// user edit: role and department access
$q("INSERT INTO clients SET client_id = 5, client_name = 'Dept E'");
$q("DELETE FROM audit_events");
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'edit_user' => 1, 'user_id' => 41, 'name' => 'helper', 'email' => 'helper@example.test', 'role' => 1, 'new_password' => '', 'clients' => [5]], $ref('users.php'));
$ok((int) $one("SELECT user_role_id FROM users WHERE user_id = 41") === 1, 'user edit: the form was processed (role changed)');
$e = $rows("SELECT * FROM audit_events WHERE event_type = 'user.role_changed'");
$m = json_decode($e[0]['metadata_json'] ?? '{}', true);
$ok(count($e) === 1 && (int) $m['role_before'] === 40 && (int) $m['role_after'] === 1 && $m['role_before_name'] === 'Helpdesk', 'user edit: user.role_changed with before and after');
$e = $rows("SELECT * FROM audit_events WHERE event_type = 'user.department_access_changed'");
$m = json_decode($e[0]['metadata_json'] ?? '{}', true);
$ok(count($e) === 1 && $m['before'] === [] && $m['after'] === [5], 'user edit: user.department_access_changed with before and after');
$q("DELETE FROM audit_events");
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'edit_user' => 1, 'user_id' => 41, 'name' => 'helper', 'email' => 'helper@example.test', 'role' => 1, 'new_password' => '', 'clients' => [5]], $ref('users.php'));
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type IN ('user.role_changed','user.department_access_changed')") === 0, 'user edit: nothing changed -> no role or access event');
// non-admin cannot reach admin handlers
$sid41 = ea_forge_session($sdir, 41);
$q("UPDATE users SET user_role_id = 40 WHERE user_id = 41");
$q("DELETE FROM audit_events");
web($wb, 'POST', '/admin/post.php', $sid41, ['csrf_token' => 'csrftok1', 'edit_role' => 1, 'role_id' => 40, 'role_name' => 'Helpdesk', 'role_description' => '', 'role_is_admin' => 1, '5##module_support' => 3], ['User-Agent: t', ...$ref('roles.php')]);
$ok((int) $one("SELECT role_is_admin FROM user_roles WHERE role_id = 40") === 0, 'a non-administrator cannot promote their own role');
// API token revoke (user page)
$q("INSERT INTO api_tokens SET token_user_id = 41, token_hash = '" . hash('sha256', 'tok41') . "', token_created_at = NOW(), token_last_used_at = NOW()");
$tid = (int) $db->insert_id;
$q("DELETE FROM audit_events");
$c = curl_init($wb . '/agent/user/api_token_revoke.php');
curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['token_id' => $tid, 'csrf_token' => 'csrftok1']), CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => "PHPSESSID=$sid41", CURLOPT_HTTPHEADER => ['User-Agent: t']]);
$raw = (string) curl_exec($c); curl_close($c);
// (the endpoint prints the page shell before its JSON - an existing quirk of includes/inc_all_user.php - so match the JSON at the end)
$ok(preg_match('/\{"ok":true\}\s*$/', $raw) === 1 && (int) $one("SELECT COUNT(*) FROM api_tokens WHERE token_id = $tid") === 0 && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'api_token.revoke' AND actor_user_id = 41") === 1, 'API token revoke: audited with the actor');
$ok(!preg_match('/PHP (Warning|Fatal)/', (string) substr((string) @file_get_contents($web['log']), $webLog0)), 'server log has no PHP warnings or fatals');
