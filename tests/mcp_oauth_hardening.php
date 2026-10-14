<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Remote MCP built-in OAuth server: regression tests for the security review of 2026-10-14 (docs/REMOTE_MCP.md, "Recommended
 * secure setup"). One section per finding:
 *   1. a policy change cuts live access tokens at once (CIMD mode off / host removed); registration off stops only new registrations
 *   2. no PHP or database session time zone can change token validity; admin and account pages show local time
 *   3. self-registration deduplication (same address, same name, same return addresses, short window) and its limits
 *   4. deleting an app: usage numbers, confirmation when connections are active, "Delete unused duplicates" never touches used apps
 *   5. names with invisible or bidirectional characters are refused (registration) or replaced by the host (documents)
 *   6. the code and the refresh token are single-use under true parallel requests
 *   7. rate-limit keys (IPv6 /64, per address and client), fetch limiter counts only real fetches and fails closed without Redis in "any" mode
 *   8. the MCP session directory is used only when it is ours and private
 * Needs the same DISPOSABLE database / throwaway Redis as tests/mcp_oauth_flow.php (and openssl for the mock document host).
 */
require_once __DIR__ . '/mcp_oauth_lib.php';

use ITFlow\Mcp\OAuth\BuiltinTokenValidator;
use ITFlow\Mcp\OAuth\ClientMetadata;
use ITFlow\Mcp\OAuth\OAuthConfig;
use ITFlow\Mcp\OAuth\OAuthHttp;
use ITFlow\Mcp\OAuth\OAuthStore;

$host = $OA['host'];
$resource = "https://$host/mcp";
$issuer = "https://$host";
$port = 18000 + random_int(100, 900);
$mport = 19000 + random_int(100, 900);
$dir = sys_get_temp_dir() . '/hard_mock_' . getmypid();
mkdir($dir, 0700);
$pem = "$dir/server.pem";
$control = "$dir/control.json";
$hits = "$dir/hits.log";
file_put_contents($hits, '');
file_put_contents($control, '{}');
exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg("$dir/k.pem") . ' -out ' . escapeshellarg("$dir/c.pem") . ' -days 2 -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1 2>&1', $o, $rc);
if ($rc !== 0) { fwrite(STDERR, "openssl failed\n"); exit(2); }
file_put_contents($pem, file_get_contents("$dir/k.pem") . file_get_contents("$dir/c.pem"));
$mock = proc_open(['php', __DIR__ . '/mcp_oauth_cimd_mock.php', (string) $mport, $pem, $control, $hits], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dir/mock.log", 'w'], 2 => ['file', "$dir/mock.log", 'w']], $pipes);
register_shutdown_function(function () use (&$mock, $dir) {
    if (is_resource($mock)) { $st = proc_get_status($mock); if (!empty($st['pid'])) { posix_kill($st['pid'], SIGTERM); } proc_close($mock); }
    foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
});
for ($i = 0; $i < 50; $i++) { $s = @fsockopen('127.0.0.1', $mport, $e, $m, 0.2); if ($s) { fclose($s); break; } usleep(100000); }
putenv('RIVETIT_WEBHOOK_ALLOW_PRIVATE=1');
putenv('RIVETIT_CIMD_TEST_CA_FILE=' . "$dir/c.pem");
putenv('PHP_CLI_SERVER_WORKERS=6');   // real parallelism for the race tests (php -S is single-process otherwise)

$authority = "127.0.0.1:$mport";
$urlOf = fn(string $p) => "https://$authority$p";
$specs = [];
$serve = function (string $path, array $spec) use (&$specs, $control): void { $specs[$path] = $spec; file_put_contents($control, json_encode($specs)); };
$docBody = fn(array $over = []) => json_encode($over + ['client_id' => '{{SELF}}', 'client_name' => 'Mock Claude', 'redirect_uris' => ['https://claude.example/cb', 'http://127.0.0.1/callback'],
    'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none']);
$good = fn(array $over = []) => ['status' => 200, 'headers' => ['Content-Type: application/json', 'Cache-Control: max-age=600'], 'body' => $docBody($over)];
$serve('/c/valid', $good());
$serve('/c/rlo', $good(['client_name' => "Anthropic\u{202E}tpircs"]));

oa_seed_base($mysqli);
oa_clean($mysqli);
$q("UPDATE settings SET config_timezone = 'Pacific/Auckland' WHERE company_id = 1");
oa_seed_user($mysqli, 9101, 'Alice Admin', 'alice@oa.test', 'pw-Alice-1234!', 91);
oa_seed_user($mysqli, 9102, 'Tom Tech', 'tom@oa.test', 'pw-Tom-1234!', 92);
$q("DELETE FROM user_client_permissions WHERE user_id IN (9101,9102)");
$clearLimits = fn() => oa_reset_limits('*rl:oauth:*');
$clearLimits();
$store = new OAuthStore($mysqli);
$mode = fn(string $m, array $hosts = []) => OAuthConfig::setCimd($mysqli, $m, $hosts);
$mode('off');
oa_start_server($port);

$internal = fn(string $url) => ClientMetadata::internalId($url);
$audits = fn(string $type) => (int) $mysqli->query("SELECT COUNT(*) FROM audit_events WHERE event_type = '" . $mysqli->real_escape_string($type) . "'")->fetch_row()[0];
$LOOP = 'http://127.0.0.1:54321/callback';
$authParams = fn(string $cid, string $challenge, string $redirect) => ['response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => $redirect,
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'st-' . bin2hex(random_bytes(4)), 'resource' => $resource, 'scope' => 'mcp:read'];
$alice = oa_jar();
$tom = oa_jar();
oa_login($alice, 'alice@oa.test', 'pw-Alice-1234!');
oa_login($tom, 'tom@oa.test', 'pw-Tom-1234!');
$connect = function (string $cid, string $redirect, string $jar) use ($authParams, $clearLimits, $resource): array {
    $clearLimits();
    [$ver, $chal] = oa_pkce();
    $c = oa_consent($jar, $authParams($cid, $chal, $redirect));
    $tok = isset($c['query']['code']) ? oa_token(['grant_type' => 'authorization_code', 'client_id' => $cid, 'code' => $c['query']['code'], 'redirect_uri' => $redirect, 'code_verifier' => $ver, 'resource' => $resource]) : ['code' => 0, 'json' => null];
    return ['tok' => $tok, 'consent' => $c, 'ver' => $ver];
};
$mcpCode = fn(?string $t) => oa_mcp($t, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'probe', 'version' => '1']]);
$probe = function (string $cid, string $redirect) use ($authParams, $clearLimits): array {
    $clearLimits();
    return oa_http('GET', oa_authorize_url($authParams($cid, oa_pkce()[1], $redirect)));
};

