<?php
/*
 * Teams interactive buttons (issue #36) and service catalog risk score display (issue #30).
 * Scratch database only (refuses unless the name contains "scratch"). Starts and stops its own php -S loopback server to drive the real
 * endpoint /teams_interactive.php; no Teams tenant, no outbound request. Never prints a token.
 *
 *   RIVETIT_TEST_PORT=8097 php tests/teams_interactive.php
 */
chdir(dirname(__DIR__));
$ROOT = getcwd();
require_once $ROOT . '/config.php';
if (!str_contains((string) $database, 'scratch')) {
    fwrite(STDERR, "Refusing: database is not a scratch database.\n");
    exit(1);
}
require_once $ROOT . '/vendor/autoload.php';
require_once $ROOT . '/functions.php';
require_once $ROOT . '/src/ITSM/ServiceCatalogService.php';

use ITFlow\ITSM\ServiceCatalogService;
use ITFlow\Webhooks\ChatFormatter;
use ITFlow\Webhooks\DestinationConfig;
use ITFlow\Webhooks\TeamsInteractive;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = $mysqli;
$db->set_charset('utf8mb4');
$db->query("SET sql_mode=''");

$PASS = 0;
$FAIL = [];
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($cond) {
        $PASS++;
        echo "  ok   $name\n";
    } else {
        $FAIL[] = $name;
        echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}
function section(string $s): void
{
    echo "\n== $s\n";
}
function q1(mysqli $db, string $sql)
{
    $r = $db->query($sql);
    $row = $r ? $r->fetch_row() : null;
    return $row ? $row[0] : null;
}
function post(string $url, array $headers, string $body): array
{
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $out = (string) curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$st, $out];
}

// ---------------------------------------------------------------- fixtures
foreach (['webhooks', 'tickets', 'users', 'user_roles', 'user_role_permissions', 'modules', 'user_client_permissions', 'ticket_replies', 'ticket_statuses', 'clients', 'slack_interactive_seen', 'service_catalog_items', 'service_catalog_requests', 'service_catalog_request_approvals'] as $t) {
    $db->query("DELETE FROM `$t`");
}
$db->query("INSERT INTO clients SET client_id = 1, client_name = 'Dept A', client_currency_code = 'USD', client_net_terms = 0");
$db->query("INSERT INTO clients SET client_id = 2, client_name = 'Dept B', client_currency_code = 'USD', client_net_terms = 0");
$db->query("INSERT INTO modules SET module_id = 1, module_name = 'module_support'");
$db->query("INSERT INTO user_roles SET role_id = 1, role_name = 'Admin', role_is_admin = 1");
$db->query("INSERT INTO user_roles SET role_id = 2, role_name = 'Tech', role_is_admin = 0");
$db->query("INSERT INTO user_roles SET role_id = 3, role_name = 'ReadOnly', role_is_admin = 0");
$db->query("INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 1, user_role_permission_level = 2");
$db->query("INSERT INTO user_role_permissions SET user_role_id = 3, module_id = 1, user_role_permission_level = 1");
function mkuser(mysqli $db, int $id, string $email, int $role, int $type = 1): void
{
    $db->query("INSERT INTO users SET user_id = $id, user_name = 'User $id', user_email = '$email', user_password = 'x', user_role_id = $role, user_type = $type, user_status = 1");
}
mkuser($db, 1, 'admin@example.test', 1);
mkuser($db, 2, 'tech@example.test', 2);
mkuser($db, 3, 'ro@example.test', 3);
mkuser($db, 4, 'dupe@example.test', 2);
mkuser($db, 5, 'dupe@example.test', 2);
mkuser($db, 6, 'portal@example.test', 2, 2); // a portal login, never an agent
mkuser($db, 7, 'scoped@example.test', 2);
$db->query("INSERT INTO user_client_permissions SET user_id = 7, client_id = 2");
$db->query("INSERT INTO ticket_statuses SET ticket_status_id = 1, ticket_status_name = 'New', ticket_status_active = 1");
$db->query("INSERT INTO ticket_statuses SET ticket_status_id = 2, ticket_status_name = 'Open', ticket_status_active = 1");
function mkticket(mysqli $db, int $id, int $client, ?string $closed = null): void
{
    $c = $closed ? "'$closed'" : 'NULL';
    $db->query("INSERT INTO tickets SET ticket_id = $id, ticket_prefix = 'T', ticket_number = $id, ticket_subject = 'Subject $id', ticket_details = 'd', ticket_status = 1, ticket_created_by = 1, ticket_client_id = $client, ticket_closed_at = $c");
}
mkticket($db, 10, 1);
mkticket($db, 11, 1);
mkticket($db, 12, 1, '2026-01-01 00:00:00');
mkticket($db, 13, 2);
mkticket($db, 14, 1);
mkticket($db, 15, 1);
mkticket($db, 16, 1);
mkticket($db, 17, 1);

