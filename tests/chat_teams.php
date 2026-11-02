<?php
/*
 * Microsoft Teams bot (issue #36) against the REAL src/Chat code, a scratch database and LOCAL mocks of the Bot Framework (tests/mock/chat_platforms.php).
 * UNVERIFIED LIVE: a locally generated RSA key stands in for the Bot Framework signing key and a mock JWKS endpoint serves it; the token endpoint and the
 * connector are mocks too. Covers JWT validation (audience, issuer, expiry, serviceurl, endorsement, key rotation, algorithm confusion, tampering),
 * key fetching and caching, the connector (token cache, SSRF allow-list), the bot (disabled by default, tenant pin, replay, identity, commands, card
 * buttons, approvals, two-way thread), the Teams app package and the real /teams_bot.php endpoint over HTTP.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=u RIVETIT_TEST_DB_PASS=p php tests/chat_teams.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
define('RIVETIT_CHAT_ALLOW_LOCAL_HTTP', true);
define('APP_NAME', 'RivetIT');

use Firebase\JWT\JWT;
use ITFlow\Chat\{ChatActions, ChatIdentity, ChatNotifier, ChatSettings, TeamsBot, TeamsCards, TeamsConnector, TeamsJwt, TeamsKeys, TeamsPackage};
use ITFlow\Webhooks\SlackInteractive;

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

// ---- keys: a signing key the "Bot Framework" uses, a second one nobody trusts
$mk = function (): array {
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $priv);
    $d = openssl_pkey_get_details($res);
    $b64 = fn (string $x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    return ['priv' => $priv, 'pub' => $d['key'], 'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e'])];
};
$K1 = $mk(); $K2 = $mk();
$jwk = fn (array $k, string $kid, array $end = ['msteams', 'webchat']) => ['kty' => 'RSA', 'use' => 'sig', 'kid' => $kid, 'n' => $k['n'], 'e' => $k['e'], 'endorsements' => $end];
$APP = 'aaaaaaaa-1111-2222-3333-444444444444'; $TENANT = 'bbbbbbbb-1111-2222-3333-444444444444';
$SVC = 'https://smba.trafficmanager.net/amer/';
$NOW = 1800000000;
$tok = function (array $over = [], ?array $key = null, string $kid = 'kid1', string $alg = 'RS256', ?string $secret = null) use ($K1, $APP, $SVC, $NOW): string {
    $claims = $over + ['iss' => 'https://api.botframework.com', 'aud' => $APP, 'serviceurl' => $SVC, 'iat' => $NOW - 10, 'nbf' => $NOW - 10, 'exp' => $NOW + 3000];
    return JWT::encode($claims, $secret ?? ($key ?? $K1)['priv'], $alg, $kid);
};
$calls = 0;
$jwks = ['keys' => [$jwk($K1, 'kid1')]];
$provider = function (bool $refresh) use (&$jwks, &$calls) { if ($refresh) $calls++; return $jwks; };
$v = new TeamsJwt($provider);
$val = fn (string $t, string $app = null, string $svc = null, string $ch = 'msteams', int $now = null) => $v->validate("Bearer $t", $app ?? $APP, $svc ?? $SVC, $ch, $now ?? $NOW);

// ======================================================= 1. JWT validation
$ok($val($tok())['ok'] === true, 'a correctly signed token for this bot and serviceUrl is accepted');
$ok($v->validate('', $APP, $SVC, 'msteams', $NOW)['error'] === 'no_bearer' && $v->validate('Basic abc', $APP, $SVC, 'msteams', $NOW)['ok'] === false && $v->validate('Bearer not.a.jwt', $APP, $SVC, 'msteams', $NOW)['ok'] === false && $v->validate('Bearer ' . $tok(), '', $SVC, 'msteams', $NOW)['ok'] === false, 'missing / malformed Authorization header and an unconfigured App ID are refused');
$ok($val($tok(['aud' => 'cccccccc-1111-2222-3333-444444444444']))['error'] === 'bad_audience', 'a token for another bot (audience) is refused');
$ok($val($tok(['iss' => 'https://evil.example.test']))['error'] === 'bad_issuer' && $val($tok(['iss' => 'https://login.microsoftonline.com/x/v2.0']))['ok'] === false, 'a token from another issuer is refused');
$ok($val($tok(['exp' => $NOW - 400, 'nbf' => $NOW - 4000]))['ok'] === false, 'an expired token (beyond the 5 minute tolerance) is refused');
$ok($val($tok(['exp' => $NOW - 200, 'nbf' => $NOW - 4000]))['ok'] === true, 'a token that expired 200 s ago is still inside the 5 minute tolerance');
$ok($val($tok(['nbf' => $NOW + 400, 'iat' => $NOW + 400]))['ok'] === false, 'a token not valid yet (nbf in the future beyond tolerance) is refused');
$ok($val($tok(), null, 'https://evil.example.test/')['error'] === 'bad_serviceurl' && $val($tok(['serviceurl' => '']))['ok'] === false, 'the serviceUrl in the activity must equal the token\'s serviceurl claim');
$ok($val($tok(), null, 'https://smba.trafficmanager.net/amer')['ok'] === true, 'a trailing slash difference in serviceUrl is tolerated');
$ok($val($tok(), null, null, 'slack')['error'] === 'not_endorsed', 'a key not endorsed for the activity\'s channel is refused');
$ok($val($tok([], null, 'ghost'))['error'] === 'unknown_key' && $calls >= 1, 'an unknown key id is refused after one refresh attempt');
$calls = 0; $jwks = ['keys' => [$jwk($K1, 'kid1')]];
$rot = new TeamsJwt(function (bool $refresh) use ($K2, $jwk, &$calls) { if ($refresh) { $calls++; return ['keys' => [$jwk($K2, 'kid2')]]; } return ['keys' => []]; });
$ok($rot->validate('Bearer ' . $tok([], $K2, 'kid2'), $APP, $SVC, 'msteams', $NOW)['ok'] === true && $calls === 1, 'key rotation: an unknown kid triggers exactly one refresh and then validates');
$ok($val($tok([], $K2, 'kid1'))['ok'] === false, 'a token signed by a different RSA key but claiming a trusted kid is refused');
$parts = explode('.', $tok()); $evil = $parts[0] . '.' . rtrim(strtr(base64_encode(json_encode(['iss' => 'https://api.botframework.com', 'aud' => $APP, 'serviceurl' => $SVC, 'exp' => $NOW + 3000, 'nbf' => $NOW - 10, 'x' => 'tampered'])), '+/', '-_'), '=') . '.' . $parts[2];
$ok($val($evil)['ok'] === false, 'a token whose payload was altered after signing is refused');
$none = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'kid1'])), '+/', '-_'), '=') . '.' . $parts[1] . '.';
$ok($val($none)['ok'] === false, 'alg=none is refused');
$hs = JWT::encode(['iss' => 'https://api.botframework.com', 'aud' => $APP, 'serviceurl' => $SVC, 'exp' => $NOW + 3000, 'nbf' => $NOW - 10], $K1['pub'], 'HS256', 'kid1');
$ok($val($hs)['ok'] === false, 'algorithm confusion (HS256 signed with the public key) is refused');
$ok($val($tok(['exp' => null]))['ok'] === false, 'a token without an expiry is refused');

// ======================================================= 2. keys: fetch, cache, rate limit, host restriction
$port = random_int(20000, 40000);
$mlog = sys_get_temp_dir() . '/chat_teams_mock_' . getmypid() . '.jsonl'; $jfile = sys_get_temp_dir() . '/chat_teams_jwks_' . getmypid() . '.json';
file_put_contents($jfile, json_encode(['keys' => [$jwk($K1, 'kid1')]]));
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/mock/chat_platforms.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MOCK_LOG' => $mlog, 'MOCK_JWKS' => $jfile] + $_ENV + ['PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($proc, $mlog, $jfile) { proc_terminate($proc); foreach ([$mlog, "$mlog.n", "$mlog.t", $jfile] as $f) @unlink($f); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
define('RIVETIT_TEAMS_OPENID_URL', "http://127.0.0.1:$port/teams/openid");
define('RIVETIT_TEAMS_TOKEN_URL', "http://127.0.0.1:$port/teams/token");
$mockLog = fn () => array_map(fn ($l) => json_decode($l, true), is_file($mlog) ? file($mlog, FILE_IGNORE_NEW_LINES) : []);
$mockReset = function () use ($mlog) { @unlink($mlog); };
$q("DELETE FROM chat_kv");
$prov = TeamsKeys::provider($db);
$j1 = $prov(false); $n1 = count($mockLog());
$j2 = $prov(false);
$ok(($j1['keys'][0]['kid'] ?? '') === 'kid1' && count($mockLog()) === $n1 && $n1 === 2, 'the signing keys are fetched once (metadata + keys) and then served from the cache');
$j3 = $prov(true);
$ok(count($mockLog()) === $n1, 'a forced refresh inside 5 minutes is served from the cache (rate limited, cannot be used to hammer Microsoft)');
$q("UPDATE chat_kv SET kv_updated_at = NOW() - INTERVAL 10 MINUTE WHERE kv_key = 'teams_jwks'");
$prov(true);
$ok(count($mockLog()) === $n1 + 2, 'an old cache entry is refreshed on demand');
$ok(TeamsKeys::fetch() !== null, 'fetch works against the mock');
$ok(TeamsKeys::fetch("http://127.0.0.1:$port/teams/openid-evil") === null, 'a metadata document that points the keyset at a foreign host is refused');

// ======================================================= 3. fixtures for connector / bot
foreach (['chat_threads', 'chat_identities', 'slack_interactive_seen', 'ticket_replies', 'tickets', 'service_catalog_request_approvals', 'service_catalog_requests', 'service_catalog_approval_steps', 'service_catalog_fields', 'service_catalog_items', 'kb_articles', 'contacts',
    'user_client_permissions', 'user_role_permissions', 'modules', 'users', 'ticket_statuses', 'clients', 'webhooks', 'logs'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM settings WHERE company_id = 1"); $q("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.229', config_ticket_prefix = 'TCK-', config_ticket_next_number = 500");
foreach ([[1, 'New'], [2, 'Open'], [3, 'On Hold'], [5, 'Closed']] as [$id, $n]) { $q("INSERT INTO ticket_statuses SET ticket_status_id = $id, ticket_status_name = '$n', ticket_status_color = '#000', ticket_status_active = 1"); }
$q("INSERT INTO clients SET client_id = 1, client_name = 'Dept A'"); $q("INSERT INTO clients SET client_id = 2, client_name = 'Dept B'");
$q("INSERT INTO user_roles (role_id, role_name, role_is_admin, role_type) VALUES (1, 'Admin', 1, 1), (2, 'Tech', 0, 1) ON DUPLICATE KEY UPDATE role_is_admin = VALUES(role_is_admin)");
$q("INSERT INTO modules SET module_id = 1, module_name = 'module_support', module_description = 'x'");
$q("INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 1, user_role_permission_level = 2");
$mkUser = fn (int $id, string $name, string $email, int $role) => $q("INSERT INTO users SET user_id = $id, user_name = '$name', user_email = '$email', user_password = 'x', user_type = 1, user_status = 1, user_role_id = $role");
$mkUser(10, 'Alice Admin', 'alice@x.test', 1); $mkUser(11, 'Bob Tech', 'bob@x.test', 2);
$q("INSERT INTO user_client_permissions SET user_id = 11, client_id = 1");
$q("INSERT INTO contacts SET contact_id = 50, contact_name = 'Pat Portal', contact_email = 'pat@client.test', contact_client_id = 1");
$setCfg = function (array $kv) use ($q) { foreach ($kv as $k => $v) { $q("UPDATE settings SET $k = " . (is_int($v) ? $v : "'" . addslashes($v) . "'") . ' WHERE company_id = 1'); } };
$BOB = '11111111-aaaa-bbbb-cccc-000000000011'; $PAT = '22222222-aaaa-bbbb-cccc-000000000050'; $STRANGER = '99999999-aaaa-bbbb-cccc-000000000099'; $ALICE_EMAIL = '11111111-1111-1111-1111-111111111111';
$q("INSERT INTO chat_identities SET identity_platform = 'teams', identity_team_id = '$TENANT', identity_external_id = '$BOB', identity_user_id = 11");
$q("INSERT INTO chat_identities SET identity_platform = 'teams', identity_team_id = '', identity_external_id = '$PAT', identity_contact_id = 50");
$SVCLOCAL = "http://127.0.0.1:$port/teams/service/";

// ======================================================= 4. connector
$setCfg(['config_teams_app_id' => $APP, 'config_teams_app_secret' => encryptSetting('client-secret-value-123'), 'config_teams_tenant_id' => $TENANT]);
$conn = new TeamsConnector($db, new ChatSettings($db));
$ok(TeamsConnector::serviceUrlAllowed('https://smba.trafficmanager.net/amer/') && TeamsConnector::serviceUrlAllowed('https://smba.infra.gcc.teams.microsoft.com/x/') && TeamsConnector::serviceUrlAllowed('https://api.botframework.com/'), 'Microsoft Bot Connector hosts are allowed as service URLs');
$ok(!TeamsConnector::serviceUrlAllowed('https://evil.example.test/') && !TeamsConnector::serviceUrlAllowed('https://smba.trafficmanager.net.evil.example.test/') && !TeamsConnector::serviceUrlAllowed('http://smba.trafficmanager.net/') && !TeamsConnector::serviceUrlAllowed('https://user:pw@smba.trafficmanager.net/') && !TeamsConnector::serviceUrlAllowed('https://169.254.169.254/') && !TeamsConnector::serviceUrlAllowed('https://smba.trafficmanager.net/?x=1'), 'any other host, look-alike hosts, plain http, credentials and cloud metadata are refused');
$mockReset();
$r1 = $conn->send($SVCLOCAL, 'conv-1', TeamsCards::textActivity('hello <at>x</at>'));
$r2 = $conn->send($SVCLOCAL, 'conv-1', TeamsCards::textActivity('again'));
$ml = $mockLog(); $tokenCalls = array_filter($ml, fn ($m) => $m['path'] === '/teams/token'); $acts = array_values(array_filter($ml, fn ($m) => str_contains($m['path'], '/activities')));
$ok($r1['ok'] && $r2['ok'] && count($tokenCalls) === 1 && count($acts) === 2 && $acts[0]['auth'] === 'Bearer mock-bot-token', 'connector: one token request for two sends, token sent as a bearer header');
$tc = array_values($tokenCalls)[0]; parse_str($tc['body'], $tf);
$ok($tf['grant_type'] === 'client_credentials' && $tf['client_id'] === $APP && $tf['client_secret'] === 'client-secret-value-123' && $tf['scope'] === 'https://api.botframework.com/.default', 'token request is a client-credentials grant for the bot scope');
$a0 = json_decode($acts[0]['body'], true);
$ok(str_contains($a0['text'], '\\<') === false && !str_contains($a0['text'], '<at>') , 'outgoing text has no live <at> mention (Markdown / mention neutralised)');
$ok(str_contains((string) $one("SELECT kv_value FROM chat_kv WHERE kv_key = 'teams_token'"), 'mock-bot-token') && !str_contains((string) $one("SELECT kv_value FROM chat_kv WHERE kv_key = 'teams_token'"), 'client-secret'), 'the cached token never contains the client secret');
$ok(!$conn->send('https://evil.example.test/', 'c', TeamsCards::textActivity('x'))['ok'] && !$conn->send($SVCLOCAL, '', TeamsCards::textActivity('x'))['ok'], 'connector refuses a foreign service URL and an empty conversation');
$setCfg(['config_teams_app_secret' => encryptSetting('wrong')]); $q("DELETE FROM chat_kv WHERE kv_key = 'teams_token'");
$ok((new TeamsConnector($db, new ChatSettings($db)))->send($SVCLOCAL, 'c', TeamsCards::textActivity('x'))['error'] === 'no_token', 'a rejected client secret fails closed (no send)');
$setCfg(['config_teams_app_secret' => '']); $q("DELETE FROM chat_kv WHERE kv_key = 'teams_token'");
$ok((new TeamsConnector($db, new ChatSettings($db)))->token() === null, 'no client secret: no token');
$setCfg(['config_teams_app_secret' => encryptSetting('client-secret-value-123')]); $q("DELETE FROM chat_kv WHERE kv_key = 'teams_token'");
$ok((new TeamsConnector($db, new ChatSettings($db)))->memberEmail($SVCLOCAL, 'conv-1', $ALICE_EMAIL) === 'alice@x.test', 'member email lookup returns the directory address for exactly that member');

// ======================================================= 5. the bot
$sent = [];
$sender = function (string $url, string $conv, array $act) use (&$sent) { $sent[] = compact('url', 'conv', 'act'); return ['ok' => true, 'error' => null, 'id' => 'x']; };
$audits = []; $auditFn = function (string $e, ?int $a, string $et, $id, string $act, string $sum, array $meta = []) use (&$audits) { $audits[] = compact('e', 'a', 'sum'); };
$bot = new TeamsBot($db, $auditFn, $sender, fn ($aad, $conv, $url) => $aad === $ALICE_EMAIL ? 'alice@x.test' : null);
$act = fn (string $text, string $from = null, array $x = []) => $x + ['type' => 'message', 'id' => 'a' . bin2hex(random_bytes(4)), 'serviceUrl' => $SVC, 'channelId' => 'msteams', 'text' => $text, 'from' => ['id' => '29:abc', 'aadObjectId' => $from ?? $BOB], 'conversation' => ['id' => 'conv-1', 'conversationType' => 'personal', 'tenantId' => $TENANT], 'channelData' => ['tenant' => ['id' => $TENANT]]];
$lastText = function () use (&$sent) { return $sent ? (end($sent)['act']['text'] ?? '') : ''; };
$ok($bot->authenticate("Bearer " . $tok(), $act('hi'), $provider, $NOW)['error'] === 'disabled', 'the Teams bot is OFF by default: even a valid token is refused');
$setCfg(['config_teams_bot_enabled' => 1]); $bot = new TeamsBot($db, $auditFn, $sender, fn ($aad, $conv, $url) => $aad === $ALICE_EMAIL ? 'alice@x.test' : null);
$ok($bot->authenticate("Bearer " . $tok(), $act('hi'), $provider, $NOW)['ok'] === true, 'bot ON: a valid Bot Framework token authenticates');
$ok($bot->authenticate("Bearer " . $tok(), ['serviceUrl' => 'https://evil.example.test/', 'channelId' => 'msteams'] + $act('hi'), $provider, $NOW)['ok'] === false, 'bot: a valid token replayed with a different serviceUrl is refused');
$seen = new SlackInteractive($db); $claim = fn (string $k) => $seen->claimOnce($k);
$bot->handle($act('help'), $claim);
$ok($sent && str_contains($lastText(), 'new'), 'help is answered');
$sent = [];
$bot->handle($act('new Printer jammed', $STRANGER), $claim);
$ok(count($sent) === 1 && str_contains($lastText(), "isn't linked") && (int) $one("SELECT COUNT(*) FROM tickets") === 0, 'an unlinked Teams user is told so and nothing is created');
$sent = [];
$bot->handle($act('new Printer jammed', $BOB), $claim);
$ok(str_contains($lastText(), 'commands') || str_contains($lastText(), 'turned off'), 'commands OFF (default): creating a ticket from Teams is refused');
$setCfg(['config_chat_commands_enabled' => 1, 'config_chat_default_client_id' => 1]); $bot = new TeamsBot($db, $auditFn, $sender, fn ($aad, $conv, $url) => $aad === $ALICE_EMAIL ? 'alice@x.test' : null);
$sent = []; $aid = 'a-fixed-1'; $a1 = $act('<at>RivetIT</at> new priority=High Printer <b>jammed</b>', $BOB, ['id' => $aid]);
$bot->handle($a1, $claim);
$tk = $q("SELECT * FROM tickets ORDER BY ticket_id DESC LIMIT 1")->fetch_assoc();
$ok($tk && $tk['ticket_priority'] === 'High' && (int) $tk['ticket_client_id'] === 1 && $tk['ticket_source'] === 'Teams' && (int) $tk['ticket_created_by'] === 11 && str_contains($lastText(), 'Created'), 'a linked agent creates a ticket by messaging the bot (mention stripped, priority honoured, source Teams)');
$n = (int) $one("SELECT COUNT(*) FROM tickets"); $bot->handle($a1, $claim);
$ok((int) $one("SELECT COUNT(*) FROM tickets") === $n, 'the same activity delivered twice (Bot Framework retry) is handled once');
$sent = []; $bot->handle($act('status ' . $tk['ticket_prefix'] . $tk['ticket_number'], $BOB), $claim);
$ok(str_contains($lastText(), 'Priority') && str_contains($lastText(), 'jammed'), 'status of a ticket');
$sent = []; $bot->handle($act('new Pat cannot print', $PAT), $claim);
$ptk = $q("SELECT * FROM tickets WHERE ticket_subject LIKE 'Pat cannot%'")->fetch_assoc();
$ok($ptk && (int) $ptk['ticket_contact_id'] === 50, 'a linked portal contact can raise a ticket (their own department and contact)');
$sent = []; $bot->handle($act('status ' . $tk['ticket_number'], $PAT), $claim);
$ok(str_contains($lastText(), 'could not find'), 'a contact cannot read an agent ticket that is not theirs');
$q("DELETE FROM slack_interactive_seen");
// tenant pin
$sent = []; $other = $act('new from another tenant', $BOB); $other['channelData']['tenant']['id'] = 'dddddddd-1111-2222-3333-444444444444'; $other['conversation']['tenantId'] = $other['channelData']['tenant']['id'];
$bot->handle($other, $claim);
$ok($sent === [] && (int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_subject LIKE 'from another tenant%'") === 0, 'an activity from a tenant other than the pinned one is ignored silently');
// non-message types
$sent = []; $bot->handle(['type' => 'conversationUpdate'] + $act('x'), $claim); $bot->handle(['type' => 'typing'] + $act('x'), $claim);
$ok($sent === [], 'non-message activities are ignored');
// actions: card buttons
$sent = [];
$bot->handle($act('', $BOB, ['value' => ['rivet' => 'priority', 'ticket' => (int) $tk['ticket_id'], 'choice_priority' => 'Low'], 'text' => null]), $claim);
$ok(str_contains($lastText(), 'turned off') && $one("SELECT ticket_priority FROM tickets WHERE ticket_id = {$tk['ticket_id']}") === 'High', 'card buttons do nothing while "actions from chat" is OFF');
$setCfg(['config_chat_actions_enabled' => 1]); $bot = new TeamsBot($db, $auditFn, $sender, fn ($aad, $conv, $url) => $aad === $ALICE_EMAIL ? 'alice@x.test' : null);
$bot->handle($act('', $BOB, ['value' => ['rivet' => 'priority', 'ticket' => (int) $tk['ticket_id'], 'choice_priority' => 'Low']]), $claim);
$bot->handle($act('', $BOB, ['value' => ['rivet' => 'status', 'ticket' => (int) $tk['ticket_id'], 'choice_status' => 'On Hold']]), $claim);
$bot->handle($act('', $BOB, ['value' => ['rivet' => 'reply', 'ticket' => (int) $tk['ticket_id'], 'text' => 'From a Teams card']]), $claim);
$ok($one("SELECT ticket_priority FROM tickets WHERE ticket_id = {$tk['ticket_id']}") === 'Low' && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = {$tk['ticket_id']}") === 3 && $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = {$tk['ticket_id']} AND ticket_reply LIKE '%From a Teams card%'") == 1, 'priority, status and reply card buttons change the ticket');
$bot->handle($act('', $PAT, ['value' => ['rivet' => 'status', 'ticket' => (int) $tk['ticket_id'], 'choice_status' => 'Open']]), $claim);
$ok((int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = {$tk['ticket_id']}") === 3, 'a contact\'s status button does nothing');
$bot->handle($act('', $BOB, ['value' => ['rivet' => 'status', 'ticket' => (int) $tk['ticket_id'], 'choice_status' => 'Closed']]), $claim);
$ok((int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = {$tk['ticket_id']}") === 3, 'a forged "Closed" status choice is refused');
$bot->handle($act('', $BOB, ['value' => ['rivet' => 'delete', 'ticket' => (int) $tk['ticket_id']]]), $claim);
$ok(str_contains($lastText(), 'not valid'), 'an unknown card action is rejected');
// approvals
$q("INSERT INTO service_catalog_items SET catalog_item_id = 2, name = 'VPN access', description = 'x', is_active = 1, requires_approval = 1");
$q("INSERT INTO service_catalog_approval_steps SET catalog_item_id = 2, step_order = 1, approver_type = 'user', approver_id = 11, mode = 'any'");
$q("INSERT INTO tickets SET ticket_prefix = 'T-', ticket_number = 9001, ticket_subject = 'VPN req', ticket_details = 'x', ticket_status = 2, ticket_client_id = 1, ticket_contact_id = 50, ticket_created_by = 0");
$reqTicket = $db->insert_id;
$item2 = $q("SELECT * FROM service_catalog_items WHERE catalog_item_id = 2")->fetch_assoc();
$reqId = (new \ITFlow\ITSM\ServiceCatalogService($db))->submit($item2, $reqTicket, 1, 50, 0, [])['request_id'];
$bot->handle($act('', $PAT, ['value' => ['rivet' => 'approve', 'kind' => 'catalog_request', 'id' => $reqId]]), $claim);
$ok($one("SELECT status FROM service_catalog_requests WHERE request_id = $reqId") === 'pending_approval', 'a contact who is not the approver cannot approve from Teams');
$sent = []; $bot->handle($act('approvals', $BOB), $claim);
$ok(str_contains($lastText(), 'VPN access'), 'the approvals command lists what is waiting for the agent');
$bot->handle($act('', $BOB, ['value' => ['rivet' => 'approve', 'kind' => 'catalog_request', 'id' => $reqId]]), $claim);
$ok($one("SELECT status FROM service_catalog_requests WHERE request_id = $reqId") === 'approved' && str_contains($lastText(), 'Approved'), 'the addressed approver approves from a Teams card');
$ok((bool) array_filter($audits, fn ($x) => $x['e'] === 'chat.approval_decided' && $x['a'] === 11), 'the Teams decision is audited with the approver as actor');
// DM memory + approval prompt over the connector
$ok((int) $one("SELECT COUNT(*) FROM chat_threads WHERE thread_platform = 'teams' AND thread_ref = 'dm:$BOB' AND thread_ticket_id = 0") === 1, 'the personal conversation is remembered so approvals can be sent to the user');
$bad = $act('x', $BOB); $bad['serviceUrl'] = 'https://evil.example.test/'; $bad['id'] = 'evil-1';
$bot->handle($bad, $claim);
ChatNotifier::markOrigin($db, 0);
$ok((string) $one("SELECT thread_service_url FROM chat_threads WHERE thread_ref = 'dm:$BOB'") !== 'https://evil.example.test/', 'a service URL outside the Microsoft allow-list is never stored for proactive messages');
$q("UPDATE chat_threads SET thread_service_url = '$SVCLOCAL' WHERE thread_ref = 'dm:$BOB'");
$q("INSERT INTO tickets SET ticket_prefix = 'T-', ticket_number = 9002, ticket_subject = 'VPN req 2', ticket_details = 'x', ticket_status = 2, ticket_client_id = 1, ticket_contact_id = 50, ticket_created_by = 0"); $rt2 = $db->insert_id;
$reqId2 = (new \ITFlow\ITSM\ServiceCatalogService($db))->submit($item2, $rt2, 1, 50, 0, [])['request_id'];
$mockReset(); $q("DELETE FROM chat_kv");
$n = ChatNotifier::approvalRequested($db, 'catalog_request', $reqId2);
$ml = $mockLog(); $acts = array_values(array_filter($ml, fn ($m) => str_contains($m['path'], '/activities')));
$card = $acts ? json_decode($acts[0]['body'], true)['attachments'][0]['content'] ?? [] : [];
$ok($n === 1 && count($acts) === 1 && ($card['actions'][0]['data']['rivet'] ?? '') === 'approve' && ($card['actions'][1]['data']['rivet'] ?? '') === 'reject' && ($card['actions'][0]['data']['id'] ?? 0) === $reqId2, 'approvalRequested sends an Approve / Deny Adaptive Card to the approver\'s Teams conversation over the connector');
// two-way: a ticket made in Teams keeps talking in that conversation
$setCfg(['config_chat_two_way_enabled' => 1]); $bot = new TeamsBot($db, $auditFn, $sender, null);
$bot->handle($act('new Thread test', $BOB), $claim);
$tt = $q("SELECT * FROM tickets WHERE ticket_subject = 'Thread test'")->fetch_assoc();
$ok((int) $one("SELECT COUNT(*) FROM chat_threads WHERE thread_platform = 'teams' AND thread_ticket_id = {$tt['ticket_id']}") === 1, 'two-way: the Teams conversation is bound to the ticket it created');
$q("UPDATE chat_threads SET thread_service_url = '$SVCLOCAL' WHERE thread_ticket_id = {$tt['ticket_id']}");
$q("INSERT INTO ticket_replies SET ticket_reply = '<p>We are looking</p>', ticket_reply_type = 'Public', ticket_reply_by = 10, ticket_reply_ticket_id = {$tt['ticket_id']}");
$mockReset(); $q("DELETE FROM chat_kv WHERE kv_key = 'teams_token'");
$nposted = ChatNotifier::syncTicket($db, (int) $tt['ticket_id']);
$acts = array_values(array_filter($mockLog(), fn ($m) => str_contains($m['path'], '/activities')));
$ok($nposted === 1 && count($acts) === 1 && str_contains(json_decode($acts[0]['body'], true)['text'], 'Alice Admin') && ChatNotifier::syncTicket($db, (int) $tt['ticket_id']) === 0, 'two-way: a public reply is posted into the Teams conversation once');
// email fallback
$bot2 = new TeamsBot($db, $auditFn, $sender, fn ($aad, $conv, $url) => $aad === $ALICE_EMAIL ? 'alice@x.test' : null);
$sent = []; $bot2->handle($act('status 1', $ALICE_EMAIL), $claim);
$ok(str_contains($lastText(), "isn't linked"), 'email fallback OFF (default): an unlinked Teams user is not matched by email');
$setCfg(['config_teams_link_by_email' => 1]);
$ident = new ChatIdentity($db, new ChatSettings($db), null, fn ($aad, $conv, $url) => $aad === $ALICE_EMAIL ? 'alice@x.test' : null);
$w = $ident->resolve('teams', $TENANT, $ALICE_EMAIL, ['tenant_id' => $TENANT, 'conversation_id' => 'c', 'service_url' => $SVC]);
$ok($w && $w['user_id'] === 10 && $w['via'] === 'email', 'email fallback ON with a pinned tenant: the directory email maps to the one agent');
$ok($ident->resolve('teams', $TENANT, $ALICE_EMAIL, ['tenant_id' => 'other-tenant', 'conversation_id' => 'c', 'service_url' => $SVC]) === null, 'email fallback needs the request tenant to equal the pinned tenant');
$setCfg(['config_teams_tenant_id' => '']);
$ok((new ChatIdentity($db, new ChatSettings($db), null, fn () => 'alice@x.test'))->resolve('teams', '', $ALICE_EMAIL, ['tenant_id' => '', 'conversation_id' => 'c']) === null, 'email fallback never works without a pinned tenant');
$setCfg(['config_teams_tenant_id' => $TENANT, 'config_teams_link_by_email' => 0]);

// ======================================================= 6. Teams app package
$m = TeamsPackage::manifest('https://help.example.test', 'RivetIT', $APP);
$ok($m['manifestVersion'] === '1.17' && $m['id'] === $APP && $m['bots'][0]['botId'] === $APP && $m['validDomains'] === ['help.example.test'] && in_array('personal', $m['bots'][0]['scopes']), 'manifest: version, bot id, scopes and valid domain');
$thrown = 0; foreach ([['http://x.test', $APP], ['https://x.test', 'not-a-guid']] as [$u, $id]) { try { TeamsPackage::manifest($u, 'x', $id); } catch (\InvalidArgumentException $e) { $thrown++; } }
$ok($thrown === 2, 'manifest refuses a non-https base URL and a non-GUID App ID');
$zip = TeamsPackage::zip('https://help.example.test', 'RivetIT', $APP);
$tmp = tempnam(sys_get_temp_dir(), 'z'); file_put_contents($tmp, $zip); $za = new ZipArchive(); $za->open($tmp);
$names = []; for ($i = 0; $i < $za->numFiles; $i++) $names[] = $za->getNameIndex($i);
sort($names);
$c = $za->getFromName('color.png'); $o = $za->getFromName('outline.png'); $mj = json_decode((string) $za->getFromName('manifest.json'), true);
$ok($names === ['color.png', 'manifest.json', 'outline.png'] && is_array($mj) && $mj['icons'] === ['outline' => 'outline.png', 'color' => 'color.png'], 'package zip holds manifest.json, color.png and outline.png and the manifest references them');
$ok(unpack('N2', substr($c, 16, 8)) === [1 => 192, 2 => 192] && unpack('N2', substr($o, 16, 8)) === [1 => 32, 2 => 32], 'icons are 192x192 and 32x32 PNGs');
$za->close(); @unlink($tmp);

// ======================================================= 7. the real /teams_bot.php over HTTP
$cfgFile = __DIR__ . '/../config.php';
if (!(is_file($cfgFile) && str_contains((string) file_get_contents($cfgFile), getenv('RIVETIT_TEST_DB_NAME')))) {
    echo "SKIP  HTTP endpoint (config.php does not point at the scratch database)\n";
} else {
    $prep = sys_get_temp_dir() . '/chat_teams_prepend_' . getmypid() . '.php';
    file_put_contents($prep, "<?php define('RIVETIT_CHAT_ALLOW_LOCAL_HTTP', true); define('RIVETIT_TEAMS_OPENID_URL', 'http://127.0.0.1:$port/teams/openid'); define('RIVETIT_TEAMS_TOKEN_URL', 'http://127.0.0.1:$port/teams/token');\n");
    $realEnc = fn (string $v): string => trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/chat_enc.php') . ' ' . escapeshellarg($v)));
    $setCfg(['config_teams_app_secret' => $realEnc('client-secret-value-123'), 'config_teams_bot_enabled' => 1, 'config_chat_commands_enabled' => 1]);
    $q("DELETE FROM chat_kv"); $q("DELETE FROM slack_interactive_seen");
    $hp = random_int(41000, 49000);
    $hproc = proc_open([PHP_BINARY, '-d', "auto_prepend_file=$prep", '-S', "127.0.0.1:$hp", '-t', realpath(__DIR__ . '/..')], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $hp2, realpath(__DIR__ . '/..'), ['PATH' => getenv('PATH')]);
    register_shutdown_function(function () use ($hproc, $prep) { proc_terminate($hproc); @unlink($prep); });
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $hp)) break; usleep(100000); }
    $post = function (array $a, ?string $auth) use ($hp): int {
        $ch = curl_init("http://127.0.0.1:$hp/teams_bot.php");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($a), CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', $auth !== null ? "Authorization: $auth" : null]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '']);
        curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return $c;
    };
    // real time tokens: the endpoint uses the real clock
    $realNow = time();
    $tokReal = JWT::encode(['iss' => 'https://api.botframework.com', 'aud' => $APP, 'serviceurl' => $SVCLOCAL, 'iat' => $realNow - 5, 'nbf' => $realNow - 5, 'exp' => $realNow + 3000], $K1['priv'], 'RS256', 'kid1');
    $httpAct = $act('help', $BOB) ; $httpAct['serviceUrl'] = $SVCLOCAL; $httpAct['id'] = 'http-act-1';
    $ok($post($httpAct, null) === 401 && $post($httpAct, 'Bearer garbage.garbage.garbage') === 401, 'HTTP /teams_bot.php: no token or a garbage token gets 401');
    $badTok = JWT::encode(['iss' => 'https://api.botframework.com', 'aud' => $APP, 'serviceurl' => $SVCLOCAL, 'iat' => $realNow - 5, 'nbf' => $realNow - 5, 'exp' => $realNow + 3000], $K2['priv'], 'RS256', 'kid1');
    $ok($post($httpAct, "Bearer $badTok") === 401, 'HTTP /teams_bot.php: a token signed by an untrusted key gets 401');
    $gc = curl_init("http://127.0.0.1:$hp/teams_bot.php"); curl_setopt_array($gc, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '']); curl_exec($gc); $g = curl_getinfo($gc, CURLINFO_RESPONSE_CODE); curl_close($gc);
    $ok($g === 405, 'HTTP /teams_bot.php: GET gets 405');
    $mockReset();
    $ok($post($httpAct, "Bearer $tokReal") === 200, 'HTTP /teams_bot.php: a validly signed activity is accepted (200)');
    $got = false; for ($i = 0; $i < 60 && !$got; $i++) { usleep(100000); $got = (bool) array_filter($mockLog(), fn ($m) => str_contains($m['path'], '/activities')); }
    $ok($got, 'HTTP /teams_bot.php: the bot answered through the (mock) connector with a bearer token obtained from the (mock) token endpoint');
    $ok($post($httpAct, "Bearer $tokReal") === 200 && count(array_filter($mockLog(), fn ($m) => str_contains($m['path'], '/activities'))) === 1, 'HTTP /teams_bot.php: the same activity id delivered twice is answered once');
    $setCfg(['config_teams_bot_enabled' => 0]);
    $ok($post($act('help', $BOB) + [], "Bearer $tokReal") === 401, 'HTTP /teams_bot.php: with the bot switched OFF even a valid token gets 401');
}

echo "\n$count checks, $fail failed\n";
exit($fail ? 1 : 0);
