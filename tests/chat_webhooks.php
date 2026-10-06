<?php
/*
 * Slack / Teams chat destinations: formatter, routing filters, URL vetting (SSRF), delivery and the event bus, against a LOCAL mock
 * (tests/mock/slack_webhook.php). Never contacts Slack or Microsoft. Needs a schema-only scratch database (db.sql imported):
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/chat_webhooks.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
define('RIVETIT_CHAT_ALLOW_LOCAL_HTTP', true);   // test constant: lets the vetted-URL check accept http://127.0.0.1 (the mock) and nothing else

use ITFlow\Webhooks\ChatDelivery;
use ITFlow\Webhooks\ChatFormatter;

function encryptSetting(string $p): string { return $p === '' ? '' : 'ENC2:' . strrev($p); }
function decryptSetting(string $c): string { return str_starts_with($c, 'ENC2:') ? strrev(substr($c, 5)) : $c; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$db = $mysqli;
$fail = 0;
$ok = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fail++; };

// ======================================================= formatter (pure)
$evil = 'Server down <!channel> @here @channel <@U123> <http://evil.test|click> & *bold* `x` "q" \' \\ ' . "line\nbreak \u{1F525} \u{202E}";
$data = ['ticket_id' => 42, 'ticket_number' => 'TCK-0042', 'ticket_subject' => $evil, 'ticket_priority' => 'High', 'client_id' => 7, 'client_name' => 'Acme <b>Corp</b>', 'ticket_status' => 'Open'];
$opts = ['ticket_url' => 'https://helpdesk.example.test/agent/ticket.php?ticket_id=42', 'app_name' => 'RivetIT'];

$s = ChatFormatter::slack('ticket.created', $data, $opts);
$json = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$back = json_decode($json, true);
$ok($back === $s || json_last_error() === JSON_ERROR_NONE, 'slack payload round-trips as JSON');
$ok(isset($s['text'], $s['blocks']) && $s['blocks'][0]['type'] === 'header' && $s['blocks'][1]['type'] === 'section' && end($s['blocks'])['type'] === 'actions', 'slack: text fallback + header/section/actions blocks');
$ok(!str_contains($s['text'], '<!channel>') && str_contains($s['text'], '&lt;!channel&gt;') && !str_contains($s['text'], '<@U123>') && !str_contains($s['text'], '<http'), 'slack text: <!channel>, <@U123> and <url|label> neutralised by entity escaping');
$ok(!preg_match('/@(channel|here|everyone)/i', $s['text']), 'slack text: @channel / @here defanged');
$allText = json_encode($s['blocks']);
$ok(!str_contains($allText, '"mrkdwn"') && $s['mrkdwn'] === false, 'slack blocks: user text only in plain_text elements; mrkdwn off');
$ok($s['blocks'][1]['text']['type'] === 'plain_text' && $s['blocks'][2]['elements'][0]['url'] === $opts['ticket_url'], 'slack: title is plain_text, ticket link is a button url');
$ok(count(array_filter($s['blocks'][1]['fields'], fn ($f) => str_starts_with($f['text'], 'Priority: High'))) === 1 && str_contains(json_encode($s['blocks'][1]['fields']), 'Acme'), 'slack: priority, client and status fields present');

$t = ChatFormatter::teams('ticket.created', $data, $opts);
$card = $t['attachments'][0]['content'];
$ok($t['type'] === 'message' && $t['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive' && $card['type'] === 'AdaptiveCard' && $card['version'] === '1.4', 'teams: Workflows format = message + Adaptive Card 1.4 attachment');
$ok($card['actions'][0]['type'] === 'Action.OpenUrl' && $card['actions'][0]['url'] === $opts['ticket_url'], 'teams: Open ticket action');
$ok(json_decode(json_encode($t), true) === $t, 'teams payload is plain JSON data');
$title = $card['body'][1]['text'];
$ok(!str_contains($title, '<') && str_contains($title, '\\*bold\\*') && str_contains($title, '\\`x\\`'), 'teams text: angle brackets removed, Markdown control chars escaped');
$md = ChatFormatter::teamsEscape('[click](http://evil.test) <at>Everyone</at> # h');
$ok(!str_contains($md, '[click](') && !str_contains($md, '<at>') && str_contains($md, '\\[click\\]\\(http://evil.test\\)'), 'teams: [links](x) and <at> mentions neutralised');

$p = ChatFormatter::slack('login.failed', ['summary' => 'Failed sign-in for bob', 'action' => 'failed', 'entity_type' => 'user', 'metadata' => ['password' => 'hunter2']], []);
$ok(!str_contains(json_encode($p), 'hunter2'), 'platform events: metadata is never put in the chat message');
$tm = ChatFormatter::slack('x', [], ['test' => true]);
$ok(str_contains($tm['blocks'][0]['text']['text'], 'TEST MESSAGE'), 'test message is clearly labelled');
$long = ChatFormatter::slack('ticket.created', ['ticket_subject' => str_repeat('A', 9000)] , []);
$ok(mb_strlen($long['blocks'][1]['text']['text']) <= 250 && mb_strlen($long['text']) < 1000, 'long subjects are clipped');
$ok(ChatFormatter::format('generic', 'e', [], []) === null && ChatFormatter::normalizeType('SLACK ') === 'slack' && ChatFormatter::normalizeType('evil') === 'generic', 'type handling: generic = no chat formatting; unknown -> generic');

// routing
$wh = ['webhook_events' => 'ticket.created,ticket.replied', 'webhook_min_priority' => 'High', 'webhook_client_ids' => '7,9'];
$ok(ChatFormatter::shouldDeliver($wh, 'ticket.created', $data), 'route: subscribed event, High priority, client 7 -> deliver');
$ok(!ChatFormatter::shouldDeliver($wh, 'ticket.resolved', $data), 'route: unsubscribed event -> drop');
$ok(!ChatFormatter::shouldDeliver($wh, 'ticket.created', ['ticket_priority' => 'Low'] + $data), 'route: Low priority below minimum High -> drop');
$ok(ChatFormatter::shouldDeliver($wh, 'ticket.created', ['ticket_priority' => 'Critical'] + $data), 'route: Critical above minimum -> deliver');
$ok(!ChatFormatter::shouldDeliver($wh, 'ticket.created', ['client_id' => 8] + $data), 'route: client 8 not in the client filter -> drop');
$ok(ChatFormatter::shouldDeliver(['webhook_events' => 'ticket.created', 'webhook_min_priority' => '', 'webhook_client_ids' => ''], 'ticket.created', ['client_id' => 99, 'ticket_priority' => 'Low']), 'route: no filters -> everything subscribed');
$ok(ChatFormatter::shouldDeliver(['webhook_events' => 'login.failed', 'webhook_min_priority' => 'High', 'webhook_client_ids' => '7'], 'login.failed', ['summary' => 'x']), 'route: platform event (no priority/client) is not dropped by those filters');

// ======================================================= SSRF / URL vetting
$ok(!ChatDelivery::vetUrl('http://hooks.slack.com/services/T/B/x')['ok'], 'vet: plain http rejected');
foreach (['https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://192.168.1.1/x', 'https://169.254.169.254/latest/meta-data', 'https://[::1]/x', 'https://localhost/x', 'https://172.16.0.1/x', 'https://0.0.0.0/x'] as $u) {
    $ok(!ChatDelivery::vetUrl($u)['ok'], "vet: $u rejected");
}
$ok(!ChatDelivery::vetUrl('http://169.254.169.254/x')['ok'] && !ChatDelivery::vetUrl('http://10.0.0.5/x')['ok'] && !ChatDelivery::vetUrl('http://example.com/x')['ok'], 'vet: the test constant allows ONLY loopback http, not other private or http hosts');
$ok(!ChatDelivery::vetUrl('https://user:pw@8.8.8.8/x')['ok'] && !ChatDelivery::vetUrl("https://8.8.8.8/x y")['ok'] && !ChatDelivery::vetUrl('https://8.8.8.8\\@10.0.0.1/')['ok'] && !ChatDelivery::vetUrl('ftp://8.8.8.8/x')['ok'] && !ChatDelivery::vetUrl('https://8.8.8.8/' . str_repeat('a', 1100))['ok'], 'vet: userinfo, whitespace, backslash, other schemes and over-long URLs rejected');
$ok(ChatDelivery::vetUrl('https://8.8.8.8/services/x')['ok'], 'vet: a public https address is accepted');
$ok(ChatDelivery::maskUrl('https://hooks.slack.com/services/T000/B000/SECRETSECRET') === 'https://hooks.slack.com/…hidden', 'maskUrl shows scheme and host only');

foreach (["webhooks", "webhook_deliveries", "integration_jobs"] as $t) $db->query("DELETE FROM `$t`");
// ======================================================= delivery against the mock
$port = random_int(20000, 40000);
$logFile = sys_get_temp_dir() . '/chat_mock_' . getmypid() . '.jsonl';
@unlink($logFile);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/mock/slack_webhook.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MOCK_LOG' => $logFile, 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($proc, $logFile) { proc_terminate($proc); @unlink($logFile); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
$reqs = function () use ($logFile) { return is_file($logFile) ? array_map(fn ($l) => json_decode($l, true), file($logFile, FILE_IGNORE_NEW_LINES)) : []; };
$mkHook = function (string $type, string $path, string $events = 'ticket.created', string $minp = '', string $clients = '') use ($db, $port) {
    $url = encryptSetting("http://127.0.0.1:$port$path");
    $stmt = $db->prepare("INSERT INTO webhooks SET webhook_name=?, webhook_url=?, webhook_secret='', webhook_events=?, webhook_enabled=1, webhook_type=?, webhook_min_priority=?, webhook_client_ids=?");
    $name = "$type hook"; $stmt->bind_param('ssssss', $name, $url, $events, $type, $minp, $clients); $stmt->execute();
    return (int) $db->insert_id;
};
$row = fn (int $id) => $db->query("SELECT * FROM webhooks WHERE webhook_id=$id")->fetch_assoc();
$secretPath = '/slack/ok';

$sid = $mkHook('slack', '/slack/ok');
$r = ChatDelivery::deliverRow($db, $row($sid), 'ticket.created', $data, 1);
$got = $reqs();
$body = json_decode($got[0]['body'] ?? '', true);
$ok($r['ok'] && $r['http_status'] === 200 && count($got) === 1 && isset($body['blocks']) && str_contains($got[0]['content_type'], 'application/json'), 'slack delivery: one POST, JSON body with blocks, HTTP 200');
$ok($got[0]['sig'] === '' && str_contains($got[0]['body'], 'TCK-0042'), 'slack delivery: carries the ticket, no generic signature header');
$dl = $db->query("SELECT * FROM webhook_deliveries WHERE webhook_id=$sid ORDER BY delivery_id DESC LIMIT 1")->fetch_assoc();
$ok($dl && (int) $dl['http_status'] === 200 && $dl['event_type'] === 'ticket.created' && !str_contains($dl['request_payload_json'] . $dl['response_body_snippet'], "127.0.0.1:$port"), 'delivery log row written (HTTP 200) without the destination URL');

@unlink($logFile);
$tid = $mkHook('teams', '/teams/ok');
$r = ChatDelivery::deliverRow($db, $row($tid), 'ticket.created', $data, 1);
$body = json_decode($reqs()[0]['body'] ?? '', true);
$ok($r['ok'] && $r['http_status'] === 202 && ($body['attachments'][0]['content']['version'] ?? '') === '1.4', 'teams delivery: Adaptive Card 1.4, HTTP 202 accepted as success');

@unlink($logFile);
$r = ChatDelivery::deliverRow($db, $row($sid), 'ticket.created', $data, 1, true);
$b = $reqs()[0]['body'] ?? '';
$ok($r['ok'] && str_contains($b, 'TEST MESSAGE') && $db->query("SELECT event_type FROM webhook_deliveries WHERE webhook_id=$sid ORDER BY delivery_id DESC LIMIT 1")->fetch_row()[0] === 'test.slack', 'test button path: labelled test message, logged as test.slack');

@unlink($logFile);
$f500 = $mkHook('slack', '/fail/500');
$r = ChatDelivery::deliverRow($db, $row($f500), 'ticket.created', $data, 1);
$ok(!$r['ok'] && $r['error'] === 'HTTP 500' && $r['http_status'] === 500, 'HTTP 500 -> failure (the job queue retries it)');
$f404 = $mkHook('slack', '/fail/404');
$r = ChatDelivery::deliverRow($db, $row($f404), 'ticket.created', $data, 1);
$dl = $db->query("SELECT response_body_snippet FROM webhook_deliveries WHERE webhook_id=$f404")->fetch_row()[0];
$ok(!$r['ok'] && $dl === 'no_service', 'Slack error text (no_service) is logged so the admin can see why');

@unlink($logFile);
$rid = $mkHook('slack', '/redirect');
$r = ChatDelivery::deliverRow($db, $row($rid), 'ticket.created', $data, 1);
$ok(!$r['ok'] && $r['http_status'] === 302 && count($reqs()) === 1, 'redirects are not followed');

@unlink($logFile);
$flt = $mkHook('slack', '/slack/ok', 'ticket.created', 'High', '');
$r = ChatDelivery::deliverRow($db, $row($flt), 'ticket.created', ['ticket_priority' => 'Low'] + $data, 1);
$ok($r['ok'] && $r['skipped'] && count($reqs()) === 0, 'filtered-out event: no request sent, counted as handled');

// private destination stored directly in the DB is still refused at send time (defence in depth, e.g. edited row)
$stmt = $db->prepare("INSERT INTO webhooks SET webhook_name='evil', webhook_url=?, webhook_events='ticket.created', webhook_enabled=1, webhook_type='slack'");
$evilUrl = encryptSetting('https://169.254.169.254/latest/meta-data/iam/'); $stmt->bind_param('s', $evilUrl); $stmt->execute();
$r = ChatDelivery::deliverRow($db, $row((int) $db->insert_id), 'ticket.created', $data, 1);
$ok(!$r['ok'] && $r['http_status'] === null && str_contains((string) $r['error'], 'public address'), 'delivery re-vets the URL: a metadata/private address stored in the row is refused');

// network failure message must not leak the secret path
$deadUrl = 'http://127.0.0.1:1/services/T000/B000/TOPSECRETTOKEN';
$err = ChatDelivery::post($deadUrl, '{}');
$ok($err['status'] === null && !str_contains((string) $err['error'], 'TOPSECRETTOKEN') && !str_contains((string) $err['error'], '/services/T000'), 'connection errors never contain the secret URL path');
$ok(!str_contains(ChatDelivery::redact('error at https://hooks.slack.com/services/T0/B0/SECRET123 here /services/T0/B0/SECRET123', 'https://hooks.slack.com/services/T0/B0/SECRET123'), 'SECRET123'), 'redact() removes URL and path');

// ======================================================= event bus end to end (queue -> handler -> mock)
require_once __DIR__ . '/../includes/event_bus.php';
@unlink($logFile);
foreach (["webhooks", "webhook_deliveries", "integration_jobs"] as $t) $db->query("DELETE FROM `$t`");
$gen = $db->query("INSERT INTO webhooks SET webhook_name='plain', webhook_url='http://127.0.0.1:$port/generic', webhook_secret='" . encryptSetting('s3cr3t') . "', webhook_events='ticket.created', webhook_enabled=1") ? (int) $db->insert_id : 0;
$sl = $mkHook('slack', '/slack/ok', 'ticket.created', 'High', '7');
$tm = $mkHook('teams', '/teams/ok', 'ticket.created', '', '');
$sl_low = $mkHook('slack', '/slack/ok', 'ticket.created', 'Critical', '');   // High ticket is below Critical -> never queued
rivetEmitEvent('ticket.created', $data);
$jobs = (int) $db->query("SELECT COUNT(*) FROM integration_jobs WHERE job_type='webhook.deliver'")->fetch_row()[0];
$ok($jobs === 3, "event bus: 3 jobs queued for 4 subscribed endpoints (Critical-only Slack filtered before queueing); got $jobs");
$res = rivetRunJobWorker($db, 20, 20);
$got = $reqs();
$paths = array_column($got, 'path'); sort($paths);
$ok($paths === ['/generic', '/slack/ok', '/teams/ok'], 'event bus: generic, slack and teams each got exactly one POST: ' . implode(',', $paths));
$g = array_values(array_filter($got, fn ($x) => $x['path'] === '/generic'))[0];
$gb = json_decode($g['body'], true);
$ok(str_starts_with($g['sig'], 'sha256=') && hash_equals($g['sig'], 'sha256=' . hash_hmac('sha256', $g['body'], 's3cr3t')) && $g['event'] === 'ticket.created' && ($gb['data']['ticket_number'] ?? '') === 'TCK-0042' && !isset($gb['blocks']), 'generic webhook unchanged: signed {event,timestamp,data} body, valid HMAC');
$ok((int) $db->query("SELECT COUNT(*) FROM webhook_deliveries WHERE http_status BETWEEN 200 AND 299")->fetch_row()[0] === 3, 'event bus: 3 successful delivery log rows (generic + slack + teams)');
// failing chat destination goes through the job retry path
$db->query("DELETE FROM webhooks"); $db->query("DELETE FROM integration_jobs");
$mkHook('slack', '/fail/500');
rivetEmitEvent('ticket.created', $data);
rivetRunJobWorker($db, 20, 20);
$jstat = $db->query("SELECT status, attempts FROM integration_jobs WHERE job_type='webhook.deliver' ORDER BY job_id DESC LIMIT 1")->fetch_assoc();
$ok($jstat && $jstat['status'] !== 'completed' && (int) $jstat['attempts'] >= 1, 'event bus: failed chat delivery is retried by the job queue (status ' . ($jstat['status'] ?? '?') . ')');

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