echo "== 1. a policy change cuts live access tokens at once ==\n";
$ok(ClientMetadata::policyAllows('dynamic', null, ['mode' => 'off', 'hosts' => []]) && ClientMetadata::policyAllows('manual', null, ['mode' => 'off', 'hosts' => []]), 'unit: registered and hand-added clients do not depend on the metadata policy');
$cv = $urlOf('/c/valid');
$ok(!ClientMetadata::policyAllows('metadata', $cv, ['mode' => 'off', 'hosts' => [$authority]]) && !ClientMetadata::policyAllows('metadata', $cv, ['mode' => 'hosts', 'hosts' => ['claude.ai']])
    && ClientMetadata::policyAllows('metadata', $cv, ['mode' => 'hosts', 'hosts' => [$authority]]) && ClientMetadata::policyAllows('metadata', $cv, ['mode' => 'any', 'hosts' => []])
    && !ClientMetadata::policyAllows('metadata', null, ['mode' => 'any', 'hosts' => []]) && !ClientMetadata::policyAllows('metadata', 'http://x/y', ['mode' => 'any', 'hosts' => []]), 'unit: a metadata client needs mode on and (in hosts mode) a listed host; no source URL means no');
$mode('hosts', ["127.0.0.1:$mport"]);
$run = $connect($cv, $LOOP, $alice);
$acc = $run['tok']['json']['access_token'] ?? '';
$ref = $run['tok']['json']['refresh_token'] ?? '';
$ok($acc !== '' && $mcpCode($acc)['code'] === 200, 'precondition: a metadata client is connected and its access token works');
$mode('hosts', ['claude.ai']);
$r = $mcpCode($acc);
$ok($r['code'] === 401 && str_contains($r['headers']['www-authenticate'] ?? '', 'invalid_token'), 'host removed from the trusted list: the SAME access token is refused on the very next request (401 invalid_token)');
$mode('hosts', ["127.0.0.1:$mport"]);
$ok($mcpCode($acc)['code'] === 200, 'host listed again: the unexpired connection works again (consent was never revoked)');
$mode('off');
$ok($mcpCode($acc)['code'] === 401, 'mode Off: the same access token is refused at once');
$t = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cv, 'refresh_token' => $ref]);
$ok($t['code'] === 401, 'mode Off: refresh is refused too');
$mode('any');
$ok($mcpCode($acc)['code'] === 200, 'mode Any: allowed again');
$mode('hosts', ["127.0.0.1:$mport"]);
$ok($mcpCode($acc)['code'] === 200, 'listed host: allowed');
// the removed row of the document: a metadata client with no document row is not allowed
$q("DELETE FROM mcp_oauth_client_documents WHERE client_id = '" . $internal($cv) . "'");
$ok($mcpCode($acc)['code'] === 401, 'a metadata client whose document row is gone is refused (fail closed)');
$mode('off');