$TOKEN = bin2hex(random_bytes(20));
$TOKEN2 = bin2hex(random_bytes(20));
function mkhook(mysqli $db, int $id, string $type, string $token, int $enabled = 1, string $clients = ''): void
{
    $enc = $token === '' ? '' : encryptSetting($token);
    $db->query("INSERT INTO webhooks SET webhook_id = $id, webhook_name = 'h$id', webhook_url = 'x', webhook_type = '$type', webhook_enabled = $enabled, webhook_secret = '" . $db->real_escape_string($enc) . "', webhook_client_ids = '$clients'");
}
mkhook($db, 1, 'teams', $TOKEN);
mkhook($db, 2, 'teams', '', 1);
mkhook($db, 3, 'teams', $TOKEN2, 0);
mkhook($db, 4, 'slack', $TOKEN2);
$hook = fn (int $id) => $db->query("SELECT * FROM webhooks WHERE webhook_id = $id")->fetch_assoc();

$audits = [];
$audit = static function (string $e, ?int $a, string $et, $id, string $act, string $sum, array $meta = []) use (&$audits): void {
    $audits[] = [$e, $a, $id];
};
$ti = new TeamsInteractive($db, $audit);
$now = time();

// ---------------------------------------------------------------- formatter
section('Teams card actions');
$data = ['ticket_id' => 10, 'ticket_number' => 'T10', 'ticket_subject' => 'Printer', 'ticket_priority' => 'High', 'client_name' => 'Dept A'];
$plain = ChatFormatter::teams('ticket.created', $data, ['ticket_url' => 'https://h.example/agent/ticket.php?ticket_id=10']);
$acts = $plain['attachments'][0]['content']['actions'];
ok(count($acts) === 1 && $acts[0]['type'] === 'Action.OpenUrl', 'without a token the card has only Open ticket');
$inter = ChatFormatter::teams('ticket.created', $data, ['ticket_url' => 'https://h.example/agent/ticket.php?ticket_id=10', 'interactive' => true]);
$acts = $inter['attachments'][0]['content']['actions'];
ok(count($acts) === 3 && $acts[1]['type'] === 'Action.Submit' && $acts[1]['data'] === ['rivet_action' => 'rivet_ack', 'value' => 'ticket:10'] && $acts[2]['data']['rivet_action'] === 'rivet_assign', 'interactive card has Acknowledge and Assign to me with ticket id only');
$json = json_encode($inter);
ok(!str_contains($json, $TOKEN), 'no token in the card');
$t = ChatFormatter::teams('ticket.created', $data, ['interactive' => true, 'test' => true]);
ok(count($t['attachments'][0]['content']['actions'] ?? []) === 0, 'test message never has action buttons');
$p = ChatFormatter::teams('user.login', ['summary' => 'x'], ['interactive' => true]);
ok(!isset($p['attachments'][0]['content']['actions']), 'platform events have no ticket buttons');

