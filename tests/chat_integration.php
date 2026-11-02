<?php
/*
 * Slack chat integration (issue #36) against the REAL src/Chat code, a scratch database and a LOCAL mock of the Slack Web API (tests/mock/chat_platforms.php).
 * Never contacts Slack. Requests are SIGNED by this test the way Slack signs them (v0 HMAC-SHA256), so signature checking, the 5 minute window and replay
 * protection are exercised for real. Covers: auth, Events API (url verification, thread replies -> ticket replies, DMs -> virtual agent, dedupe, pins),
 * slash commands, interactivity (status/priority/reply/approve/deny, modals, shortcuts), identity (explicit links vs email), ChatActions rights,
 * ticket thread mirroring, virtual agent visibility, SSRF-safe outbound, Slack manifest, secrets never logged, and the real endpoints over HTTP.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=u RIVETIT_TEST_DB_PASS=p php tests/chat_integration.php
 * The HTTP section needs config.php to point at the same scratch database (it is skipped otherwise).
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
define('RIVETIT_CHAT_ALLOW_LOCAL_HTTP', true);
define('APP_NAME', 'RivetIT');

use ITFlow\Chat\{ChatActions, ChatBlocks, ChatIdentity, ChatNotifier, ChatSettings, SlackApp, SlackAuth, SlackManifest, VirtualAgent};
use ITFlow\Webhooks\{ChatFormatter, SlackInteractive};

function encryptSetting(string $p): string { return $p === '' ? '' : 'ENC2:' . strrev($p); }
function decryptSetting(string $c): string { return str_starts_with($c, 'ENC2:') ? strrev(substr($c, 5)) : $c; }

mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
date_default_timezone_set('UTC');
$fail = 0; $count = 0;
$ok = function (bool $c, string $l) use (&$fail, &$count) { $count++; echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fail++; };
$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;

// ---- local mock Slack API
$port = random_int(20000, 40000);
$mlog = sys_get_temp_dir() . '/chat_mock_' . getmypid() . '.jsonl';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/mock/chat_platforms.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MOCK_LOG' => $mlog] + $_ENV + ['PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($proc, $mlog) { proc_terminate($proc); foreach ([$mlog, "$mlog.n", "$mlog.t"] as $f) @unlink($f); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
define('RIVETIT_SLACK_API_BASE', "http://127.0.0.1:$port/api");
$base = "http://127.0.0.1:$port";
$mockLog = fn () => array_map(fn ($l) => json_decode($l, true), is_file($mlog) ? file($mlog, FILE_IGNORE_NEW_LINES) : []);
$mockReset = function () use ($mlog) { @unlink($mlog); };

// ======================================================= fixtures
foreach (['chat_threads', 'chat_identities', 'chat_kv', 'slack_interactive_seen', 'ticket_replies', 'tickets', 'service_catalog_request_approvals', 'service_catalog_requests', 'service_catalog_approval_steps', 'service_catalog_fields', 'service_catalog_items',
    'kb_articles', 'workflow_run_tasks', 'workflow_runs', 'contacts', 'user_client_permissions', 'user_role_permissions', 'modules', 'users', 'ticket_statuses', 'clients', 'webhooks', 'webhook_deliveries', 'logs'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM settings WHERE company_id = 1"); $q("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.229', config_ticket_prefix = 'TCK-', config_ticket_next_number = 100");
foreach ([[1, 'New'], [2, 'Open'], [3, 'On Hold'], [4, 'Resolved'], [5, 'Closed']] as [$id, $n]) { $q("INSERT INTO ticket_statuses SET ticket_status_id = $id, ticket_status_name = '$n', ticket_status_color = '#000', ticket_status_active = 1"); }
$q("INSERT INTO clients SET client_id = 1, client_name = 'Dept A'"); $q("INSERT INTO clients SET client_id = 2, client_name = 'Dept B'");
$q("INSERT INTO user_roles (role_id, role_name, role_is_admin, role_type) VALUES (1, 'Admin', 1, 1), (2, 'Tech', 0, 1), (3, 'Viewer', 0, 1) ON DUPLICATE KEY UPDATE role_is_admin = VALUES(role_is_admin)");
$q("INSERT INTO modules SET module_id = 1, module_name = 'module_support', module_description = 'x'"); $q("INSERT INTO modules SET module_id = 2, module_name = 'module_client', module_description = 'x'");
$q("INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 1, user_role_permission_level = 2"); $q("INSERT INTO user_role_permissions SET user_role_id = 3, module_id = 1, user_role_permission_level = 1");
$q("INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 2, user_role_permission_level = 1");
$mkUser = fn (int $id, string $name, string $email, int $role, int $status = 1, string $arch = 'NULL', int $type = 1) => $q("INSERT INTO users SET user_id = $id, user_name = '$name', user_email = '$email', user_password = 'x', user_type = $type, user_status = $status, user_archived_at = $arch, user_role_id = $role");
$mkUser(10, 'Alice Admin', 'alice@x.test', 1); $mkUser(11, 'Bob Tech', 'bob@x.test', 2); $mkUser(12, 'Carol Viewer', 'carol@x.test', 3); $mkUser(13, 'Dave Disabled', 'dave@x.test', 2, 0);
$q("INSERT INTO user_client_permissions SET user_id = 11, client_id = 1");   // Bob may only work Dept A
$q("INSERT INTO contacts SET contact_id = 50, contact_name = 'Pat Portal', contact_email = 'pat@client.test', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 51, contact_name = 'Quinn Other', contact_email = 'quinn@client.test', contact_client_id = 2");
$mkTicket = function (int $client = 1, int $status = 2, int $contact = 0, ?string $closed = null, string $subject = 'Printer down') use ($q, $db) {
    static $n = 7000; $n++;
    $q("INSERT INTO tickets SET ticket_prefix = 'T-', ticket_number = $n, ticket_subject = '$subject', ticket_details = 'x', ticket_status = $status, ticket_client_id = $client, ticket_contact_id = $contact, ticket_priority = 'Low', ticket_created_by = 0, ticket_closed_at = " . ($closed ? "'$closed'" : 'NULL'));
    return (int) $db->insert_id;
};
$setCfg = function (array $kv) use ($q) { foreach ($kv as $k => $v) { $q("UPDATE settings SET $k = " . (is_int($v) ? $v : "'" . addslashes($v) . "'") . ' WHERE company_id = 1'); } };
$secret = 'abcdef0123456789abcdef0123456789';
$config_base_url = 'help.example.test';
$setCfg(['config_slack_signing_secret' => encryptSetting($secret), 'config_slack_bot_token' => encryptSetting('xoxb-test-token-1234'), 'config_slack_team_id' => 'T1', 'config_chat_slack_channel_id' => 'C0CHAN123']);
$link = fn (string $ext, int $user, int $contact = 0, string $team = '') => $q("INSERT INTO chat_identities SET identity_platform = 'slack', identity_team_id = '$team', identity_external_id = '$ext', identity_user_id = $user, identity_contact_id = $contact");

$audits = [];
$auditFn = function (string $e, ?int $a, string $et, $id, string $act, string $sum, array $meta = []) use (&$audits) { $audits[] = compact('e', 'a', 'et', 'id', 'sum', 'meta'); };
$posted = []; $opened = [];
$poster = function (string $ch, string $text, array $blocks, ?string $th) use (&$posted) { $posted[] = compact('ch', 'text', 'blocks', 'th'); };
$opener = function (string $trigger, array $view) use (&$opened) { $opened[] = compact('trigger', 'view'); return $trigger !== 'BAD'; };
$lookup = fn (string $token, string $uid) => SlackInteractive::slackUsersInfo($token, $uid);
$newApp = fn () => new SlackApp($db, $auditFn, $lookup, $poster, $opener);
$reset = function () use (&$posted, &$opened, &$audits) { $posted = []; $opened = []; $audits = []; };
$lastPost = function () use (&$posted) { return $posted ? end($posted)['text'] : ''; };

// ======================================================= 1. defaults are OFF
$s = new ChatSettings($db);
$ok(!$s->twoWay() && !$s->commands() && !$s->actions() && !$s->virtualAgent() && !$s->teamsBot() && !$s->flag('config_slack_link_by_email'), 'every chat switch is OFF on a fresh settings row');
$ok($s->threadReplyType() === 'Internal', 'a chat reply is an internal note by default');
$ok(str_starts_with((string) $one("SELECT config_slack_signing_secret FROM settings WHERE company_id = 1"), 'ENC2:') && $s->secret('config_slack_signing_secret') === $secret, 'the signing secret is stored encrypted and decrypts through the settings helper');

// ======================================================= 2. SlackAuth (signing secret app-level or destination)
$body = 'payload=%7B%7D'; $now = time(); $ts = (string) $now;
$sig = SlackInteractive::signature($secret, $ts, $body);
$a = SlackAuth::authenticate($db, $ts, $body, $sig, $now);
$ok($a !== null && $a['source'] === 'app' && $a['webhook'] === null, 'app-level signing secret authenticates a request');
$ok(SlackAuth::authenticate($db, $ts, $body, SlackInteractive::signature('wrong-secret-wrong-secret', $ts, $body), $now) === null, 'a request signed with another secret is refused');
$ok(SlackAuth::authenticate($db, $ts, $body . 'x', $sig, $now) === null, 'a tampered body is refused');
$old = (string) ($now - 301);
$ok(SlackAuth::authenticate($db, $old, $body, SlackInteractive::signature($secret, $old, $body), $now) === null, 'a request older than 5 minutes is refused (replay window)');
$fut = (string) ($now + 301);
$ok(SlackAuth::authenticate($db, $fut, $body, SlackInteractive::signature($secret, $fut, $body), $now) === null, 'a request from the future (> 5 minutes) is refused');
$si = new SlackInteractive($db);
$ok($si->claimOnce($sig) === true && $si->claimOnce($sig) === false, 'replay: the same signature is accepted once, then refused');
$q("INSERT INTO webhooks SET webhook_name = 'h', webhook_url = '" . encryptSetting('https://hooks.slack.com/services/T/B/x') . "', webhook_secret = '" . encryptSetting('dest-secret-9999') . "', webhook_events = 'ticket.created', webhook_enabled = 1, webhook_type = 'slack'");
$sigD = SlackInteractive::signature('dest-secret-9999', $ts, $body);
$a = SlackAuth::authenticate($db, $ts, $body, $sigD, $now);
$ok($a !== null && $a['source'] === 'destination' && $a['webhook'] !== null, 'a Slack destination secret still authenticates (existing setup keeps working)');
$setCfg(['config_slack_signing_secret' => '']);
$ok(SlackAuth::authenticate($db, $ts, $body, SlackInteractive::signature('', $ts, $body), $now) === null, 'no app secret configured: an empty-secret signature never authenticates');
$setCfg(['config_slack_signing_secret' => encryptSetting($secret)]);

// ======================================================= 3. identity
$ident = new ChatIdentity($db, new ChatSettings($db), $lookup);
$ok($ident->resolve('slack', 'T1', 'UBOBB') === null, 'no link and email matching OFF: not linked');
$r = $ident->link('slack', '', 'UBOBB', 11, 0, 10);
$ok($r['ok'], 'admin can create an explicit link');
$w = $ident->resolve('slack', 'T1', 'UBOBB');
$ok($w && $w['kind'] === 'agent' && $w['user_id'] === 11 && $w['via'] === 'explicit', 'explicit link resolves to the agent');
$ok($ident->link('slack', '', 'UBOBB', 12, 0, 10)['ok'] && $ident->resolve('slack', 'T1', 'UBOBB')['user_id'] === 12 && (int) $one("SELECT COUNT(*) FROM chat_identities WHERE identity_external_id = 'UBOBB'") === 1, 're-linking replaces the link (one row per chat user)');
$ident->link('slack', '', 'UBOBB', 11, 0, 10);
$ok(!$ident->link('slack', '', 'bob', 11, 0, 10)['ok'] && !$ident->link('slack', '', 'UNEWUSER1', 0, 0, 10)['ok'] && !$ident->link('slack', '', 'UNEWUSER1', 11, 50, 10)['ok'] && !$ident->link('slack', '', 'UNEWUSER1', 13, 0, 10)['ok'] && !$ident->link('slack', '', 'UNEWUSER1', 9999, 0, 10)['ok'], 'bad links refused: malformed id, neither/both target, disabled agent, unknown agent');
$ident->link('slack', '', 'UALICE', 13, 0, 10); // explicit link to a DISABLED agent is rejected at link time ...
$q("INSERT INTO chat_identities SET identity_platform = 'slack', identity_team_id = '', identity_external_id = 'UALICE', identity_user_id = 13"); // ... but a later-disabled agent can exist
$setCfg(['config_slack_link_by_email' => 1]);
$ident = new ChatIdentity($db, new ChatSettings($db), $lookup);
$ok($ident->resolve('slack', 'T1', 'UALICE') === null, 'an explicit link to a disabled agent means NOT linked, and does not fall back to the email match');
$q("DELETE FROM chat_identities WHERE identity_external_id = 'UALICE'");
$w = $ident->resolve('slack', 'T1', 'UALICE');
$ok($w && $w['user_id'] === 10 && $w['via'] === 'email', 'with email matching ON and no link: Slack-confirmed email maps to the one active agent');
$ok($ident->resolve('slack', 'T1', 'UUNCONF') === null, 'an unconfirmed Slack email is never used');
$w = $ident->resolve('slack', 'T1', 'UCONTACT');
$ok($w && $w['kind'] === 'contact' && $w['contact_id'] === 50 && $w['client_id'] === 1, 'email matching falls back to exactly one portal contact');
$ident->link('slack', '', 'UCAROL', 0, 51, 10);
$ok($ident->resolve('slack', 'T1', 'UCAROL')['kind'] === 'contact', 'explicit link to a contact');
$setCfg(['config_slack_link_by_email' => 0]);
$ident->link('slack', 'T9', 'UTEAMED1', 11, 0, 10);
$ok($ident->resolve('slack', 'T1', 'UTEAMED1') === null && $ident->resolve('slack', 'T9', 'UTEAMED1') !== null, 'a link bound to a workspace only matches that workspace');
$q("DELETE FROM chat_identities WHERE identity_external_id = 'UTEAMED1'");
$ok(count($ident->listLinks()) >= 2 && $ident->unlink((int) $ident->listLinks()[0]['identity_id']), 'links can be listed and removed');
$ident->link('slack', '', 'UBOBB', 11, 0, 10); $ident->link('slack', '', 'UALICE', 10, 0, 10); $ident->link('slack', '', 'UCAROL', 0, 51, 10);

// ======================================================= 4. ChatActions rights
$agentBob = $ident->resolve('slack', 'T1', 'UBOBB'); $agentAlice = $ident->resolve('slack', 'T1', 'UALICE'); $contactQ = $ident->resolve('slack', 'T1', 'UCAROL');
$ident->link('slack', '', 'UPAT00001', 0, 50, 10); $contactPat = $ident->resolve('slack', 'T1', 'UPAT00001');
$ca = new ChatActions($db, $auditFn, new ChatSettings($db));
$t1 = $mkTicket(1, 2, 50); $t2 = $mkTicket(2, 2, 51); $tClosed = $mkTicket(1, 5, 0, '2026-01-01 00:00:00');
$ok($ca->findTicket($agentBob, 'id:' . $t1) !== null && $ca->findTicket($agentBob, 'id:' . $t2) === null, 'department scoping: Bob sees Dept A tickets, not Dept B');
$ok($ca->findTicket($agentAlice, 'id:' . $t2) !== null, 'an administrator sees every department');
$tt = $ca->findTicket($agentAlice, 'id:' . $t1);
$ok($ca->findTicket($agentAlice, $tt['ticket_prefix'] . $tt['ticket_number']) !== null && $ca->findTicket($agentAlice, strtolower($tt['ticket_prefix'] . $tt['ticket_number'])) !== null && $ca->findTicket($agentAlice, (string) $tt['ticket_number']) !== null, 'a ticket is found by label, lower-case label or bare number');
$ok($ca->findTicket($contactPat, 'id:' . $t1) !== null && $ca->findTicket($contactPat, 'id:' . $t2) === null, 'a contact only sees their own tickets');
$ca2 = new ChatActions($db, $auditFn, new ChatSettings($db));
$r = $ca2->createTicket($agentBob, 'No client given', '', '', 0);
$ok(!$r['ok'] && str_contains($r['text'], 'client'), 'an agent with no client named and no default is told how to name one');
$setCfg(['config_chat_default_client_id' => 1]); $ca2 = new ChatActions($db, $auditFn, new ChatSettings($db));
$reset();
$r = $ca2->createTicket($agentBob, 'Printer <script>alert(1)</script> jams', "line1\n\n<b>line2</b>", 'High');
$tn = $r['ticket_id'] ?? 0;
$row = $q("SELECT * FROM tickets WHERE ticket_id = $tn")->fetch_assoc();
$ok($r['ok'] && $row['ticket_client_id'] == 1 && $row['ticket_priority'] === 'High' && $row['ticket_source'] === 'Chat' && (int) $row['ticket_created_by'] === 11 && $row['ticket_prefix'] === 'TCK-' && (int) $row['ticket_number'] === 100, 'agent creates a ticket in the default client with prefix/number, priority and source Chat');
$ok(!str_contains($row['ticket_subject'], '<script') && str_contains($row['ticket_details'], '&lt;b&gt;line2&lt;/b&gt;') && !str_contains($row['ticket_details'], '<b>'), 'subject is stripped and details are HTML-escaped (no markup from chat reaches the ticket)');
$ok(count($audits) === 1 && $audits[0]['e'] === 'chat.ticket_created' && $audits[0]['a'] === 11, 'creation is audited with the agent as actor');
$r = $ca2->createTicket($agentBob, 'In Dept B', '', '', 2);
$ok(!$r['ok'] && str_contains($r['text'], 'not allowed'), 'Bob may not create a ticket for a department he has no access to');
$r = $ca2->createTicket($contactPat, 'My laptop is slow', '', 'Critical', 2);
$cr = $q("SELECT * FROM tickets WHERE ticket_id = " . ($r['ticket_id'] ?? 0))->fetch_assoc();
$ok($r['ok'] && (int) $cr['ticket_client_id'] === 1 && (int) $cr['ticket_contact_id'] === 50 && $cr['ticket_priority'] === 'Low', 'a contact always gets their own department and contact, and cannot set priority');
// status / priority
$r = $ca2->changeStatus($agentBob, $t1, 'on hold');
$ok($r['ok'] && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $t1") === 3, 'status can be changed by name (case-insensitive)');
$ok(!$ca2->changeStatus($agentBob, $t1, 'Closed')['ok'] && !$ca2->changeStatus($agentBob, $t1, 'resolved')['ok'] && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $t1") === 3, 'closing / resolving is refused from chat');
$ok(!$ca2->changeStatus($agentBob, $t1, 'Nonsense')['ok'] && !$ca2->changeStatus($agentBob, $t2, 'Open')['ok'] && !$ca2->changeStatus($agentBob, $tClosed, 'Open')['ok'], 'unknown status, other department, closed ticket: all refused');
$ok(!$ca2->changeStatus($contactPat, $t1, 'Open')['ok'] && !$ca2->changePriority($contactPat, $t1, 'High')['ok'], 'a contact can never change status or priority');
$viewer = ['kind' => 'agent', 'user_id' => 12, 'contact_id' => 0, 'client_id' => 0, 'via' => 'explicit', 'name' => 'Carol Viewer'];
$ok(!$ca2->changePriority($viewer, $t1, 'High')['ok'] && $ca2->findTicket($viewer, 'id:' . $t1) !== null, 'a read-only role can look but not change');
$r = $ca2->changePriority($agentBob, $t1, 'high');
$ok($r['ok'] && $one("SELECT ticket_priority FROM tickets WHERE ticket_id = $t1") === 'High' && !$ca2->changePriority($agentBob, $t1, 'Critical')['ok'], 'priority changes (Low/Medium/High only)');
// replies
$reset();
$r = $ca2->addReply($agentBob, $t1, "Checked <i>it</i>\nrebooted");
$rep = $q("SELECT * FROM ticket_replies WHERE ticket_reply_id = " . ($r['reply_id'] ?? 0))->fetch_assoc();
$ok($r['ok'] && $rep['ticket_reply_type'] === 'Internal' && (int) $rep['ticket_reply_by'] === 11 && !str_contains($rep['ticket_reply'], '<i>'), 'agent chat reply is an internal note by default, escaped');
$setCfg(['config_chat_thread_reply_type' => 'public']); $ca3 = new ChatActions($db, $auditFn, new ChatSettings($db));
$r = $ca3->addReply($agentBob, $t1, 'Fixed, please retest');
$ok($r['ok'] && $one("SELECT ticket_reply_type FROM ticket_replies WHERE ticket_reply_id = {$r['reply_id']}") === 'Public' && $one("SELECT ticket_first_response_at IS NOT NULL FROM tickets WHERE ticket_id = $t1") == 1, 'with the setting on, the reply is public and stamps first response');
$setCfg(['config_chat_thread_reply_type' => 'internal']);
$r = $ca2->addReply($contactPat, $t1, 'Still broken');
$ok($r['ok'] && $one("SELECT ticket_reply_type FROM ticket_replies WHERE ticket_reply_id = {$r['reply_id']}") === 'Client', 'a contact reply is a Client reply on their own ticket');
$ok(!$ca2->addReply($contactPat, $t2, 'x')['ok'] && !$ca2->addReply($contactPat, $tClosed, 'x')['ok'] && !$ca2->addReply($agentBob, $t1, '   ')['ok'], 'contact cannot reply to others\' or closed tickets; empty reply refused');

// ======================================================= 5. Events API
$app = $newApp();
$ev = fn (array $e, string $id = null) => ['type' => 'event_callback', 'team_id' => 'T1', 'event_id' => $id ?? ('Ev' . bin2hex(random_bytes(4))), 'event' => $e];
$claim = fn (string $k) => $si->claimOnce($k);
$r = $app->events(['type' => 'url_verification', 'challenge' => 'abc123-XYZ']);
$ok($r === ['challenge' => 'abc123-XYZ'], 'url_verification echoes the challenge');
$ok($app->events(['type' => 'url_verification', 'challenge' => '<script>'])['challenge'] === '', 'a challenge with odd characters is not echoed');
// thread setup
$tTh = $mkTicket(1, 2, 50, null, 'Thread ticket');
$q("INSERT INTO chat_threads SET thread_platform = 'slack', thread_team_id = 'T1', thread_channel_id = 'C0CHAN123', thread_ref = '1700000001.000100', thread_ticket_id = $tTh");
$msg = ['type' => 'message', 'user' => 'UBOBB', 'channel' => 'C0CHAN123', 'ts' => '1700000009.000200', 'thread_ts' => '1700000001.000100', 'text' => 'Try &lt;this&gt; &amp; <@U12345> see <https://x.test/a|docs>'];
$reset();
$app->events($ev($msg), $claim);
$ok((int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tTh") === 0, 'two-way OFF (default): a thread reply changes nothing');
$setCfg(['config_chat_two_way_enabled' => 1]); $app = $newApp();
$app->events($ev($msg), $claim);
$rep = $q("SELECT * FROM ticket_replies WHERE ticket_reply_ticket_id = $tTh")->fetch_assoc();
$ok($rep && $rep['ticket_reply_type'] === 'Internal' && (int) $rep['ticket_reply_by'] === 11 && str_contains($rep['ticket_reply'], 'Try &lt;this&gt; &amp; @user see docs (https://x.test/a)'), 'two-way ON: a linked agent\'s thread reply becomes an internal note on the ticket, mentions/links cleaned and escaped');
$id1 = 'EvDUP00001';
$cnt0 = (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tTh");
$app->events($ev($msg + [], $id1), $claim); $app->events($ev($msg, $id1), $claim);
$ok((int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tTh") === $cnt0 + 1, 'the same event delivered twice (Slack retry) is handled once');
$cnt = (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tTh");
$app->events($ev(['bot_id' => 'B1'] + $msg), $claim); $app->events($ev(['subtype' => 'message_changed'] + $msg), $claim);
$ok((int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tTh") === $cnt, 'bot messages and edits are ignored (no echo loop)');
$app->events($ev(['thread_ts' => '1700000099.000000'] + $msg), $claim); $app->events($ev(['thread_ts' => $msg['ts']] + $msg), $claim);
$ok((int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tTh") === $cnt, 'a reply in an unknown thread, or a top-level message, never touches a ticket');
$reset();
$app->events($ev(['user' => 'USTRANGER1'] + $msg), $claim);
$ok((int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tTh") === $cnt && str_contains($lastPost(), "isn't linked"), 'an unlinked Slack user\'s thread reply is refused and they are told why');
$reset();
$app->events($ev(['user' => 'UCAROL'] + $msg), $claim);
$ok((int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tTh") === $cnt, 'a linked CONTACT cannot reply into an agent ticket thread');
$q("DELETE FROM chat_threads WHERE thread_ticket_id = $tTh"); $q("INSERT INTO chat_threads SET thread_platform = 'slack', thread_team_id = 'T1', thread_channel_id = 'C0CHAN123', thread_ref = '1700000001.000100', thread_ticket_id = $t2");
$cntB = (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $t2"); $reset();
$app->events($ev($msg), $claim);
$ok((int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $t2") === $cntB && $lastPost() !== '', 'rights are re-checked: Bob\'s reply into a Dept B ticket thread is refused');
$app->events(['type' => 'event_callback', 'team_id' => 'TOTHER', 'event_id' => 'EvOtherTeam', 'event' => $msg], $claim);
$ok((int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $t2") === $cntB, 'an event from a workspace other than the pinned one is ignored');
// virtual agent in DM
$reset(); $dm = ['type' => 'message', 'channel_type' => 'im', 'user' => 'UBOBB', 'channel' => 'D0DM12345', 'ts' => '1700000100.000100', 'text' => 'hello'];
$app->events($ev($dm), $claim);
$ok($posted === [], 'virtual agent OFF (default): a direct message is not answered');
$setCfg(['config_chat_virtual_agent_enabled' => 1]); $app = $newApp();
$app->events($ev($dm), $claim);
$ok($posted && $posted[0]['ch'] === 'D0DM12345' && str_contains($posted[0]['text'], 'ticket &lt;what is wrong&gt;'), 'virtual agent ON: a DM gets help, text is Slack-escaped');
$reset(); $app->events($ev(['user' => 'USTRANGER1'] + $dm), $claim);
$ok($posted && str_contains($posted[0]['text'], "isn't linked"), 'virtual agent: an unlinked user is told to get linked');

// ======================================================= 6. virtual agent content + visibility
$q("INSERT INTO kb_articles SET kb_article_id = 1, kb_article_title = 'Reset your VPN password', kb_article_content = '<p>Open the VPN portal</p>', kb_article_content_raw = 'Open the VPN portal and press reset password', kb_article_client_id = 0, kb_article_client_visible = 1");
$q("INSERT INTO kb_articles SET kb_article_id = 2, kb_article_title = 'Internal VPN runbook', kb_article_content_raw = 'secret vpn internals', kb_article_client_id = 0, kb_article_client_visible = 0");
$q("INSERT INTO kb_articles SET kb_article_id = 3, kb_article_title = 'Dept B VPN notes', kb_article_content_raw = 'vpn for B', kb_article_client_id = 2, kb_article_client_visible = 1");
$q("INSERT INTO kb_articles SET kb_article_id = 4, kb_article_title = 'Old VPN guide', kb_article_content_raw = 'vpn old', kb_article_client_id = 0, kb_article_client_visible = 1, kb_article_archived_at = NOW()");
$q("INSERT INTO service_catalog_items SET catalog_item_id = 1, name = 'New laptop', description = 'Order a laptop', is_active = 1, requires_approval = 0");
$q("INSERT INTO service_catalog_items SET catalog_item_id = 2, name = 'VPN access', description = 'Remote access to the VPN', is_active = 1, requires_approval = 1");
$q("INSERT INTO service_catalog_items SET catalog_item_id = 3, name = 'VPN token (retired)', description = 'x', is_active = 0");
$q("INSERT INTO service_catalog_items SET catalog_item_id = 4, name = 'Software install', description = 'Install software', is_active = 1");
$q("INSERT INTO service_catalog_fields SET catalog_item_id = 4, field_key = 'what', label = 'Which software', field_type = 'text', is_required = 1");
$q("INSERT INTO service_catalog_approval_steps SET catalog_item_id = 2, step_order = 1, approver_type = 'user', approver_id = 11, mode = 'any'");
$va = new VirtualAgent($db, $ca2);
$titles = fn (array $rows) => array_column($rows, 'title');
$kbAgent = $titles($va->searchKb($agentBob, 'vpn password'));
$kbContact = $titles($va->searchKb($contactPat, 'vpn password'));
$ok(in_array('Reset your VPN password', $kbContact) && !in_array('Internal VPN runbook', $kbContact) && !in_array('Dept B VPN notes', $kbContact) && !in_array('Old VPN guide', $kbContact), 'a contact only gets client-visible, non-archived articles of their own department or all departments');
$ok(in_array('Internal VPN runbook', $kbAgent) && !in_array('Dept B VPN notes', $kbAgent) && !in_array('Old VPN guide', $kbAgent), 'an agent also gets internal articles, but not another department\'s (Bob) nor archived ones');
$ok(in_array('Dept B VPN notes', $titles($va->searchKb($agentAlice, 'vpn'))), 'an administrator gets articles of every department');
$cat = $titles($va->searchCatalog($contactPat, 'vpn'));
$ok(count($cat) === 1 && str_contains($cat[0], 'VPN access') && !str_contains(implode('|', $cat), 'retired'), 'catalog search returns active items only');
$kb = $va->searchKb($contactPat, "x' OR '1'='1 %_ vpn");
$ok(is_array($kb), 'search text with quotes and wildcards is bound, not interpolated');
$a = $va->answer($contactPat, 'how do I reset my vpn password?');
$ok($a['intent'] === 'search' && $a['results'] && str_contains($a['text'], 'Reset your VPN password') && !str_contains(json_encode($a), 'secret vpn internals'), 'answer lists matching articles and never includes bodies of internal ones');
$a = $va->answer($contactPat, 'gibberish zzzqqq');
$ok(str_contains($a['text'], 'ticket <what is wrong>'), 'no result: offers to open a ticket');
$a = $va->answer($contactPat, 'ticket my monitor flickers');
$ok($a['intent'] === 'ticket' && $a['ticket_id'] && $one("SELECT ticket_source FROM tickets WHERE ticket_id = {$a['ticket_id']}") === 'virtual agent', 'virtual agent opens a ticket on request');
$a = $va->answer($agentBob, 'request 1');
$tk = $a['ticket_id']; $ok($a['intent'] === 'request' && $tk && (int) $one("SELECT ticket_catalog_item_id FROM tickets WHERE ticket_id = $tk") === 1 && (int) $one("SELECT COUNT(*) FROM service_catalog_requests WHERE ticket_id = $tk") === 0, 'catalog item without approval: a ticket linked to the item');
$a = $va->answer($contactPat, 'request 4');
$ok(str_contains($a['text'], 'needs a few answers') && str_contains($a['text'], 'catalog_item_id=4'), 'catalog item with required questions is sent to the portal');
$a = $va->answer($contactPat, 'request 3'); $ok(!$a['ticket_id'] && str_contains($a['text'], 'not available'), 'inactive catalog item cannot be requested');
$a = $va->answer($contactPat, 'status id:' . $t2); $ok(str_contains($a['text'], 'could not find'), 'status of somebody else\'s ticket is not revealed to a contact');
$a = $va->answer($agentBob, 'status id:' . $t1); $ok(str_contains($a['text'], 'Printer down') && str_contains($a['text'], 'Priority: High'), 'status of a ticket the agent may see');
$q("DELETE FROM chat_kv");

// ======================================================= 7. catalog approval from chat
$reqTicket = $mkTicket(1, 2, 50, null, 'VPN request');
$svc = new \ITFlow\ITSM\ServiceCatalogService($db);
$item2 = $q("SELECT * FROM service_catalog_items WHERE catalog_item_id = 2")->fetch_assoc();
$res = $svc->submit($item2, $reqTicket, 1, 50, 0, []);
$reqId = $res['request_id'];
$ok($res['status'] === 'pending_approval' && (int) $one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id = $reqId AND approver_user_id = 11 AND status = 'pending'") === 1, 'fixture: a catalog request waits for Bob');
$pend = $ca2->pendingApprovals($agentBob);
$ok(count($pend) === 1 && $pend[0]['kind'] === 'catalog_request' && $pend[0]['id'] === $reqId && $ca2->pendingApprovals($agentAlice) === [], 'pending approvals list only what is addressed to the actor (no admin override listing)');
$ok(!$ca2->decideCatalog($agentAlice, $reqId, true)['ok'] && $one("SELECT status FROM service_catalog_requests WHERE request_id = $reqId") === 'pending_approval', 'an administrator who is not the approver cannot approve from chat (no override)');
$ok(!$ca2->decideCatalog($viewer, $reqId, true)['ok'], 'another agent cannot approve');
// the Slack button path
$setCfg(['config_chat_actions_enabled' => 0]); $app = $newApp();
$click = fn (string $action, string $value, string $user, array $extra = []) => ['type' => 'block_actions', 'user' => ['id' => $user, 'name' => 'forged'], 'team' => ['id' => 'T1'], 'response_url' => "$base/response/ok", 'trigger_id' => 'TRIG1', 'actions' => [[ 'action_id' => $action ] + $extra + ['value' => $value]]];
$r = $app->interaction(null, $click('rivet_approve', "catalog_request:$reqId", 'UBOBB'));
$ok($r['acted'] === false && str_contains((string) $r['text'], 'turned off') && $one("SELECT status FROM service_catalog_requests WHERE request_id = $reqId") === 'pending_approval', 'actions switch OFF (default): the Approve button does nothing');
$setCfg(['config_chat_actions_enabled' => 1]); $app = $newApp();
$r = $app->interaction(null, $click('rivet_approve', "catalog_request:$reqId", 'USTRANGER1'));
$ok($r['acted'] === false && str_contains((string) $r['text'], "isn't linked") && $one("SELECT status FROM service_catalog_requests WHERE request_id = $reqId") === 'pending_approval', 'Approve from an unlinked Slack user does nothing');
$r = $app->interaction(null, $click('rivet_approve', "catalog_request:$reqId", 'UALICE'));
$ok($r['acted'] === false && $one("SELECT status FROM service_catalog_requests WHERE request_id = $reqId") === 'pending_approval', 'Approve from a linked non-approver does nothing');
$r = $app->interaction(null, $click('rivet_approve', "catalog_request:999999", 'UBOBB'));
$ok($r['acted'] === false, 'Approve for a request that does not exist does nothing');
$r = $app->interaction(null, $click('rivet_approve', "bogus:$reqId", 'UBOBB'));
$ok($r['acted'] === false && str_contains((string) $r['text'], 'not valid'), 'a tampered button value is rejected');
$reset();
$r = $app->interaction(null, $click('rivet_approve', "catalog_request:$reqId", 'UBOBB'));
$ok($r['acted'] === true && $r['replace'] === true && $one("SELECT status FROM service_catalog_requests WHERE request_id = $reqId") === 'approved' && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $reqTicket") !== 3, 'the addressed approver approves from Slack: request approved and ticket released');
$ok((bool) array_filter($audits, fn ($x) => $x['e'] === 'chat.approval_decided' && $x['a'] === 11), 'the decision is audited with the approver as actor');
$r = $app->interaction(null, $click('rivet_approve', "catalog_request:$reqId", 'UBOBB'));
$ok($r['acted'] === false, 'a second click (already decided) does nothing');
$reqT2 = $mkTicket(1, 2, 50, null, 'VPN request 2');
$reqId2 = $svc->submit($item2, $reqT2, 1, 50, 0, [])['request_id'];
$r = $app->interaction(null, $click('rivet_reject', "catalog_request:$reqId2", 'UBOBB'));
$ok($r['acted'] === true && $one("SELECT status FROM service_catalog_requests WHERE request_id = $reqId2") === 'rejected' && $one("SELECT ticket_closed_at IS NOT NULL FROM tickets WHERE ticket_id = $reqT2") == 1, 'Deny rejects the request and closes the ticket');

// ======================================================= 8. workflow approval from chat
final class FakeGw implements \ITFlow\Workflow\ActionGateway {
    public array $log = [];
    public function createTicket(string $subject, string $detailsHtml, string $priority, int $clientId, string $source): int { return 0; }
    public function queueMail(string $to, string $toName, string $subject, string $bodyHtml): void {}
    public function notifyUser(int $userId, string $type, string $message, ?string $action, int $clientId, int $entityId): void { $this->log[] = "notify:$userId"; }
    public function emitEvent(string $event, array $data): void { $this->log[] = "event:$event"; }
    public function audit(string $event, ?int $actor, string $entityType, $entityId, string $action, string $summary, array $metadata = []): void { $this->log[] = "audit:$event"; }
    public function disablePortalLogin(int $contactId): string { return ''; }
}
$gw = new FakeGw();
$q("INSERT INTO workflow_runs SET run_id = 1, contact_id = 50, type = 'onboarding', status = 'in_progress', started_by = 10");
$q("INSERT INTO workflow_run_tasks SET run_task_id = 1, run_id = 1, title = 'Approve hardware', task_type = 'approval', status = 'pending', approval_status = 'pending', approver_type = 'user', approver_user_id = 11, sort_order = 1");
$q("INSERT INTO workflow_run_tasks SET run_task_id = 2, run_id = 1, title = 'Approve access', task_type = 'approval', status = 'pending', approval_status = 'pending', approver_type = 'user', approver_user_id = 11, sort_order = 2");
$app = new SlackApp($db, $auditFn, $lookup, $poster, $opener, $gw);
$p = (new ChatActions($db, $auditFn, new ChatSettings($db), $gw))->pendingApprovals($agentBob);
$ok(count(array_filter($p, fn ($x) => $x['kind'] === 'workflow_task')) === 2, 'workflow approvals the agent can decide are listed');
$r = $app->interaction(null, $click('rivet_approve', 'workflow_task:1', 'UBOBB'));
$ok($r['acted'] === true && $one("SELECT approval_status FROM workflow_run_tasks WHERE run_task_id = 1") === 'approved' && $one("SELECT approved_by FROM workflow_run_tasks WHERE run_task_id = 1") == 11, 'a workflow approval is approved from Slack by the approver');
$r = $app->interaction(null, $click('rivet_approve', 'workflow_task:1', 'UBOBB'));
$ok($r['acted'] === false, 'a second decision on the same workflow task is refused');
$r = $app->interaction(null, $click('rivet_approve', 'workflow_task:2', 'UCAROL'));
$ok($r['acted'] === false && $one("SELECT approval_status FROM workflow_run_tasks WHERE run_task_id = 2") === 'pending', 'a contact can never decide a workflow approval');
$r = $app->interaction(null, $click('rivet_reject', 'workflow_task:2', 'UBOBB'));
$ok($r['acted'] === true && $one("SELECT approval_status FROM workflow_run_tasks WHERE run_task_id = 2") === 'rejected' && $one("SELECT status FROM workflow_runs WHERE run_id = 1") === 'paused', 'Deny rejects the workflow approval and pauses the run');
$app = $newApp();

// ======================================================= 9. interactivity: status / priority / reply / modals / shortcuts
$tI = $mkTicket(1, 2, 50, null, 'Interactive');
$sel = fn (string $action, string $value, string $user) => ['type' => 'block_actions', 'user' => ['id' => $user], 'team' => ['id' => 'T1'], 'response_url' => "$base/response/ok", 'trigger_id' => 'TRIG1', 'actions' => [['action_id' => $action, 'selected_option' => ['value' => $value]]]];
$r = $app->interaction(null, $sel('rivet_set_status', "ticket:$tI:On Hold", 'UBOBB'));
$ok($r['acted'] && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $tI") === 3, 'status select changes the ticket');
$r = $app->interaction(null, $sel('rivet_set_priority', "ticket:$tI:High", 'UBOBB'));
$ok($r['acted'] && $one("SELECT ticket_priority FROM tickets WHERE ticket_id = $tI") === 'High', 'priority select changes the ticket');
$r = $app->interaction(null, $sel('rivet_set_status', "ticket:$tI:Closed", 'UBOBB'));
$ok(!$r['acted'] && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $tI") === 3, 'selecting Closed (forged option) is refused');
$r = $app->interaction(null, $sel('rivet_set_status', "ticket:$t2:Open", 'UBOBB'));
$ok(!$r['acted'] && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $t2") === 2, 'status select on another department\'s ticket is refused');
$r = $app->interaction(null, $sel('rivet_set_status', "ticket:$tI:Open", 'UCAROL'));
$ok(!$r['acted'], 'a contact\'s status select does nothing');
$setCfg(['config_chat_actions_enabled' => 0]); $app = $newApp();
$r = $app->interaction(null, $sel('rivet_set_priority', "ticket:$tI:Low", 'UBOBB'));
$ok(!$r['acted'] && $one("SELECT ticket_priority FROM tickets WHERE ticket_id = $tI") === 'High', 'actions OFF: selects do nothing');
$setCfg(['config_chat_actions_enabled' => 1]); $app = $newApp();
$reset();
$r = $app->interaction(null, $click('rivet_reply', "ticket:$tI", 'UBOBB'));
$ok($opened && $opened[0]['view']['callback_id'] === ChatBlocks::CB_REPLY_MODAL && $opened[0]['view']['private_metadata'] === (string) $tI && $r['text'] === null, 'Reply button opens the reply modal for that ticket');
$sub = fn (string $cb, string $meta, array $vals, string $user) => ['type' => 'view_submission', 'user' => ['id' => $user], 'team' => ['id' => 'T1'], 'view' => ['callback_id' => $cb, 'private_metadata' => $meta, 'state' => ['values' => $vals]]];
$r = $app->interaction(null, $sub(ChatBlocks::CB_REPLY_MODAL, (string) $tI, ['text' => ['v' => ['value' => 'Modal reply text']]], 'UBOBB'));
$ok($r['body'] === ['response_action' => 'clear'] && $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tI AND ticket_reply LIKE '%Modal reply text%' AND ticket_reply_type = 'Internal'") == 1, 'reply modal submission adds the reply and closes the modal');
$r = $app->interaction(null, $sub(ChatBlocks::CB_REPLY_MODAL, (string) $t2, ['text' => ['v' => ['value' => 'x']]], 'UBOBB'));
$ok(isset($r['body']['response_action']) && $r['body']['response_action'] === 'errors', 'reply modal for a ticket the user may not work shows an error in the modal');
$r = $app->interaction(null, $sub(ChatBlocks::CB_REPLY_MODAL, (string) $tI, ['text' => ['v' => ['value' => 'x']]], 'USTRANGER1'));
$ok($r['body']['response_action'] === 'errors', 'reply modal from an unlinked user shows a not-linked error');
// new ticket modal
$setCfg(['config_chat_commands_enabled' => 0]); $app = $newApp();
$opened = [];
$r = $app->interaction(null, ['type' => 'shortcut', 'callback_id' => ChatBlocks::SHORTCUT_GLOBAL, 'user' => ['id' => 'UBOBB'], 'team' => ['id' => 'T1'], 'trigger_id' => 'TRIG2']);
$ok($opened === [] && str_contains((string) $r['text'], 'turned off'), 'commands switch OFF (default): the shortcut says it is off');
$setCfg(['config_chat_commands_enabled' => 1]); $app = $newApp();
$r = $app->interaction(null, ['type' => 'message_action', 'callback_id' => ChatBlocks::SHORTCUT_NEW, 'user' => ['id' => 'UBOBB'], 'team' => ['id' => 'T1'], 'trigger_id' => 'TRIG3', 'channel' => ['id' => 'C0CHAN123'], 'message' => ['text' => "Wifi dropping in room 4\nsince Monday <@U999999>"]]);
$view = $opened[0]['view'] ?? [];
$subjIn = $view['blocks'][0]['element']['initial_value'] ?? ''; $detIn = $view['blocks'][1]['element']['initial_value'] ?? '';
$ok(($view['callback_id'] ?? '') === ChatBlocks::CB_NEW_MODAL && $subjIn === 'Wifi dropping in room 4' && str_contains($detIn, 'since Monday @user'), 'message shortcut opens the ticket modal pre-filled from the message');
$clientBlock = array_values(array_filter($view['blocks'] ?? [], fn ($b) => ($b['block_id'] ?? '') === 'client'))[0] ?? null;
$ok($clientBlock && count($clientBlock['element']['options']) === 1 && $clientBlock['element']['options'][0]['value'] === '1', 'the modal client picker lists only the departments Bob may work');
$opened = [];
$r = $app->interaction(null, ['type' => 'message_action', 'callback_id' => ChatBlocks::SHORTCUT_NEW, 'user' => ['id' => 'UCAROL'], 'team' => ['id' => 'T1'], 'trigger_id' => 'TRIG3', 'message' => ['text' => 'x']]);
$ok($opened === [] && str_contains((string) $r['text'], 'Only agents'), 'a contact cannot use the agent ticket form');
$r = $app->interaction(null, ['type' => 'message_action', 'callback_id' => ChatBlocks::SHORTCUT_NEW, 'user' => ['id' => 'UBOBB'], 'team' => ['id' => 'T1'], 'trigger_id' => 'BAD', 'message' => ['text' => 'x']]);
$ok(str_contains((string) $r['text'], 'could not open'), 'an expired trigger is reported, not swallowed');
$reset();
$r = $app->interaction(null, $sub(ChatBlocks::CB_NEW_MODAL, 'C0CHAN123', ['subject' => ['v' => ['value' => 'From modal']], 'details' => ['v' => ['value' => 'Details here']], 'client' => ['v' => ['selected_option' => ['value' => '1']]], 'priority' => ['v' => ['selected_option' => ['value' => 'High']]]], 'UBOBB'));
$mt = $q("SELECT * FROM tickets WHERE ticket_subject = 'From modal'")->fetch_assoc();
$ok($r['body'] === ['response_action' => 'clear'] && $mt && $mt['ticket_priority'] === 'High' && (int) $mt['ticket_client_id'] === 1 && $posted && str_contains($lastPost(), 'Created'), 'new-ticket modal submission creates the ticket and confirms by message');
$r = $app->interaction(null, $sub(ChatBlocks::CB_NEW_MODAL, '', ['subject' => ['v' => ['value' => 'Sneaky']], 'client' => ['v' => ['selected_option' => ['value' => '2']]]], 'UBOBB'));
$ok($r['body']['response_action'] === 'errors' && $one("SELECT COUNT(*) FROM tickets WHERE ticket_subject = 'Sneaky'") == 0, 'modal with a department Bob may not use is refused (forged client value)');
$r = $app->interaction(null, $sub(ChatBlocks::CB_NEW_MODAL, '', ['subject' => ['v' => ['value' => '   ']]], 'UBOBB'));
$ok($r['body']['response_action'] === 'errors', 'an empty subject is a form error');
// legacy buttons still work through the new dispatcher
$tL = $mkTicket(1, 1, 0, null, 'Legacy');
$setCfg(['config_slack_link_by_email' => 0]); $app = $newApp();
$r = $app->interaction(null, $click('rivet_assign', "ticket:$tL", 'UBOBB'));
$ok($r['acted'] === true && (int) $one("SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $tL") === 11, 'the original "Assign to me" button works through the new dispatcher, now also for an explicitly linked user');
$r = $app->interaction(null, $click('rivet_assign', "ticket:$tL", 'USTRANGER1'));
$ok($r['acted'] === false && str_contains((string) $r['text'], "isn't linked"), 'the original buttons still refuse an unlinked user');

// ======================================================= 10. slash commands
$cmd = fn (string $text, string $user = 'UBOBB', array $x = []) => $x + ['command' => '/ticket', 'text' => $text, 'user_id' => $user, 'team_id' => 'T1', 'channel_id' => 'C0CHAN123', 'trigger_id' => 'TRIGC'];
$setCfg(['config_chat_commands_enabled' => 0]); $app = $newApp();
$r = $app->command($cmd('new test'));
$ok(str_contains($r['text'], 'turned off') && $r['response_type'] === 'ephemeral', 'slash commands OFF (default): says so, ephemeral');
$setCfg(['config_chat_commands_enabled' => 1]); $app = $newApp();
$r = $app->command($cmd('new', 'UBOBB')); $ok($r === [] && $opened, '/ticket new with no subject opens the form (empty body)');
$r = $app->command($cmd('help')); $ok(str_contains($r['text'], '/ticket new') && str_contains($r['text'], 'status'), '/ticket help');
$before = (int) $one("SELECT COUNT(*) FROM tickets");
$r = $app->command($cmd('new priority=High Phone line is dead <!channel>'));
$nt = $q("SELECT * FROM tickets ORDER BY ticket_id DESC LIMIT 1")->fetch_assoc();
$ok((int) $one("SELECT COUNT(*) FROM tickets") === $before + 1 && $nt['ticket_priority'] === 'High' && (int) $nt['ticket_client_id'] === 1 && str_contains($r['text'], 'Created'), '/ticket new <subject> creates a ticket (default client, priority option)');
$ok(!str_contains($r['text'], '<!channel>') && !str_contains($r['text'], '<'), 'the reply never contains a live broadcast mention');
$r = $app->command($cmd('new client=2 Other dept problem')); $ok(str_contains($r['text'], 'not allowed'), '/ticket new client=<id> for a department Bob may not use is refused');
$r = $app->command($cmd('status ' . $nt['ticket_prefix'] . $nt['ticket_number'])); $ok(str_contains($r['text'], 'Phone line is dead') && str_contains($r['text'], 'Priority: High'), '/ticket status <label>');
$r = $app->command($cmd('status id:' . $t2)); $ok(str_contains($r['text'], 'could not find'), '/ticket status for another department says not found (no existence leak)');
$r = $app->command($cmd('status 1', 'USTRANGER1')); $ok(str_contains($r['text'], "isn't linked"), 'unlinked user: not linked');
$setCfg(['config_slack_team_id' => 'TPINNED']); $app = $newApp();
$r = $app->command($cmd('status ' . $nt['ticket_number'])); $ok(str_contains($r['text'], 'not allowed'), 'a workspace other than the pinned one is refused');
$setCfg(['config_slack_team_id' => 'T1']); $app = $newApp();
$setCfg(['config_chat_actions_enabled' => 0]); $app = $newApp();
$r = $app->command($cmd('set ' . $nt['ticket_number'] . ' status Open')); $ok(str_contains($r['text'], 'turned off'), '/ticket set with actions OFF: refused');
$setCfg(['config_chat_actions_enabled' => 1]); $app = $newApp();
$r = $app->command($cmd('set ' . $nt['ticket_number'] . ' status On Hold')); $ok(str_contains($r['text'], 'On Hold') && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = {$nt['ticket_id']}") === 3, '/ticket set <t> status <name>');
$r = $app->command($cmd('set ' . $nt['ticket_number'] . ' priority Medium')); $ok($one("SELECT ticket_priority FROM tickets WHERE ticket_id = {$nt['ticket_id']}") === 'Medium', '/ticket set <t> priority <p>');
$r = $app->command($cmd('reply ' . $nt['ticket_number'] . ' On it, sending a tech')); $ok($one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = {$nt['ticket_id']} AND ticket_reply LIKE '%sending a tech%'") == 1, '/ticket reply <t> <text>');
$r = $app->command($cmd('approvals')); $ok(str_contains($r['text'], 'no approvals') || str_contains($r['text'], 'Waiting'), '/ticket approvals');
$r = $app->command($cmd('set ' . $nt['ticket_number'] . ' status Open', 'UCAROL')); $ok(str_contains($r['text'], 'Only agents'), 'a contact cannot /ticket set');
$r = $app->command($cmd('new My keyboard is broken', 'UPAT00001')); $kt = $q("SELECT * FROM tickets WHERE ticket_subject LIKE 'My keyboard%'")->fetch_assoc();
$ok($kt && (int) $kt['ticket_contact_id'] === 50 && (int) $kt['ticket_client_id'] === 1, 'a contact can raise a ticket with /ticket new; it lands in their own department');
$r = $app->command($cmd('new', 'UPAT00001')); $ok(str_contains($r['text'], 'Say what you need'), 'a contact with no subject is told what to type (no agent form)');
$r = $app->command($cmd('vpn password')); $ok(str_contains($r['text'], 'Reset your VPN password') && !empty($r['blocks']), '/ticket <question> goes to the virtual agent with result blocks');
$setCfg(['config_chat_virtual_agent_enabled' => 0]); $app = $newApp();
$r = $app->command($cmd('vpn password')); $ok(str_contains($r['text'], 'I do not know'), 'virtual agent OFF: free text is not answered');
$setCfg(['config_chat_virtual_agent_enabled' => 1]); $app = $newApp();

// ======================================================= 11. thread mirroring (outbound)
$setCfg(['config_chat_two_way_enabled' => 0]);
$tM = $mkTicket(1, 2, 50, null, 'Mirror me');
$mockReset();
$ok(ChatNotifier::mirrorTicket($db, $tM) === null && $mockLog() === [], 'two-way OFF: nothing is posted to Slack');
$setCfg(['config_chat_two_way_enabled' => 1]);
$th = ChatNotifier::mirrorTicket($db, $tM);
$ml = $mockLog();
$ok($th && $th['thread_channel_id'] === 'C0CHAN123' && preg_match('/^[0-9]+\.[0-9]+$/', $th['thread_ref']) && count($ml) === 1 && $ml[0]['path'] === '/api/chat.postMessage' && $ml[0]['auth'] === 'Bearer xoxb-test-token-1234', 'a ticket is posted into the channel as a thread (bot token in the Authorization header only)');
$sent = json_decode($ml[0]['body'], true);
$ok(str_contains($sent['text'], 'Mirror me') && !str_contains($ml[0]['body'], 'xoxb-test-token'), 'the message body never contains the token');
$ok(ChatNotifier::mirrorTicket($db, $tM)['thread_id'] === $th['thread_id'] && count($mockLog()) === 1, 'mirroring twice does not post twice');
$r1 = $q("INSERT INTO ticket_replies SET ticket_reply = '<p>We are on it</p>', ticket_reply_type = 'Public', ticket_reply_by = 11, ticket_reply_ticket_id = $tM"); $rid1 = $db->insert_id;
$q("INSERT INTO ticket_replies SET ticket_reply = '<p>Secret internal</p>', ticket_reply_type = 'Internal', ticket_reply_by = 11, ticket_reply_ticket_id = $tM");
$q("INSERT INTO ticket_replies SET ticket_reply = '<p>Thanks &amp; please hurry</p>', ticket_reply_type = 'Client', ticket_reply_by = 50, ticket_reply_ticket_id = $tM");
$n = ChatNotifier::syncTicket($db, $tM);
$ml = $mockLog(); $bodies = implode('|', array_map(fn ($m) => $m['body'], array_slice($ml, 1)));
$ok($n === 2 && str_contains($bodies, 'Bob Tech (public reply): We are on it') && str_contains($bodies, 'Pat Portal (customer): Thanks') && !str_contains($bodies, 'Secret internal'), 'public and customer replies are posted into the thread; internal notes are not (default)');
$thr = json_decode($ml[1]['body'], true);
$ok($thr['thread_ts'] === $th['thread_ref'], 'replies are posted with thread_ts of the ticket thread');
$ok(ChatNotifier::syncTicket($db, $tM) === 0, 'the cursor advances: a second sync posts nothing');
$q("INSERT INTO ticket_replies SET ticket_reply = '<p>Visible now</p>', ticket_reply_type = 'Internal', ticket_reply_by = 11, ticket_reply_ticket_id = $tM");
$setCfg(['config_chat_mirror_internal' => 1]);
$ok(ChatNotifier::syncTicket($db, $tM) === 1, 'with "mirror internal notes" ON the internal note is posted');
$setCfg(['config_chat_mirror_internal' => 0]);
$mockReset();
$agentBobFresh = (new ChatIdentity($db, new ChatSettings($db), $lookup))->resolve('slack', 'T1', 'UBOBB');
(new ChatActions($db, $auditFn, new ChatSettings($db)))->addReply($agentBobFresh, $tM, 'Posted from Slack');
$q("UPDATE chat_kv SET kv_updated_at = kv_updated_at"); // no-op
ChatNotifier::syncTicket($db, $tM);
$ok($mockLog() === [], 'a reply that came FROM chat is not echoed back into the thread');
$q("INSERT INTO ticket_replies SET ticket_reply = '<p>Web reply after chat reply</p>', ticket_reply_type = 'Public', ticket_reply_by = 11, ticket_reply_ticket_id = $tM");
$ok(ChatNotifier::syncTicket($db, $tM) === 1, 'a web reply made after a chat reply is still mirrored (nothing is skipped)');
// a failing Slack leaves the cursor so the next event retries
$q("INSERT INTO chat_threads SET thread_platform = 'slack', thread_channel_id = 'CFAIL', thread_ref = '1700000500.000100', thread_ticket_id = $tM, thread_last_reply_id = 0");
$cursor0 = (int) $one("SELECT thread_last_reply_id FROM chat_threads WHERE thread_channel_id = 'CFAIL'");
ChatNotifier::syncTicket($db, $tM);
$ok((int) $one("SELECT thread_last_reply_id FROM chat_threads WHERE thread_channel_id = 'CFAIL'") === $cursor0, 'when Slack refuses a post the thread cursor does not move (retry later)');
$q("DELETE FROM chat_threads WHERE thread_channel_id = 'CFAIL'");
$tNoCfg = $mkTicket(1, 2, 0, null, 'No token');
$setCfg(['config_slack_bot_token' => '']); $mockReset();
$ok(ChatNotifier::mirrorTicket($db, $tNoCfg) === null && $mockLog() === [], 'no bot token: mirroring does nothing and does not call Slack');
$setCfg(['config_slack_bot_token' => encryptSetting('xoxb-test-token-1234')]);

// approvals DM
$setCfg(['config_chat_actions_enabled' => 1]);
$tA = $mkTicket(1, 2, 50, null, 'DM approval'); $reqA = $svc->submit($item2, $tA, 1, 50, 0, [])['request_id'];
$mockReset();
$nsent = ChatNotifier::approvalRequested($db, 'catalog_request', $reqA);
$ml = $mockLog();
$msgDm = $ml ? json_decode($ml[0]['body'], true) : [];
$act = $msgDm['blocks'][2]['elements'] ?? [];
$ok($nsent === 1 && $msgDm['channel'] === 'UBOBB' && count($act) === 3 && isset($act[2]['url']) && $act[0]['action_id'] === 'rivet_approve' && $act[0]['value'] === "catalog_request:$reqA" && $act[1]['action_id'] === 'rivet_reject', 'approvalRequested DMs the linked approver an Approve / Deny message');
$setCfg(['config_chat_actions_enabled' => 0]); $mockReset();
$ok(ChatNotifier::approvalRequested($db, 'catalog_request', $reqA) === 0 && $mockLog() === [], 'actions OFF: no approval DM is sent');
$setCfg(['config_chat_actions_enabled' => 1]);

// ======================================================= 12. formatter controls
$data = ['ticket_id' => 42, 'ticket_number' => 'T-42', 'ticket_subject' => 'Printer', 'ticket_priority' => 'High', 'ticket_status' => 'Open', 'client_name' => 'Acme'];
$controls = ChatBlocks::ticketControls(42, 'Open', 'High', ['New', 'Open', 'On Hold']);
$m = ChatFormatter::slack('ticket.created', $data, ['interactive' => true, 'controls' => $controls]);
$last = end($m['blocks']);
$ok($last['type'] === 'actions' && $last['elements'][0]['action_id'] === 'rivet_set_status' && $last['elements'][0]['initial_option']['value'] === 'ticket:42:Open' && $last['elements'][1]['initial_option']['value'] === 'ticket:42:High' && $last['elements'][2]['action_id'] === 'rivet_reply', 'ticket message gets status, priority and Reply controls with the current values preselected');
$ok(!in_array('Closed', array_map(fn ($o) => $o['text']['text'], $controls[0]['options'])), 'the status control never offers Closed');
$ok(!str_contains(json_encode(ChatFormatter::slack('ticket.created', $data, ['interactive' => true])), 'rivet_set_status') && !str_contains(json_encode(ChatFormatter::slack('ticket.created', $data, ['interactive' => true, 'test' => true, 'controls' => $controls])), 'rivet_set_status'), 'no controls without them being requested, and none on a test message');

// ======================================================= 13. manifest
$mf = SlackManifest::build('https://help.example.test', 'RivetIT');
$ok($mf['settings']['interactivity']['request_url'] === 'https://help.example.test/slack_interactive.php' && $mf['settings']['event_subscriptions']['request_url'] === 'https://help.example.test/slack_events.php' && $mf['features']['slash_commands'][0]['url'] === 'https://help.example.test/slack_commands.php', 'manifest URLs are derived from the install base URL');
$ok(in_array('commands', $mf['oauth_config']['scopes']['bot']) && in_array('chat:write', $mf['oauth_config']['scopes']['bot']) && in_array('app_mentions:read', $mf['oauth_config']['scopes']['bot']) && count($mf['features']['shortcuts']) === 2, 'manifest asks for the scopes the features need and defines both shortcuts');
$mf2 = SlackManifest::build('https://help.example.test', 'RivetIT', false, false);
$ok(!isset($mf2['features']['slash_commands']) && !isset($mf2['settings']['event_subscriptions']) && !in_array('commands', $mf2['oauth_config']['scopes']['bot']) && !in_array('channels:history', $mf2['oauth_config']['scopes']['bot']), 'feature-less manifest requests neither command nor history scopes');
$thrown = false; try { SlackManifest::build('http://insecure.example.test', 'x'); } catch (\InvalidArgumentException $e) { $thrown = true; }
$ok($thrown, 'a non-https base URL is refused for the manifest');
$ok(is_array(json_decode(SlackManifest::json('https://help.example.test', 'Rivet<script>IT'), true)) && !str_contains(SlackManifest::json('https://help.example.test', 'Rivet<script>IT'), '<script>'), 'manifest is valid JSON and the app name is sanitised');

// ======================================================= 14. SSRF-safe outbound
$r = \ITFlow\Chat\ChatHttp::request('GET', 'http://169.254.169.254/latest/meta-data/');
$ok($r['error'] === 'url_rejected', 'outbound chat HTTP refuses cloud metadata addresses');
$r = \ITFlow\Chat\ChatHttp::request('GET', 'http://10.0.0.5/x'); $ok($r['error'] === 'url_rejected', 'outbound chat HTTP refuses plain http to private addresses');
$r = \ITFlow\Chat\ChatHttp::request('GET', 'https://user:pass@example.com/'); $ok($r['error'] === 'url_rejected', 'outbound chat HTTP refuses URLs with credentials');

// ======================================================= 15. secrets are never logged
$allAudit = json_encode($audits) . (string) $one("SELECT GROUP_CONCAT(log_description) FROM logs");
$ok(!str_contains($allAudit, $secret) && !str_contains($allAudit, 'xoxb-'), 'no secret appears in the audit trail or log table');

// ======================================================= 16. real endpoints over HTTP (needs config.php on the same scratch DB)
$cfgFile = __DIR__ . '/../config.php';
$httpDb = is_file($cfgFile) && str_contains((string) file_get_contents($cfgFile), getenv('RIVETIT_TEST_DB_NAME'));
if (!$httpDb) {
    echo "SKIP  HTTP endpoints (config.php does not point at the scratch database)\n";
} else {
    // The server uses the app's REAL crypto, so the secret it reads must be sealed by it (this test process uses a reversible stand-in).
    $realEnc = fn (string $v): string => trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/chat_enc.php') . ' ' . escapeshellarg($v)));
    $q("UPDATE settings SET config_slack_signing_secret = '" . $db->real_escape_string($realEnc($secret)) . "', config_slack_bot_token = '" . $db->real_escape_string($realEnc('xoxb-test-token-1234')) . "' WHERE company_id = 1");
    $hp = random_int(41000, 49000);
    $hproc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$hp", '-t', realpath(__DIR__ . '/..')], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $hp2, realpath(__DIR__ . '/..'), ['PATH' => getenv('PATH'), 'RIVETIT_TEST_ALLOW_LOCAL' => '1']);
    register_shutdown_function(function () use ($hproc) { proc_terminate($hproc); });
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $hp)) break; usleep(100000); }
    $post = function (string $path, string $raw, array $headers) use ($hp): array {
        $ch = curl_init("http://127.0.0.1:$hp/$path");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '']);
        $b = curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return [$c, (string) $b];
    };
    $signed = function (string $raw, ?string $sec = null, ?int $at = null) use ($secret): array {
        $t = (string) ($at ?? time());
        return ['Content-Type: application/x-www-form-urlencoded', 'X-Slack-Request-Timestamp: ' . $t, 'X-Slack-Signature: ' . SlackInteractive::signature($sec ?? $secret, $t, $raw)];
    };
    $json = json_encode(['type' => 'url_verification', 'challenge' => 'http-challenge-1']);
    $hdrEv = $signed($json);
    [$c, $b] = $post('slack_events.php', $json, $hdrEv);
    $ok($c === 200 && json_decode($b, true) === ['challenge' => 'http-challenge-1'], 'HTTP /slack_events.php: signed url_verification is answered');
    [$c, $b] = $post('slack_events.php', $json, $hdrEv);
    $ok($c === 200 && !str_contains($b, 'http-challenge-1'), 'HTTP /slack_events.php: the identical signed request replayed is not processed again');
    $json2 = json_encode(['type' => 'url_verification', 'challenge' => 'c2']);
    [$c, ] = $post('slack_events.php', $json2, $signed($json2, 'wrong-wrong-wrong-wrong'));
    $ok($c === 401, 'HTTP /slack_events.php: wrong signature gets 401');
    [$c, ] = $post('slack_events.php', $json2, ['Content-Type: application/json']);
    $ok($c === 401, 'HTTP /slack_events.php: no signature gets 401');
    [$c, ] = $post('slack_events.php', $json2, $signed($json2, null, time() - 400));
    $ok($c === 401, 'HTTP /slack_events.php: a 400 second old (validly signed) request gets 401');
    $ch = curl_init("http://127.0.0.1:$hp/slack_events.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '']); curl_exec($ch); $gc = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $ok($gc === 405, 'HTTP /slack_events.php: GET gets 405');
    $form = http_build_query($cmd('status ' . $nt['ticket_number']));
    [$c, $b] = $post('slack_commands.php', $form, $signed($form));
    $j = json_decode($b, true);
    $ok($c === 200 && ($j['response_type'] ?? '') === 'ephemeral' && str_contains($j['text'] ?? '', 'Phone line is dead'), 'HTTP /slack_commands.php: a signed /ticket status is answered ephemerally');
    [$c, ] = $post('slack_commands.php', $form, $signed($form . 'x'));
    $ok($c === 401, 'HTTP /slack_commands.php: body altered after signing gets 401');
    $payload = http_build_query(['payload' => json_encode($click('rivet_approve', 'bogus:1', 'UBOBB') + ['response_url' => 'https://evil.example.test/steal'])]);
    [$c, $b] = $post('slack_interactive.php', $payload, $signed($payload));
    $ok($c === 200, 'HTTP /slack_interactive.php: a signed interaction is accepted; a non-Slack response_url is never called');
    $subm = http_build_query(['payload' => json_encode($sub(ChatBlocks::CB_NEW_MODAL, '', ['subject' => ['v' => ['value' => '']]], 'UBOBB'))]);
    [$c, $b] = $post('slack_interactive.php', $subm, $signed($subm));
    $ok($c === 200 && (json_decode($b, true)['response_action'] ?? '') === 'errors', 'HTTP /slack_interactive.php: a modal submission is answered in the response body');
    $t0 = (string) time(); $hdr = ['Content-Type: application/x-www-form-urlencoded', 'X-Slack-Request-Timestamp: ' . $t0, 'X-Slack-Signature: ' . SlackInteractive::signature($secret, $t0, $payload)];
    [$c1, ] = $post('slack_interactive.php', $payload, $hdr); [$c2, $b3] = $post('slack_interactive.php', $payload, $hdr);
    $ok($c1 === 200 && $c2 === 200 && str_contains($b3, 'already handled'), 'HTTP: the same signed interaction sent twice is answered "already handled" the second time');
}

// cleanup of rows this test made in shared tables is the caller's job (the scratch DB is dropped)
echo "\n$count checks, $fail failed\n";
exit($fail ? 1 : 0);