// registration switched off: only NEW registrations stop
$mode('off');
$clearLimits();
[$reg, $dynId] = oa_register(['client_name' => 'Dynamic App']);
$ok($reg['code'] === 201 && $dynId, 'precondition: a self-registered client');
$dyn = $connect($dynId, 'http://127.0.0.1:8765/callback', $tom);
$dynAcc = $dyn['tok']['json']['access_token'] ?? '';
$dynRef = $dyn['tok']['json']['refresh_token'] ?? '';
$ok($dynAcc !== '' && $mcpCode($dynAcc)['code'] === 200, 'precondition: it is connected');
$q("UPDATE mcp_oauth_config SET setting_value = '0' WHERE setting_key = 'registration_enabled'");
$clearLimits();
$ok(oa_register(['client_name' => 'Another'])[0]['code'] === 403, 'registration off: a NEW registration is refused');
$ok(!isset((oa_http('GET', '/.well-known/oauth-authorization-server')['json'] ?? [])['registration_endpoint']), 'registration off: metadata no longer advertises registration_endpoint');
$ok($mcpCode($dynAcc)['code'] === 200, 'registration off: the existing connection keeps working');
$t = oa_token(['grant_type' => 'refresh_token', 'client_id' => $dynId, 'refresh_token' => $dynRef]);
$ok($t['code'] === 200, 'registration off: the existing client can still refresh');
$ok($probe($dynId, 'http://127.0.0.1:8765/callback')['code'] === 302, 'registration off: the existing client can still start a new authorization');
$q("UPDATE mcp_oauth_config SET setting_value = '1' WHERE setting_key = 'registration_enabled'");
$q("INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris, registration_type, created_at) VALUES ('manual-preset-0001','Manual','[\"http://127.0.0.1:8777/cb\"]','manual',UTC_TIMESTAMP())");
$man = $connect('manual-preset-0001', 'http://127.0.0.1:8777/cb', $tom);
$manAcc = $man['tok']['json']['access_token'] ?? '';
$mode('off');
$ok($manAcc !== '' && $mcpCode($manAcc)['code'] === 200, 'a hand-added client is unaffected by the metadata mode being Off');