section('Destination form accepts a Teams token');
$known = static fn (string $e): bool => true;
$safe = static fn (string $u): bool => true;
$base = ['webhook_destination' => 'teams', 'webhook_name' => 'T', 'webhook_url' => 'https://prod-00.westus.logic.azure.com:443/workflows/abc123/triggers/manual/paths/invoke?api-version=2016-06-01&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=abcDEF123', 'webhook_events' => ['ticket.created'], 'webhook_enabled' => '1'];
$r = DestinationConfig::validateForm($base + ['webhook_secret' => $TOKEN], null, $known, $safe);
ok(($r['errors'] ?? ['x']) === [] && decryptSetting($r['row']['webhook_secret'] ?? '') === $TOKEN, 'a 40-char token is stored encrypted', json_encode($r['errors'] ?? null));
$r = DestinationConfig::validateForm($base + ['webhook_secret' => 'short'], null, $known, $safe);
ok($r['errors'] !== [], 'a short token is refused');
$r = DestinationConfig::validateForm($base, null, $known, $safe);
ok(($r['row']['webhook_secret'] ?? 'x') === '', 'no token = empty secret (no buttons)');
$ex = ['webhook_type' => 'teams', 'webhook_destination' => 'teams', 'webhook_secret' => encryptSetting($TOKEN)];
$r = DestinationConfig::validateForm($base, $ex, $known, $safe);
ok(!array_key_exists('webhook_secret', $r['row']), 'blank on edit keeps the saved token');
$r = DestinationConfig::validateForm($base + ['webhook_secret_clear' => '1'], $ex, $known, $safe);
ok(($r['row']['webhook_secret'] ?? 'x') === '', 'Remove token clears it');

// ---------------------------------------------------------------- authentication
section('Token and timestamp');
ok(TeamsInteractive::tokenMatches($TOKEN, $TOKEN), 'same token matches');
ok(!TeamsInteractive::tokenMatches($TOKEN, $TOKEN . 'x') && !TeamsInteractive::tokenMatches($TOKEN, substr($TOKEN, 0, 39)) && !TeamsInteractive::tokenMatches($TOKEN, ''), 'near misses and empty do not match');
ok(!TeamsInteractive::tokenMatches('short', 'short'), 'a too-short configured token never authenticates');
ok(TeamsInteractive::timestampFresh((string) $now, $now) && TeamsInteractive::timestampFresh((string) ($now - 299), $now) && TeamsInteractive::timestampFresh((string) ($now + 299), $now), 'fresh timestamps accepted');
ok(!TeamsInteractive::timestampFresh((string) ($now - 301), $now) && !TeamsInteractive::timestampFresh((string) ($now + 301), $now) && !TeamsInteractive::timestampFresh('', $now) && !TeamsInteractive::timestampFresh('abc', $now) && !TeamsInteractive::timestampFresh('-5', $now) && !TeamsInteractive::timestampFresh('1e9', $now), 'stale, future, blank, non-digit timestamps refused');
ok(($ti->destinationFor($TOKEN, (string) $now, $now)['webhook_id'] ?? 0) == 1, 'right token finds destination 1');
ok($ti->destinationFor($TOKEN . '0', (string) $now, $now) === null, 'wrong token: no destination');
ok($ti->destinationFor($TOKEN, (string) ($now - 400), $now) === null, 'right token, stale timestamp: no destination');
ok($ti->destinationFor($TOKEN, '', $now) === null && $ti->destinationFor('', (string) $now, $now) === null, 'missing token or timestamp: no destination');
ok($ti->destinationFor($TOKEN2, (string) $now, $now) === null, 'token of a disabled Teams destination and of a Slack destination does not authenticate');
ok($ti->claimOnce($TOKEN, (string) $now, 'body-a') === true && $ti->claimOnce($TOKEN, (string) $now, 'body-a') === false, 'a replay of the same request is refused');
ok($ti->claimOnce($TOKEN, (string) $now, 'body-b') === true, 'a different body is a different request');

