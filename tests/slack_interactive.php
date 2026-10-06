<?php
/*
 * Slack interactive buttons (Acknowledge / Assign to me) against the REAL src/Webhooks code, a scratch database, a LOCAL mock of Slack
 * (tests/mock/slack_webhook.php: incoming webhook, users.info, response_url) and the real public endpoint /slack_interactive.php served
 * by php -S. Never contacts Slack. Covers: signature verification (valid, bad, wrong secret, stale, future, tampered, malformed), replay,
 * destination selection, buttons only when a signing secret exists, action handling, the Slack-user -> agent mapping (opt-in, verified email
 * only, every takeover attempt refused), role / department checks, secrets stored encrypted and never logged.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/slack_interactive.php
 * The HTTP section needs config.php to point at the same scratch database (it is skipped otherwise).
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
define('RIVETIT_CHAT_ALLOW_LOCAL_HTTP', true);

use ITFlow\Webhooks\{ChatDelivery, ChatFormatter, SlackInteractive};

function encryptSetting(string $p): string { return $p === '' ? '' : 'ENC2:' . strrev($p); }
function decryptSetting(string $c): string { return str_starts_with($c, 'ENC2:') ? strrev(substr($c, 5)) : $c; }

mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
date_default_timezone_set('UTC');
$fail = 0;
$ok = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fail++; };
$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;

// ---- local mock Slack
$port = random_int(20000, 40000);
$mlog = sys_get_temp_dir() . '/slack_mock_' . getmypid() . '.jsonl';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/mock/slack_webhook.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MOCK_LOG' => $mlog] + $_ENV + ['PATH' => getenv('PATH')]);
$procs = [$proc];
register_shutdown_function(function () use (&$procs, $mlog) { foreach ($procs as $p) { proc_terminate($p); } @unlink($mlog); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
define('RIVETIT_SLACK_API_BASE', "http://127.0.0.1:$port/api");
$base = "http://127.0.0.1:$port";
$mockLog = fn () => array_map(fn ($l) => json_decode($l, true), is_file($mlog) ? file($mlog, FILE_IGNORE_NEW_LINES) : []);

// ======================================================= signature (pure)
$secret = 's3cr3t-signing-key';
$ts = '1700000000'; $body = 'payload=%7B%22type%22%3A%22block_actions%22%7D';
$sig = SlackInteractive::signature($secret, $ts, $body);
$ok($sig === 'v0=' . hash_hmac('sha256', "v0:$ts:$body", $secret), 'signature is HMAC-SHA256 over v0:timestamp:body');
// Slack's documented example (https://api.slack.com/authentication/verifying-requests-from-slack)
$docBody = 'token=xyzz0WbapA4vBCDEFasx0q6G&team_id=T1DC2JH3J&team_domain=testteamnow&channel_id=G8PSS9T3V&channel_name=foobar&user_id=U2CERLKJA&user_name=roadrunner&command=%2Fwebhook-collect&text=&response_url=https%3A%2F%2Fhooks.slack.com%2Fcommands%2FT1DC2JH3J%2F397700885554%2F96rGlfmibIGlgcZRskXaIFfN&trigger_id=398738663015.47445629121.803a0bc887a14d10d2c447fce8b6703c';
$ok(SlackInteractive::signature('8f742231b10e8888abcd99yyyzzz85a5', '1531420618', $docBody) === 'v0=a2114d57b48eac39b9ad189dd8316235a7b4a8d21a10bd27519666489c69b503', "matches the example in Slack's own documentation");
$now = 1700000100;
$ok(SlackInteractive::verify($secret, $ts, $body, $sig, $now), 'valid signature, fresh timestamp: accepted');
$ok(!SlackInteractive::verify($secret, $ts, $body, 'v0=' . str_repeat('0', 64), $now), 'wrong signature: rejected');
$ok(!SlackInteractive::verify('other-secret', $ts, $body, $sig, $now), 'signed with another secret: rejected');
$ok(!SlackInteractive::verify($secret, $ts, $body . 'x', $sig, $now), 'tampered body: rejected');
$ok(!SlackInteractive::verify($secret, '1700000001', $body, $sig, $now), 'tampered timestamp: rejected');
$ok(!SlackInteractive::verify($secret, $ts, $body, $sig, 1700000000 + 301), 'stale timestamp (301 s old): rejected');
$ok(SlackInteractive::verify($secret, $ts, $body, $sig, 1700000000 + 300), 'timestamp exactly 300 s old: accepted (5 minute window)');
$ok(!SlackInteractive::verify($secret, $ts, $body, $sig, 1700000000 - 301), 'timestamp 301 s in the future: rejected');
$ok(!SlackInteractive::verify($secret, $ts, $body, strtoupper($sig), $now) && !SlackInteractive::verify($secret, $ts, $body, substr($sig, 3), $now) && !SlackInteractive::verify($secret, $ts, $body, '', $now) && !SlackInteractive::verify($secret, $ts, $body, "$sig ", $now), 'malformed signature headers (case, no v0=, empty, trailing space): rejected');
$ok(!SlackInteractive::verify($secret, '', $body, $sig, $now) && !SlackInteractive::verify($secret, '17e8', $body, $sig, $now) && !SlackInteractive::verify($secret, '-1', $body, $sig, $now), 'missing or non-numeric timestamp: rejected');
$ok(!SlackInteractive::verify('', $ts, $body, SlackInteractive::signature('', $ts, $body), $now), 'an empty secret never verifies (no secret configured = no way in)');

// ======================================================= fixtures
foreach (['slack_interactive_seen', 'ticket_replies', 'tickets', 'webhooks', 'webhook_deliveries', 'user_client_permissions', 'user_role_permissions', 'modules', 'users', 'ticket_statuses', 'clients', 'integration_jobs'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM settings WHERE company_id = 1"); $q("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.140'");
foreach ([[1, 'New'], [2, 'Open'], [5, 'Closed']] as [$id, $n]) { $q("INSERT INTO ticket_statuses SET ticket_status_id = $id, ticket_status_name = '$n', ticket_status_color = '#000', ticket_status_active = 1"); }
$q("INSERT INTO clients SET client_id = 1, client_name = 'Dept A'"); $q("INSERT INTO clients SET client_id = 2, client_name = 'Dept B'");
$q("INSERT INTO user_roles (role_id, role_name, role_is_admin, role_type) VALUES (1, 'Admin', 1, 1), (2, 'Tech', 0, 1) ON DUPLICATE KEY UPDATE role_is_admin = VALUES(role_is_admin)");
$q("INSERT INTO modules SET module_id = 1, module_name = 'module_support', module_description = 'x'");
$q("INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 1, user_role_permission_level = 2");
$mkUser = fn (int $id, string $name, string $email, int $role, int $status = 1, string $arch = 'NULL', int $type = 1) => $q("INSERT INTO users SET user_id = $id, user_name = '$name', user_email = '$email', user_password = 'x', user_type = $type, user_status = $status, user_archived_at = $arch, user_role_id = $role");
$mkUser(10, 'Alice Admin', 'alice@x.test', 1);
$mkUser(11, 'Bob Tech', 'bob@x.test', 2);
$mkUser(12, 'Dup One', 'dup@x.test', 2); $mkUser(13, 'Dup Two', 'DUP@x.test', 2);
$mkUser(14, 'Archived', 'arch@x.test', 2, 1, 'NOW()');
$mkUser(15, 'Portal Pat', 'pat@x.test', 0, 1, 'NULL', 2);
$mkTicket = function (int $client = 1, int $status = 1, int $assigned = 0, ?string $closed = null) use ($q, $db) {
    static $n = 7000; $n++;
    $q("INSERT INTO tickets SET ticket_prefix = 'T', ticket_number = $n, ticket_subject = 'Printer <b>down</b>', ticket_details = 'x', ticket_status = $status, ticket_client_id = $client, ticket_assigned_to = $assigned, ticket_closed_at = " . ($closed ? "'$closed'" : 'NULL'));
    return (int) $db->insert_id;
};
$setWh = function (string $type, string $secret, string $clients = '', int $enabled = 1) use ($q, $db) {
    $enc = $secret === '' ? '' : encryptSetting($secret);
    $q("INSERT INTO webhooks SET webhook_name = 'hook', webhook_url = '" . encryptSetting('https://hooks.slack.com/services/T/B/x') . "', webhook_secret = '$enc', webhook_events = 'ticket.created', webhook_enabled = $enabled, webhook_type = '$type', webhook_client_ids = '$clients'");
    return (int) $db->insert_id;
};
$setCfg = fn (int $link, string $token = 'xoxb-good', string $team = '') => $q("UPDATE settings SET config_slack_link_by_email = $link, config_slack_bot_token = '" . encryptSetting($token) . "', config_slack_team_id = '$team' WHERE company_id = 1");
$audits = [];
$auditFn = function (string $e, ?int $a, string $et, $id, string $act, string $sum, array $meta = []) use (&$audits) { $audits[] = compact('e', 'a', 'sum', 'meta'); };
$si = new SlackInteractive($db, null, $auditFn);
$click = fn (string $action, int $ticket, string $user, array $extra = []) => ['type' => 'block_actions', 'user' => ['id' => $user, 'name' => 'forged', 'username' => 'alice'] + ($extra['user'] ?? []), 'team' => ['id' => $extra['team'] ?? 'T1'], 'response_url' => "$base/response/ok", 'actions' => [['action_id' => $action, 'value' => "ticket:$ticket"]]];
$status = fn (int $t) => (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $t");
$assignee = fn (int $t) => (int) $one("SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $t");
$notes = fn (int $t) => (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $t");

// ======================================================= destinations, replay
$h1 = $setWh('slack', 'secret-one'); $h2 = $setWh('slack', 'secret-two'); $hT = $setWh('teams', 'secret-teams'); $hOff = $setWh('slack', 'secret-off', '', 0); $hNone = $setWh('slack', '');
$stored = $one("SELECT webhook_secret FROM webhooks WHERE webhook_id = $h1");
$ok($stored !== 'secret-one' && str_starts_with($stored, 'ENC2:') && !str_contains($stored, 'secret-one'), 'the signing secret is stored encrypted on the destination');
$b = 'payload=%7B%7D'; $t0 = (string) time();
$d = $si->destinationFor($t0, $b, SlackInteractive::signature('secret-two', $t0, $b), time());
$ok($d !== null && (int) $d['webhook_id'] === $h2, 'the destination whose secret signed the request is selected');
$ok($si->destinationFor($t0, $b, SlackInteractive::signature('secret-teams', $t0, $b), time()) === null, 'a Teams destination (or any non-Slack row) can never authenticate a request');
$ok($si->destinationFor($t0, $b, SlackInteractive::signature('secret-off', $t0, $b), time()) === null, 'a disabled destination cannot authenticate a request');
$ok($si->destinationFor($t0, $b, SlackInteractive::signature('', $t0, $b), time()) === null, 'a destination with no secret cannot authenticate (empty-secret signature refused)');
$ok($si->destinationFor((string) (time() - 400), $b, SlackInteractive::signature('secret-one', (string) (time() - 400), $b), time()) === null, 'stale request refused at destination lookup');
$s1 = SlackInteractive::signature('secret-one', $t0, $b);
$ok($si->claimOnce($s1) === true && $si->claimOnce($s1) === false, 'replay: the same signature is accepted once, refused the second time');
$ok($si->claimOnce(SlackInteractive::signature('secret-one', $t0, $b . 'x')) === true, 'a different request is not blocked by the replay table');
$q("UPDATE slack_interactive_seen SET seen_at = NOW() - INTERVAL 11 MINUTE");
$si->claimOnce('v0=' . str_repeat('a', 64));
$ok((int) $one("SELECT COUNT(*) FROM slack_interactive_seen") === 1, 'replay records older than the window are purged (table stays small)');

// ======================================================= buttons only with a signing secret
$data = ['ticket_id' => 42, 'ticket_number' => 'T-42', 'ticket_subject' => 'Printer', 'ticket_priority' => 'High', 'client_name' => 'Acme'];
$opts = ['ticket_url' => 'https://h.example.test/agent/ticket.php?ticket_id=42'];
$plain = json_encode(ChatFormatter::slack('ticket.created', $data, $opts));
$inter = ChatFormatter::slack('ticket.created', $data, $opts + ['interactive' => true]);
$btns = end($inter['blocks'])['elements'];
$ok(!str_contains($plain, 'rivet_ack') && !str_contains($plain, 'Acknowledge'), 'no signing secret: no interactive buttons (message unchanged)');
$ok(count($btns) === 3 && $btns[1]['action_id'] === 'rivet_ack' && $btns[1]['value'] === 'ticket:42' && $btns[2]['action_id'] === 'rivet_assign' && $btns[2]['text']['text'] === 'Assign to me' && isset($btns[0]['url']), 'with a signing secret: Open ticket + Acknowledge + Assign to me');
$ok(!str_contains(json_encode(ChatFormatter::slack('ticket.created', $data, $opts + ['interactive' => true, 'test' => true])), 'rivet_ack') && !str_contains(json_encode(ChatFormatter::slack('login.failed', ['summary' => 's'], ['interactive' => true])), 'rivet_ack') && !str_contains(json_encode(ChatFormatter::teams('ticket.created', $data, $opts + ['interactive' => true])), 'rivet_ack'), 'no buttons on a test message, a platform event or a Teams card');
// through real delivery to the mock
$row1 = mysqli_fetch_assoc($q("SELECT * FROM webhooks WHERE webhook_id = $h1")); $row1['webhook_url'] = encryptSetting("$base/slack/ok");
$rowNone = mysqli_fetch_assoc($q("SELECT * FROM webhooks WHERE webhook_id = $hNone")); $rowNone['webhook_url'] = encryptSetting("$base/slack/ok");
@unlink($mlog);
$r1 = ChatDelivery::deliverRow($db, $row1, 'ticket.created', $data);
$r2 = ChatDelivery::deliverRow($db, $rowNone, 'ticket.created', $data);
$ml = $mockLog();
$ok($r1['ok'] && $r2['ok'] && str_contains($ml[0]['body'], 'rivet_assign') && !str_contains($ml[1]['body'], 'rivet_assign'), 'delivery: buttons are sent only to the destination that has a signing secret');
$ok(!str_contains($ml[0]['body'], 'secret-one') && !str_contains((string) $one("SELECT GROUP_CONCAT(request_payload_json) FROM webhook_deliveries"), 'secret-one'), 'the signing secret is in no request body and no delivery log');

// ======================================================= handle(): not linked unless the install opted in
$tk = $mkTicket();
$wh = mysqli_fetch_assoc($q("SELECT * FROM webhooks WHERE webhook_id = $h1"));
$setCfg(0);
$res = $si->handle($wh, $click('rivet_assign', $tk, 'UALICE'));
$ok($res['acted'] === false && str_contains($res['text'], "isn't linked") && $assignee($tk) === 0 && $notes($tk) === 0 && $mockLog() === $ml, 'opt-in OFF (default): replies "not linked", changes nothing, asks Slack nothing');
// ======================================================= opt-in ON
$setCfg(1);
$audits = [];
$res = $si->handle($wh, $click('rivet_ack', $tk, 'UALICE'));
$ok($res['acted'] && str_contains($res['text'], 'Acknowledged T7') && $notes($tk) === 1 && $one("SELECT ticket_reply_by FROM ticket_replies WHERE ticket_reply_ticket_id = $tk") == 10 && $one("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id = $tk") === 'Acknowledged in Slack by Alice Admin.', 'Acknowledge: internal note by the mapped agent');
$ok($audits[0]['e'] === 'ticket.slack_acknowledged' && $audits[0]['a'] === 10, 'Acknowledge is audited with the agent as actor');
$res = $si->handle($wh, $click('rivet_assign', $tk, 'UALICE'));
$ok($res['acted'] && $assignee($tk) === 10 && $status($tk) === 2 && $notes($tk) === 2, 'Assign to me: assigned to the mapped agent, New -> Open, note added');
$res = $si->handle($wh, $click('rivet_assign', $tk, 'UALICE'));
$ok(!$res['acted'] && str_contains($res['text'], 'already assigned') && $notes($tk) === 2, 'assigning again to the same agent changes nothing');
$authSeen = array_values(array_filter($mockLog(), fn ($e) => $e['path'] === '/api/users.info'));
$ok(count($authSeen) >= 3 && $authSeen[0]['auth'] === 'Bearer xoxb-good', 'the bot token is sent to users.info as a bearer token');
// takeover attempts: each must refuse and change nothing
$tk2 = $mkTicket(); $before = [$assignee($tk2), $notes($tk2), $status($tk2)];
foreach (['UUNCONF' => 'unconfirmed email', 'UBOT' => 'a bot', 'UGUEST' => 'a guest', 'UOTHERTEAM' => 'a member of another workspace', 'UNOAGENT' => 'an email no agent has', 'UDUP' => 'an email two agents share', 'UNOEMAIL' => 'no email at all', 'UNKNOWN9' => 'a user Slack does not know', 'lower' => 'a malformed id', '' => 'no user id'] as $uid => $why) {
    $r = $si->handle($wh, $click('rivet_assign', $tk2, (string) $uid));
    $ok(!$r['acted'] && str_contains((string) $r['text'], "isn't linked") && [$assignee($tk2), $notes($tk2), $status($tk2)] === $before, "takeover attempt refused: $why");
}
// forged fields in the payload (name, username, a profile email) are never used for identity
$forged = $click('rivet_assign', $tk2, 'UNOAGENT', ['user' => ['name' => 'Alice Admin', 'username' => 'alice@x.test', 'email' => 'alice@x.test', 'profile' => ['email' => 'alice@x.test']]]);
$r = $si->handle($wh, $forged);
$ok(!$r['acted'] && $assignee($tk2) === 0, 'a payload that claims to be alice (name, username, email fields) is not believed: only the signed user id and Slack\'s own record count');
$q("UPDATE settings SET config_slack_bot_token = '" . encryptSetting('xoxb-bad') . "'");
$r = $si->handle($wh, $click('rivet_assign', $tk2, 'UALICE'));
$ok(!$r['acted'] && str_contains($r['text'], "isn't linked") && $assignee($tk2) === 0, 'a rejected bot token fails closed');
$q("UPDATE settings SET config_slack_bot_token = ''");
$ok(!$si->handle($wh, $click('rivet_assign', $tk2, 'UALICE'))['acted'], 'no bot token configured: fails closed');
$setCfg(1);
// agents that must not be mapped
$ok($si->agentForSlackUser('UALICE', encryptSetting('xoxb-good'), 'T1') === 10, 'unit: confirmed email maps to its one active agent');
$q("UPDATE users SET user_status = 0 WHERE user_id = 10");
$ok($si->agentForSlackUser('UALICE', encryptSetting('xoxb-good'), 'T1') === null, 'a disabled agent is never mapped');
$q("UPDATE users SET user_status = 1, user_archived_at = NOW() WHERE user_id = 10");
$ok($si->agentForSlackUser('UALICE', encryptSetting('xoxb-good'), 'T1') === null, 'an archived agent is never mapped');
$q("UPDATE users SET user_archived_at = NULL, user_type = 2 WHERE user_id = 10");
$ok($si->agentForSlackUser('UALICE', encryptSetting('xoxb-good'), 'T1') === null, 'a portal (client) login with the same email is never mapped');
$q("UPDATE users SET user_type = 1 WHERE user_id = 10");
$ok($si->agentForSlackUser('UALICE', encryptSetting('xoxb-good'), 'T9') === null, "a user whose workspace differs from the interaction's is refused");
// role / department checks
$tk3 = $mkTicket(1); $bobWh = $wh;
$q("UPDATE user_role_permissions SET user_role_permission_level = 1 WHERE user_role_id = 2");
$ok(!$si->handle($bobWh, $click('rivet_assign', $tk3, 'UBOBB'))['acted'] && $assignee($tk3) === 0, 'a role with read-only support access cannot act from Slack');
$q("UPDATE user_role_permissions SET user_role_permission_level = 2 WHERE user_role_id = 2");
$q("INSERT INTO user_client_permissions SET user_id = 11, client_id = 2");
$ok(!$si->handle($bobWh, $click('rivet_assign', $tk3, 'UBOBB'))['acted'] && $assignee($tk3) === 0, 'an agent restricted to another department cannot act on this one');
$q("INSERT INTO user_client_permissions SET user_id = 11, client_id = 1");
$ok($si->handle($bobWh, $click('rivet_assign', $tk3, 'UBOBB'))['acted'] && $assignee($tk3) === 11, 'with write access and department access a technician can assign to themselves');
// ticket / payload edge cases
$closed = $mkTicket(1, 5, 0, '2026-01-01 00:00:00');
$ok(str_contains($si->handle($wh, $click('rivet_assign', $closed, 'UALICE'))['text'], 'closed') && $assignee($closed) === 0, 'a closed ticket is not changed');
$ok(str_contains($si->handle($wh, $click('rivet_assign', 999999, 'UALICE'))['text'], 'no longer exists'), 'a missing ticket is reported, nothing happens');
$bad = $click('rivet_assign', 1, 'UALICE'); $bad['actions'][0]['value'] = 'ticket:1; DROP TABLE tickets';
$ok(!$si->handle($wh, $bad)['acted'], 'a malformed button value is refused');
$bad['actions'][0]['value'] = 'ticket:0';
$ok(!$si->handle($wh, $bad)['acted'], 'ticket id 0 is refused');
$ok($si->handle($wh, $click('some_link_button', $tk, 'UALICE')) === ['text' => null, 'acted' => false] && $si->handle($wh, ['type' => 'view_submission']) === ['text' => null, 'acted' => false] && $si->handle($wh, ['type' => 'block_actions', 'actions' => []])['text'] === null, 'unknown actions, other payload types and empty action lists are ignored silently');
// workspace pin + destination client filter
$tk4 = $mkTicket(1);
$setCfg(1, 'xoxb-good', 'T1');
$ok($si->handle($wh, $click('rivet_assign', $tk4, 'UALICE', ['team' => 'T2']))['acted'] === false && $assignee($tk4) === 0, 'workspace pinned: an interaction from another workspace is refused');
$ok($si->handle($wh, $click('rivet_assign', $tk4, 'UALICE', ['team' => 'T1']))['acted'] === true, 'workspace pinned: the right workspace works');
$tk5 = $mkTicket(2); $whScoped = array_merge($wh, ['webhook_client_ids' => '1']);
$ok($si->handle($whScoped, $click('rivet_assign', $tk5, 'UALICE', ['team' => 'T1']))['acted'] === false && $assignee($tk5) === 0, "the destination's client filter still applies: no action on another department's ticket");
$ok(!str_contains(json_encode($audits), 'xoxb') && !str_contains(json_encode($audits), 'secret-one'), 'no token or signing secret in any audit event');

// ======================================================= ephemeral reply
@unlink($mlog);
$ok(SlackInteractive::respond("$base/response/ok", 'Assigned T-1 <!channel>') === true, 'the ephemeral answer is posted to response_url');
$rb = json_decode($mockLog()[0]['body'], true);
$ok($rb['response_type'] === 'ephemeral' && $rb['replace_original'] === false && !str_contains($rb['text'], '<!channel>'), 'the answer is ephemeral and its text is neutralised');
$ok(SlackInteractive::respond('https://evil.example.test/steal', 'x') === false && SlackInteractive::respond('http://169.254.169.254/latest', 'x') === false && SlackInteractive::respond('file:///etc/passwd', 'x') === false, 'only Slack hosts are ever answered (no SSRF through response_url)');

// ======================================================= the real endpoint over HTTP
$cfgText = (string) @file_get_contents(__DIR__ . '/../config.php');
if (preg_match('/\$database\s*=\s*[\'"]([^\'"]+)[\'"]/', $cfgText, $mm) && $mm[1] === getenv('RIVETIT_TEST_DB_NAME')) {
    $eport = random_int(40001, 50000);
    // The endpoint is a separate process that uses the app's REAL encryptSetting/decryptSetting: re-encrypt the fixtures with them,
    // and give that process the two test-only constants (local mock Slack API, loopback response_url).
    $realEnc = function (string $plain): string { return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$_SERVER["REQUEST_URI"]="/"; ob_start(); chdir(' . var_export(realpath(__DIR__ . '/..'), true) . '); require "config.php"; require "functions.php"; ob_end_clean(); echo encryptSetting($argv[1]);') . ' ' . escapeshellarg($plain))); };
    foreach (['secret-one' => $h1, 'secret-two' => $h2, 'secret-teams' => $hT] as $plainSecret => $hid) { $q("UPDATE webhooks SET webhook_secret = '" . $realEnc($plainSecret) . "' WHERE webhook_id = $hid"); }
    $prepend = sys_get_temp_dir() . '/slack_prepend_' . getmypid() . '.php';
    file_put_contents($prepend, "<?php define('RIVETIT_SLACK_API_BASE', '" . RIVETIT_SLACK_API_BASE . "'); define('RIVETIT_CHAT_ALLOW_LOCAL_HTTP', true);");
    register_shutdown_function(fn () => @unlink($prepend));
    $procs[] = proc_open([PHP_BINARY, '-d', "auto_prepend_file=$prepend", '-S', "127.0.0.1:$eport", '-t', realpath(__DIR__ . '/..')], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes2, null, $_ENV + ['PATH' => getenv('PATH')]);
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $eport)) break; usleep(100000); }
    $post = function (string $body, array $headers) use ($eport) {
        $ch = curl_init("http://127.0.0.1:$eport/slack_interactive.php");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers + [9 => 'Content-Type: application/x-www-form-urlencoded'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $out = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return [$code, (string) $out];
    };
    $signed = function (string $secret, array $payload, ?string $ts = null) {
        $ts ??= (string) time(); $body = 'payload=' . urlencode(json_encode($payload));
        return [$body, ['X-Slack-Request-Timestamp: ' . $ts, 'X-Slack-Signature: ' . SlackInteractive::signature($secret, $ts, $body)]];
    };
    $q("DELETE FROM slack_interactive_seen"); $setCfg(1);
    $q("UPDATE settings SET config_slack_bot_token = '" . $realEnc('xoxb-good') . "' WHERE company_id = 1");
    $tkE = $mkTicket();
    @unlink($mlog);
    [$body, $hdr] = $signed('secret-one', $click('rivet_assign', $tkE, 'UALICE'));
    [$code, $out] = $post($body, $hdr);
    $ok($code === 200 && $assignee($tkE) === 10, 'HTTP: a correctly signed "Assign to me" is accepted and applied');
    $ok(count(array_filter($mockLog(), fn ($e) => str_starts_with($e['path'], '/response/'))) === 1, 'HTTP: the clicker got one ephemeral answer on response_url');
    [$code, $out] = $post($body, $hdr);
    $ok($code === 200 && str_contains($out, 'already handled') && $notes($tkE) === 1, 'HTTP: replaying the identical request does nothing');
    [$b2, $h2x] = $signed('secret-one', $click('rivet_ack', $tkE, 'UALICE'), (string) (time() - 600));
    $ok($post($b2, $h2x)[0] === 401, 'HTTP: a stale (10 minute old) but correctly signed request gets 401');
    [$b3, $h3] = $signed('not-the-secret', $click('rivet_ack', $tkE, 'UALICE'));
    $ok($post($b3, $h3)[0] === 401 && $notes($tkE) === 1, 'HTTP: a wrong signature gets 401 and changes nothing');
    $ok($post($b3, [])[0] === 401, 'HTTP: no signature headers at all gets 401');
    [$b4, $h4] = $signed('secret-teams', $click('rivet_ack', $tkE, 'UALICE'));
    $ok($post($b4, $h4)[0] === 401, 'HTTP: a request signed with a Teams destination secret gets 401');
    $c = curl_init("http://127.0.0.1:$eport/slack_interactive.php"); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true]); curl_exec($c); $ok(curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 405, 'HTTP: GET is 405'); curl_close($c);
    [$b5, $h5] = $signed('secret-one', ['type' => 'block_actions', 'response_url' => 'https://evil.example.test/x', 'user' => ['id' => 'UNOAGENT'], 'team' => ['id' => 'T1'], 'actions' => [['action_id' => 'rivet_ack', 'value' => 'ticket:' . $tkE]]]);
    [$code5, $out5] = $post($b5, $h5);
    $ok($code5 === 200 && $notes($tkE) === 1, 'HTTP: an unlinked user with a hostile response_url is answered 200, changes nothing, and nothing is sent to that URL');
    $ok(!str_contains($out . $out5, 'secret-one') && !str_contains($out . $out5, 'xoxb'), 'HTTP: no secret or token in any response body');
} else {
    echo "SKIP  HTTP endpoint checks (config.php does not point at the scratch database)\n";
}

echo $fail === 0 ? "\nAll Slack interactive checks passed\n" : "\n$fail check(s) FAILED\n";
exit($fail === 0 ? 0 : 1);