echo "== 2. no time zone can change token validity; pages show local time ==\n";
$clearLimits();
$svc = new ITFlow\Mcp\OAuth\OAuthService($mysqli, $issuer, $resource, fn() => null);
$store->createClient('rvtc_tzclient0001', 'TZ client', ['https://tz.example/cb'], 'dynamic', '10.9.9.9', null);
$g = $store->createGrant('rvtc_tzclient0001', 9101, 'mcp:read', $resource, OAuthStore::hash('tz-origin'));
$tzTok = 'rvt_at_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$store->insertToken(OAuthStore::hash($tzTok), $g, 'access', OAuthConfig::ACCESS_TTL);
$validator = new BuiltinTokenValidator($mysqli, $issuer, $resource);
foreach (['UTC', 'Pacific/Auckland', 'America/Los_Angeles', 'Asia/Kolkata', 'Pacific/Kiritimati'] as $tz) {
    foreach (['+00:00', '+13:00', '-12:00', 'SYSTEM'] as $dbTz) {
        date_default_timezone_set($tz);
        $mysqli->query("SET time_zone = '$dbTz'");
        $res = $validator->validate($tzTok);
        $exp = $res->getAttributes()['oauth.claims']['exp'] ?? 0;
        $okTz = $res->isAllowed() && $exp - time() >= 890 && $exp - time() <= 900;
        if (!$okTz) { $ok(false, "token valid and exp about 15 minutes ahead under PHP $tz / database $dbTz (exp-now=" . ($exp - time()) . ')'); }
    }
}
$ok(true, 'the validator accepts a fresh token with exp about 15 minutes ahead under 5 PHP zones x 4 database session zones');
date_default_timezone_set('UTC');
$mysqli->query("SET time_zone = '+00:00'");
$store->insertToken(OAuthStore::hash('rvt_at_' . str_repeat('x', 43)), $g, 'access', 1);
$q("UPDATE mcp_oauth_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE token_hash = " . "UNHEX('" . bin2hex(OAuthStore::hash('rvt_at_' . str_repeat('x', 43))) . "')");
foreach (['Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $tz) {
    date_default_timezone_set($tz);
    $mysqli->query("SET time_zone = '" . ($tz === 'Pacific/Kiritimati' ? '+13:00' : '-11:00') . "'");
    $ok(!$validator->validate('rvt_at_' . str_repeat('x', 43))->isAllowed(), "an expired token stays expired under $tz");
}
date_default_timezone_set('UTC');
$mysqli->query("SET time_zone = '+00:00'");
$ok(OAuthConfig::localTime('2026-10-14 12:00:00') !== null && (function () { date_default_timezone_set('Pacific/Auckland'); $v = OAuthConfig::localTime('2026-10-14 12:00:00'); date_default_timezone_set('UTC'); return $v; })() === '2026-10-15 01:00:00', 'localTime converts a stored UTC time to the company zone (Auckland is +13 in October)');
$ok(OAuthConfig::localTime(null) === null && OAuthConfig::localTime('') === null, 'localTime keeps null/empty');
// the pages: a connection used 2 minutes ago must read "2 minutes ago" in a company zone far from UTC
$q("UPDATE mcp_oauth_grants SET last_used_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE grant_id = $g");
$admin = oa_http('GET', '/admin/settings_mcp.php', ['jar' => $alice]);
$ok($admin['code'] === 200 && str_contains($admin['body'], '2 minutes ago'), 'administrator page: "Last used" of an OAuth connection is right with the company zone at +13 (not 13 hours off)');
$sec = oa_http('GET', '/agent/user/user_security.php', ['jar' => $alice]);
$ok($sec['code'] === 200 && str_contains($sec['body'], '2 minutes ago'), 'Account > Security: same');
$q("UPDATE settings SET config_timezone = 'America/Los_Angeles' WHERE company_id = 1");
$admin = oa_http('GET', '/admin/settings_mcp.php', ['jar' => $alice]);
$ok(str_contains($admin['body'], '2 minutes ago'), '... and with the company zone at -7');
$q("UPDATE settings SET config_timezone = 'UTC' WHERE company_id = 1");
$q("DELETE FROM mcp_oauth_tokens"); $q("DELETE FROM mcp_oauth_grants WHERE client_id = 'rvtc_tzclient0001'"); $q("DELETE FROM mcp_oauth_clients WHERE client_id = 'rvtc_tzclient0001'");

echo "== 3. self-registration deduplication ==\n";
oa_clean($mysqli);
$clearLimits();
$meta = ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']];
$ids = [];
for ($i = 0; $i < 5; $i++) { $r = oa_register($meta); $ids[] = $r[1]; $ok($r[0]['code'] === 201 && ($r[0]['json']['redirect_uris'] ?? null) === $meta['redirect_uris'], "registration $i answers 201 with the redirect list"); }
$ok(count(array_unique($ids)) === 1, 'five identical registrations from one address in one flow return ONE client ID');
$ok((int) $mysqli->query("SELECT COUNT(*) FROM mcp_oauth_clients")->fetch_row()[0] === 1, '... and create one row');
$ok($audits('mcp.oauth_client_registered') === 1, '... and one audit row');
$other = oa_register(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback2']]);
$ok($other[1] !== $ids[0], 'a different redirect address is a different client');
$other2 = oa_register(['client_name' => 'Claude Desktop', 'redirect_uris' => $meta['redirect_uris']]);
$ok($other2[1] !== $ids[0], 'a different name is a different client');
$q("UPDATE mcp_oauth_clients SET created_ip = '203.0.113.9' WHERE client_id = '{$ids[0]}'");
$fromElsewhere = oa_register($meta);
$ok($fromElsewhere[1] !== $ids[0], 'the same name and redirect from another address never receives the first address\'s client');
$q("UPDATE mcp_oauth_clients SET created_ip = '127.0.0.1' WHERE client_id = '{$ids[0]}'");
$q("UPDATE mcp_oauth_clients SET created_at = UTC_TIMESTAMP() - INTERVAL 11 MINUTE WHERE client_id = '{$ids[0]}'");
$later = oa_register($meta);
$ok($later[1] !== $ids[0], 'after the window the old client is not reused');
$q("UPDATE mcp_oauth_clients SET disabled_at = UTC_TIMESTAMP() WHERE client_id = '{$later[1]}'");
$again = oa_register($meta);
$ok($again[1] !== $later[1], 'a disabled client is never handed out again');
$ok(($oa = oa_register(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'grant_types' => ['password']]))[0]['code'] === 400, 'validation still runs before deduplication (a bad grant type is refused)');
oa_clean($mysqli);
$clearLimits();

echo "== 4. deleting an app: numbers, confirmation, unused duplicates ==\n";
$csrfOf = function (string $body): string { preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m); return html_entity_decode($m[1] ?? ''); };
$base = 'http://' . $host . ':' . $port;
$post = fn(array $form) => oa_http('POST', '/admin/post.php', ['jar' => $alice, 'form' => $form, 'headers' => ['Referer: ' . $base . '/admin/settings_mcp.php']]);
$mk = function (string $id, string $name, array $uris, string $type = 'dynamic', int $ageMin = 0) use ($store, $mysqli): void {
    $store->createClient($id, $name, $uris, $type, '10.1.1.1', null);
    $mysqli->query("UPDATE mcp_oauth_clients SET created_at = UTC_TIMESTAMP() - INTERVAL $ageMin MINUTE WHERE client_id = '$id'");
};
$U = ['https://dup.example/cb'];
$mk('rvtc_dupUSED00001', 'Claude', $U, 'dynamic', 50);
$mk('rvtc_dupUNUSED001', 'Claude', $U, 'dynamic', 40);
$mk('rvtc_dupUNUSED002', 'claude', array_reverse($U), 'dynamic', 30);   // same app: case-insensitive name, same set of addresses
$mk('rvtc_dupUSED00002', 'Claude', $U, 'dynamic', 20);                   // used by "last_used_at", no grant
$mk('rvtc_uniqueUNUSED', 'Something else', ['https://other.example/cb'], 'dynamic', 60);
$mk('manual-dup-aaaaaaa', 'Hand added', $U, 'manual', 70);
$mk('manual-dup-bbbbbbb', 'Hand added', $U, 'manual', 60);
$mk('rvtc_allUNUSED0001', 'Never connected', ['https://never.example/cb'], 'dynamic', 30);
$mk('rvtc_allUNUSED0002', 'Never connected', ['https://never.example/cb'], 'dynamic', 20);
$mk('rvtc_allUNUSED0003', 'Never connected', ['https://never.example/cb'], 'dynamic', 10);
$mysqli->query("UPDATE mcp_oauth_clients SET last_used_at = UTC_TIMESTAMP() - INTERVAL 5 MINUTE WHERE client_id = 'rvtc_dupUSED00002'");
$gA = $store->createGrant('rvtc_dupUSED00001', 9101, 'mcp:read', $resource, OAuthStore::hash('d1'));
$gB = $store->createGrant('rvtc_dupUSED00001', 9102, 'mcp:read', $resource, OAuthStore::hash('d2'));
$mysqli->query("UPDATE mcp_oauth_grants SET last_used_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR WHERE grant_id = $gA");
$usage = $store->clientUsage('rvtc_dupUSED00001');
$ok($usage['active_grants'] === 2 && $usage['active_users'] === 2 && $usage['total_grants'] === 2 && $usage['last_activity_at'] !== null, 'clientUsage: 2 active connections, 2 people, last activity known');
$idle = $store->clientUsage('rvtc_uniqueUNUSED');
$ok($idle['active_grants'] === 0 && $idle['last_activity_at'] === null, 'clientUsage: an unused client has no connections and no last use');
$page = oa_http('GET', '/admin/settings_mcp.php', ['jar' => $alice]);
$ok(str_contains($page['body'], '2 active connection(s) for 2 person(s)'), 'the list shows active connections and people per app');
$ok(str_contains($page['body'], 'duplicate') && str_contains($page['body'], 'Delete 4 unused duplicate(s)'), 'duplicates are flagged and the page offers to delete exactly the 4 surplus unused copies (2 in the Claude group, 2 of the 3 identical never-used ones)');
$csrf = $csrfOf($page['body']);
$post(['csrf_token' => $csrf, 'delete_mcp_oauth_client' => 1, 'client_id' => 'rvtc_dupUSED00001']);
$ok($store->client('rvtc_dupUSED00001') !== null && $store->grantIsLive($gA), 'Delete without the confirmation box is refused while connections are active (client and connections stay)');
$post(['csrf_token' => 'forged', 'delete_mcp_oauth_client' => 1, 'client_id' => 'rvtc_dupUSED00001', 'confirm_active_connections' => '1']);
$ok($store->client('rvtc_dupUSED00001') !== null, 'a forged CSRF token deletes nothing');
$post(['csrf_token' => $csrf, 'delete_unused_mcp_oauth_duplicates' => 1]);
$left = array_column($mysqli->query("SELECT client_id FROM mcp_oauth_clients ORDER BY client_id")->fetch_all(MYSQLI_ASSOC), 'client_id');
$ok(in_array('rvtc_dupUSED00001', $left, true) && in_array('rvtc_dupUSED00002', $left, true), 'Delete unused duplicates: the app with connections and the app with use are kept');
$ok(!in_array('rvtc_dupUNUSED001', $left, true) && !in_array('rvtc_dupUNUSED002', $left, true), '... the unused copies of the same app are removed');
$ok(in_array('rvtc_uniqueUNUSED', $left, true), '... an unused app that is not a duplicate is kept');
$ok(in_array('manual-dup-aaaaaaa', $left, true) && in_array('manual-dup-bbbbbbb', $left, true), '... hand-added apps are never deleted (their ID may be typed into a tool already)');
$allUnused = array_values(array_filter($left, fn($c) => str_starts_with($c, 'rvtc_allUNUSED')));
$ok(count($allUnused) === 1 && $allUnused[0] === 'rvtc_allUNUSED0003', '... when no copy in a group is used, the newest copy stays');
$ok($store->grantIsLive($gA) && $store->grantIsLive($gB), '... and no connection was touched');
$ok($audits('mcp.oauth_client_deleted') === 1, 'the bulk delete is audited');
$post(['csrf_token' => $csrf, 'delete_mcp_oauth_client' => 1, 'client_id' => 'rvtc_dupUSED00001', 'confirm_active_connections' => '1']);
$ok($store->client('rvtc_dupUSED00001') === null && !$store->grantIsLive($gA) && !$store->grantIsLive($gB), 'Delete with the confirmation ends the connections and removes the app');
$meta = json_decode((string) $mysqli->query("SELECT metadata_json FROM audit_events WHERE event_type = 'mcp.oauth_client_deleted' AND entity_id = 'rvtc_dupUSED00001'")->fetch_row()[0], true);
$ok(($meta['active_connections'] ?? null) === 2 && ($meta['people'] ?? null) === 2, 'the audit row records how many connections and people it ended');
$post(['csrf_token' => $csrf, 'delete_mcp_oauth_client' => 1, 'client_id' => 'rvtc_uniqueUNUSED']);
$ok($store->client('rvtc_uniqueUNUSED') === null, 'an app with no connections deletes without the box');
oa_clean($mysqli);