// ---------------------------------------------------------------- handling
section('Ticket actions');
$h1 = $hook(1);
$click = static fn (string $action, $value, $email) => ['rivet_action' => $action, 'value' => $value, 'user_email' => $email];
$r = $ti->handle($h1, $click('rivet_ack', 'ticket:10', 'tech@example.test'));
ok($r['acted'] === true && str_contains((string) $r['text'], 'Acknowledged'), 'tech acknowledges');
ok((int) q1($db, "SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = 10 AND ticket_reply LIKE 'Acknowledged in Teams by User 2.' AND ticket_reply_type = 'Internal'") === 1, 'internal note written, attributed to the agent');
ok(($audits[0][0] ?? '') === 'ticket.teams_acknowledged' && ($audits[0][1] ?? 0) === 2, 'audit event ticket.teams_acknowledged with the agent as actor');
$r = $ti->handle($h1, $click('rivet_assign', 'ticket:11', 'TECH@Example.test'));
ok($r['acted'] === true && (int) q1($db, 'SELECT ticket_assigned_to FROM tickets WHERE ticket_id = 11') === 2 && (int) q1($db, 'SELECT ticket_status FROM tickets WHERE ticket_id = 11') === 2, 'assign sets the agent and New becomes Open (email case-insensitive)');
ok(($audits[1][0] ?? '') === 'ticket.teams_assigned', 'audit event ticket.teams_assigned');
$r = $ti->handle($h1, $click('rivet_assign', 'ticket:11', 'tech@example.test'));
ok($r['acted'] === false && str_contains((string) $r['text'], 'already assigned'), 'assigning twice changes nothing');
$r = $ti->handle($h1, $click('rivet_assign', 'ticket:14', 'admin@example.test'));
ok($r['acted'] === true && (int) q1($db, 'SELECT ticket_assigned_to FROM tickets WHERE ticket_id = 14') === 1, 'administrator may act');

section('Identity and rights refusals');
$before = (int) q1($db, 'SELECT COUNT(*) FROM ticket_replies');
foreach ([
    'unknown email' => 'nobody@example.test',
    'two agents share the address' => 'dupe@example.test',
    'portal login with an agent-looking address' => 'portal@example.test',
    'empty email' => '',
    'not an email' => 'tech',
    'array email' => ['tech@example.test'],
    'null email' => null,
] as $label => $em) {
    $r = $ti->handle($h1, ['rivet_action' => 'rivet_ack', 'value' => 'ticket:15', 'user_email' => $em]);
    ok($r['acted'] === false && str_contains((string) $r['text'], "isn't linked"), "refused: $label");
}
$r = $ti->handle($h1, $click('rivet_assign', 'ticket:15', 'ro@example.test'));
ok($r['acted'] === false && str_contains((string) $r['text'], 'role does not allow'), 'read-only role may not act');
$r = $ti->handle($h1, $click('rivet_assign', 'ticket:15', 'scoped@example.test'));
ok($r['acted'] === false && str_contains((string) $r['text'], 'role does not allow'), 'agent restricted to another department may not act');
$r = $ti->handle($h1, $click('rivet_ack', 'ticket:12', 'tech@example.test'));
ok($r['acted'] === false && str_contains((string) $r['text'], 'closed'), 'closed ticket untouched');
$r = $ti->handle($h1, $click('rivet_ack', 'ticket:999', 'tech@example.test'));
ok($r['acted'] === false && str_contains((string) $r['text'], 'no longer exists'), 'missing ticket');
foreach (['ticket:0', 'ticket:-1', 'ticket:1 OR 1=1', 'ticket:010x', '10', 'ticket:99999999999', ['a']] as $bad) {
    $r = $ti->handle($h1, $click('rivet_ack', $bad, 'tech@example.test'));
    ok($r['acted'] === false && $r['text'] === 'That button is not valid.', 'malformed value refused: ' . json_encode($bad));
}
$r = $ti->handle($h1, $click('rivet_close', 'ticket:15', 'tech@example.test'));
ok($r['acted'] === false && $r['text'] === null, 'unknown action ignored');
ok(((int) q1($db, 'SELECT COUNT(*) FROM ticket_replies')) === $before, 'no refusal wrote anything');
ok((int) q1($db, 'SELECT ticket_assigned_to FROM tickets WHERE ticket_id = 15') === 0, 'refused clicks changed no ticket');
mkhook($db, 5, 'teams', bin2hex(random_bytes(20)), 1, '2');
$r = $ti->handle($hook(5), $click('rivet_ack', 'ticket:16', 'tech@example.test'));
ok($r['acted'] === false && str_contains((string) $r['text'], 'not set up'), "destination's own client filter applies");
$db->query('DELETE FROM webhooks WHERE webhook_id = 5');

// ---------------------------------------------------------------- Slack regression (the shared action code was refactored)
section('Slack buttons still work after the refactor');
$db->query('DELETE FROM settings');
$db->query("INSERT INTO settings SET company_id = 1, config_slack_link_by_email = 1, config_slack_bot_token = '" . $db->real_escape_string(encryptSetting('xoxb-test')) . "', config_slack_team_id = 'T1'");
$slackAudits = [];
$si = new \ITFlow\Webhooks\SlackInteractive($db, static fn (string $tok, string $uid): array => ['id' => $uid, 'team_id' => 'T1', 'is_email_confirmed' => true, 'profile' => ['email' => 'tech@example.test']], static function (string $e) use (&$slackAudits): void { $slackAudits[] = $e; });
mkticket($db, 20, 1);
$sp = fn (string $act) => ['type' => 'block_actions', 'team' => ['id' => 'T1'], 'user' => ['id' => 'U12345'], 'actions' => [['action_id' => $act, 'value' => 'ticket:20']]];
$r = $si->handle($hook(4), $sp('rivet_assign'));
ok($r['acted'] === true && (int) q1($db, 'SELECT ticket_assigned_to FROM tickets WHERE ticket_id = 20') === 2 && ($slackAudits[0] ?? '') === 'ticket.slack_assigned', 'Slack assign still works with its own audit event');
$r = $si->handle($hook(4), $sp('rivet_ack'));
ok($r['acted'] === true && (int) q1($db, "SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = 20 AND ticket_reply = 'Acknowledged in Slack by User 2.'") === 1, 'Slack acknowledge still works');