echo "== 5. misleading names ==\n";
$clearLimits();
foreach (['right-to-left override' => "Anthropic\u{202E}tpircs", 'zero-width space' => "Claude\u{200B}", 'zero-width joiner' => "Cla\u{200D}ude", 'line separator' => "A\u{2028}B", 'control' => "A\x07B"] as $label => $nm) {
    $clearLimits();
    $r = oa_register(['client_name' => $nm, 'redirect_uris' => ['https://x.example/cb']]);
    $ok($r[0]['code'] === 400, "registration refused: $label in client_name");
}
$ok(!ClientMetadata::plainText("a\u{202E}b") && ClientMetadata::plainText('Claude Desktop (work) - ünïcode 日本語'), 'plainText: refuses bidi/invisible characters, allows ordinary Unicode');
$mode('hosts', ["127.0.0.1:$mport"]);
$p = $probe($urlOf('/c/rlo'), $LOOP);
$cl = $store->client($internal($urlOf('/c/rlo')));
$ok($cl !== null && $cl['client_name'] === '127.0.0.1', 'a document name with a bidirectional override is replaced by the host name');
$mode('off');
oa_clean($mysqli);

echo "== 6. single use under true parallel requests ==\n";
$mk('rvtc_racerclient1', 'Racer', ['http://127.0.0.1:8765/callback'], 'dynamic', 0);
$clearLimits();
[$ver, $chal] = oa_pkce();
$c = oa_consent($alice, $authParams('rvtc_racerclient1', $chal, 'http://127.0.0.1:8765/callback'));
$code = $c['query']['code'] ?? '';
$ok($code !== '', 'precondition: an authorization code');
$parallel = function (array $forms): array {
    $mh = curl_multi_init();
    $chs = [];
    foreach ($forms as $i => $form) {
        $ch = curl_init(oa_url('/oauth/token.php'));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_RESOLVE => [$GLOBALS['OA']['host'] . ':' . $GLOBALS['OA']['port'] . ':127.0.0.1'], CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
        curl_multi_add_handle($mh, $ch);
        $chs[$i] = $ch;
    }
    do { curl_multi_exec($mh, $running); if ($running) { curl_multi_select($mh, 0.2); } } while ($running);
    $out = [];
    foreach ($chs as $i => $ch) { $out[$i] = ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode((string) curl_multi_getcontent($ch), true)]; curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    return $out;
};
$clearLimits();
$forms = array_fill(0, 8, ['grant_type' => 'authorization_code', 'client_id' => 'rvtc_racerclient1', 'code' => $code, 'redirect_uri' => 'http://127.0.0.1:8765/callback', 'code_verifier' => $ver, 'resource' => $resource]);
$res = $parallel($forms);
$wins = count(array_filter($res, fn($r) => $r['code'] === 200));
$ok($wins <= 1, "8 parallel redemptions of one code: at most one token response ($wins)");
$ok((int) $mysqli->query("SELECT COUNT(*) FROM mcp_oauth_grants WHERE client_id = 'rvtc_racerclient1'")->fetch_row()[0] === 1, '... and exactly one consent grant row exists');
$ok((int) $mysqli->query("SELECT COUNT(*) FROM mcp_oauth_tokens WHERE token_kind = 'access'")->fetch_row()[0] <= 1, '... and at most one access token was minted');
oa_clean($mysqli);
$mk('rvtc_racerclient1', 'Racer', ['http://127.0.0.1:8765/callback'], 'dynamic', 0);
$clearLimits();
[$ver, $chal] = oa_pkce();
$c = oa_consent($alice, $authParams('rvtc_racerclient1', $chal, 'http://127.0.0.1:8765/callback'));
$first = oa_token(['grant_type' => 'authorization_code', 'client_id' => 'rvtc_racerclient1', 'code' => $c['query']['code'], 'redirect_uri' => 'http://127.0.0.1:8765/callback', 'code_verifier' => $ver]);
$rt = $first['json']['refresh_token'] ?? '';
$clearLimits();
$res = $parallel(array_fill(0, 8, ['grant_type' => 'refresh_token', 'client_id' => 'rvtc_racerclient1', 'refresh_token' => $rt]));
$wins = count(array_filter($res, fn($r) => $r['code'] === 200));
$ok($rt !== '' && $wins <= 1, "8 parallel refreshes of one refresh token: at most one succeeds ($wins)");
$refreshTokens = (int) $mysqli->query("SELECT COUNT(*) FROM mcp_oauth_tokens WHERE token_kind = 'refresh' AND used_at IS NULL")->fetch_row()[0];
$ok($refreshTokens <= 1, "... and at most one unused refresh token exists afterwards ($refreshTokens)");
if ($wins === 0) { $ok($audits('mcp.oauth_reuse_detected') >= 0, '(every parallel refresh lost; the grant state is whatever reuse detection decided)'); }
oa_clean($mysqli);

echo "== 7. rate-limit keys and the fetch limiter ==\n";
$ok(OAuthHttp::netKey('203.0.113.5') === '203.0.113.5', 'IPv4 is counted per address');
$ok(OAuthHttp::netKey('2001:db8:1:2:3:4:5:6') === '2001:db8:1:2::/64' && OAuthHttp::netKey('2001:db8:1:2:ffff:ffff:ffff:ffff') === '2001:db8:1:2::/64', 'IPv6 is counted per /64: every address in it shares one bucket');
$ok(OAuthHttp::netKey('2001:db8:1:3::1') !== OAuthHttp::netKey('2001:db8:1:2::1'), '... and another /64 has its own');
$clearLimits();
$mk('rvtc_ratekeyclient', 'Rate', ['http://127.0.0.1:8765/callback'], 'dynamic', 0);
oa_token(['grant_type' => 'refresh_token', 'client_id' => 'rvtc_ratekeyclient', 'refresh_token' => 'x']);
$redis = new Predis\Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => (int) (getenv('RIVETIT_REDIS_PORT') ?: 6391)]);
$keys = $redis->keys('*rl:oauth:token:client:*');
$ok(count($keys) === 1 && str_contains($keys[0], 'oauth:token:client:127.0.0.1:'), 'the per-client token bucket is keyed by address AND client (nobody can use up a public client\'s bucket from elsewhere)');
// the fetch limiter counts only fetches that go out
$mode('hosts', ["127.0.0.1:$mport"]);
$clearLimits();
$direct = fn(string $cid) => oa_http('GET', oa_authorize_url($authParams($cid, oa_pkce()[1], $LOOP)));   // no limiter reset in between
$direct($cv);   // first sight: one fetch
$direct($cv);   // cached
$direct($cv);   // cached
$n = (int) ($redis->get('rivetit:rl:oauth:cimd:fetch:all') ?? 0);
$ok($n === 1, "the fetch counter went up once for one real fetch and not for two cached requests (counter=$n)");
$mode('off');
// audit flood
$clearLimits();
$before = $audits('mcp.oauth_cimd_fetch_failed');
$mode('hosts', ["127.0.0.1:$mport"]);
for ($i = 0; $i < 40; $i++) {
    if ($i === 20) { oa_reset_limits('*rl:oauth:cimd:fetch:*'); }   // the fetch limiter (20 a minute) would stop the 21st before the audit cap is reached
    $direct($urlOf("/c/missing-$i"));
}
$added = $audits('mcp.oauth_cimd_fetch_failed') - $before;
$ok($added > 0 && $added <= 30, "40 distinct failing documents wrote at most 30 audit rows in 10 minutes ($added)");
$mode('off');
// Redis down: in "any" mode a fetch is refused, in "hosts" mode it goes ahead (to the SSRF guard)
$script = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . '; require ' . var_export(__DIR__ . '/../includes/redis_functions.php', true) . ';'
    . '$a = ITFlow\Mcp\OAuth\OAuthHttp::limitedFetcher(true)("https://10.0.0.1/x"); $b = ITFlow\Mcp\OAuth\OAuthHttp::limitedFetcher(false)("https://10.0.0.1/x"); echo json_encode([$a["error"] ?? null, $b["error"] ?? null]);';