// ---------------------------------------------------------------- real endpoint over loopback
section('Endpoint /teams_interactive.php over HTTP');
$PORT = getenv('RIVETIT_TEST_PORT') ?: '8097';
$BASE = "http://127.0.0.1:$PORT/teams_interactive.php";
$log = sys_get_temp_dir() . '/teams_int_' . getmypid() . '.log';
$proc = proc_open(['php', '-S', "127.0.0.1:$PORT", '-t', $ROOT], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']], $pipes, $ROOT);
register_shutdown_function(function () use ($proc, $log) {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
    @unlink($log);
});
for ($i = 0; $i < 40; $i++) {
    if (@fsockopen('127.0.0.1', (int) $PORT)) {
        break;
    }
    usleep(250000);
}
$ts = (string) time();
$body = json_encode($click('rivet_assign', 'ticket:16', 'tech@example.test'));
[$st, $out] = post($BASE, ['X-Rivet-Teams-Token' => $TOKEN, 'X-Rivet-Timestamp' => $ts, 'Content-Type' => 'application/json'], $body);
$j = json_decode($out, true);
ok($st === 200 && ($j['acted'] ?? null) === true && (int) q1($db, 'SELECT ticket_assigned_to FROM tickets WHERE ticket_id = 16') === 2, 'valid request acts', "status $st");
[$st, $out] = post($BASE, ['X-Rivet-Teams-Token' => $TOKEN, 'X-Rivet-Timestamp' => $ts], $body);
$j = json_decode($out, true);
ok($st === 200 && ($j['acted'] ?? null) === false && str_contains((string) ($j['text'] ?? ''), 'already handled'), 'identical replay is refused');
$body2 = json_encode($click('rivet_assign', 'ticket:17', 'tech@example.test'));
[$st, $out] = post($BASE, ['X-Rivet-Teams-Token' => $TOKEN . 'x', 'X-Rivet-Timestamp' => $ts], $body2);
ok($st === 401 && $out === '{}', 'wrong token: 401 and no detail');
[$st] = post($BASE, ['X-Rivet-Timestamp' => $ts], $body2);
ok($st === 401, 'no token header: 401');
[$st] = post($BASE, ['X-Rivet-Teams-Token' => $TOKEN], $body2);
ok($st === 401, 'no timestamp header: 401');
[$st] = post($BASE, ['X-Rivet-Teams-Token' => $TOKEN, 'X-Rivet-Timestamp' => (string) (time() - 1000)], $body2);
ok($st === 401, 'stale timestamp: 401');
[$st] = post($BASE, ['X-Rivet-Teams-Token' => $TOKEN2, 'X-Rivet-Timestamp' => $ts], $body2);
ok($st === 401, 'disabled / Slack destination token: 401');
ok((int) q1($db, 'SELECT ticket_assigned_to FROM tickets WHERE ticket_id = 17') === 0, 'rejected requests changed nothing');
[$st] = post($BASE, ['X-Rivet-Teams-Token' => $TOKEN, 'X-Rivet-Timestamp' => (string) (time() + 1)], 'not json');
ok($st === 400, 'authenticated but malformed JSON: 400');
[$st] = post($BASE, ['X-Rivet-Teams-Token' => $TOKEN, 'X-Rivet-Timestamp' => $ts], str_repeat('a', TeamsInteractive::MAX_BODY_BYTES + 5));
ok($st === 413, 'oversize body: 413');
$ch = curl_init($BASE);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
ok(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 405, 'GET: 405');
curl_close($ch);
ok(!str_contains((string) @file_get_contents($log), $TOKEN), 'server log never contains the token');

// ---------------------------------------------------------------- catalog risk score
section('Service catalog risk score');
ok(ServiceCatalogService::riskLevel(0)['label'] === 'Not rated' && ServiceCatalogService::riskLevel(1)['label'] === 'Low' && ServiceCatalogService::riskLevel(33)['label'] === 'Low' && ServiceCatalogService::riskLevel(34)['label'] === 'Medium' && ServiceCatalogService::riskLevel(66)['label'] === 'Medium' && ServiceCatalogService::riskLevel(67)['label'] === 'High' && ServiceCatalogService::riskLevel(100)['label'] === 'High', 'risk bands');
ok(ServiceCatalogService::riskLevel(500)['score'] === 100 && ServiceCatalogService::riskLevel(-4)['score'] === 0, 'score is clamped to 0-100');
ok(ServiceCatalogService::riskBadgeHtml(0) === '' && str_contains(ServiceCatalogService::riskBadgeHtml(80), 'Risk 80 (High)') && str_contains(ServiceCatalogService::riskBadgeHtml(80), 'text-bg-danger'), 'badge text and class');
$db->query("INSERT INTO service_catalog_items SET catalog_item_id = 1, name = 'Laptop <b>', requires_approval = 1, risk_score = 72, is_active = 1");
$db->query("INSERT INTO service_catalog_requests SET request_id = 1, catalog_item_id = 1, ticket_id = 10, client_id = 1, contact_id = 0, field_values = '[]', status = 'pending_approval', current_step = 1, risk_score = 72");
$svc = new ServiceCatalogService($db);
$html = $svc->requestDetailsHtml(10);
ok(str_contains($html, 'Risk 72 (High)') && str_contains($html, 'Waiting for approval'), 'request details on the ticket show the risk');
$db->query('UPDATE service_catalog_requests SET risk_score = 0 WHERE request_id = 1');
ok(!str_contains($svc->requestDetailsHtml(10), 'title="Risk score'), 'an unrated request shows no risk badge');

// pages that render the badge must at least lint and reference the helper
foreach (['admin/service_catalog.php', 'agent/service_catalog.php', 'agent/service_catalog_approvals.php', 'client/my_approvals.php'] as $f) {
    ok(str_contains((string) file_get_contents($ROOT . '/' . $f), 'riskBadgeHtml'), "$f renders the risk badge");
}

echo "\n$PASS passed, " . count($FAIL) . " failed\n";
if ($FAIL) {
    echo "FAILED: " . implode('; ', $FAIL) . "\n";
    exit(1);
}