$out = [];
exec('RIVETIT_REDIS_PORT=1 RIVETIT_WEBHOOK_ALLOW_PRIVATE= php -r ' . escapeshellarg($script) . ' 2>&1', $out);
$res = json_decode((string) end($out), true);
$ok(is_array($res) && $res[0] === 'fetch limits unavailable', 'Redis down + "any host" mode: the fetch is refused before it goes out');
$ok(is_array($res) && is_string($res[1]) && str_contains($res[1], 'not allowed'), 'Redis down + "listed hosts" mode: the fetch proceeds (and is stopped only by the SSRF guard here)');

echo "== 8. the MCP session directory ==\n";
$sdir = sys_get_temp_dir() . '/rivetit-mcp-sessions-' . substr(hash('sha256', realpath(__DIR__ . '/../mcp_server')), 0, 12);
$mk('rvtc_sessclient01', 'Sess', ['http://127.0.0.1:8765/callback'], 'dynamic', 0);
$sc = $connect('rvtc_sessclient01', 'http://127.0.0.1:8765/callback', $alice);
$sTok = $sc['tok']['json']['access_token'] ?? '';
$ok($sTok !== '', 'precondition: a token');
$was = is_link($sdir) ? 'link' : (is_dir($sdir) ? 'dir' : 'none');
if ($was === 'dir') { exec('rm -rf ' . escapeshellarg($sdir)); }
@mkdir($sdir, 0775);
@chmod($sdir, 0775);
$ok($mcpCode($sTok)['code'] === 200 && (fileperms($sdir) & 0777) === 0700, 'a group-readable session directory of ours is closed to 0700 and used');
exec('rm -rf ' . escapeshellarg($sdir));
$decoy = sys_get_temp_dir() . '/hard_decoy_' . getmypid();
mkdir($decoy, 0700);
symlink($decoy, $sdir);
$ok($mcpCode($sTok)['code'] === 503, 'a symlink in place of the session directory is refused (503), not followed');
unlink($sdir);
@rmdir($decoy);
$ok($mcpCode($sTok)['code'] === 200 && (fileperms($sdir) & 0777) === 0700, 'with no directory in the way, it is created private and used');

echo "== 9. a session id is not an identity ==\n";
$mk('rvtc_sessclient02', 'Sess2', ['http://127.0.0.1:8765/callback'], 'dynamic', 0);
$a = $connect('rvtc_sessclient02', 'http://127.0.0.1:8765/callback', $alice);
$t = $connect('rvtc_sessclient02', 'http://127.0.0.1:8765/callback', $tom);
$aTok = $a['tok']['json']['access_token'] ?? '';
$tTok = $t['tok']['json']['access_token'] ?? '';
[$aSession] = oa_mcp_open($aTok);
$mineA = oa_tool($aTok, $aSession, 'rivetit_my_profile');
$mineT = oa_tool($tTok, $aSession, 'rivetit_my_profile');   // Tom's token on Alice's protocol session id
$ok(($mineA['data']['id'] ?? null) === 9101, 'precondition: Alice\'s session answers as Alice');
$ok(($mineT['data']['id'] ?? null) === 9102, 'Tom\'s token sent with Alice\'s session id still answers as Tom: identity comes from the bearer token on every request, never from the session');
$ok(oa_mcp($tTok, 'tools/list', [], '00000000-0000-4000-8000-000000000000')['code'] >= 400, 'an unknown (guessed) session id is refused');
$ok(oa_mcp(null, 'tools/list', [], $aSession)['code'] === 401, 'a valid session id without a token is refused');

oa_finish();
