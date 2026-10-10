<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Remote MCP built-in OAuth 2.1 server: the full authorization-code + PKCE flow over real HTTP against a private `php -S`
 * server, plus a negative case for each rule (wrong verifier, reused code, wrong redirect, wrong resource, expired, refresh
 * reuse, revoked, disabled user, role downgrade between calls, non-https redirect, registration off ...).
 * Needs a DISPOSABLE database (name contains "scratch" or "test"; config.php must point at it with $config_https_only = FALSE
 * and $config_enable_setup = 0) and the throwaway Redis (RIVETIT_REDIS_HOST/PORT). See the scratch recipe in the commit message
 * of tests/mcp_oauth_lib.php's author notes / docs: schema from db.sql, then scripts/update_cli.php --update_db.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/mcp_oauth_flow.php
 * Never run it against a live database: it replaces module/role rows 91-93 and users 9101-9106, and empties the mcp_oauth_* tables.
 */
require_once __DIR__ . '/mcp_oauth_lib.php';

use ITFlow\Mcp\OAuth\OAuthStore;

const RESOURCE_PATH = '/mcp';
const REDIRECT = 'http://127.0.0.1:8765/callback';
$host = $OA['host'];
$resource = "https://$host/mcp";
$issuer = "https://$host";
$port = 18000 + random_int(100, 900);

// ---- seed
oa_seed_base($mysqli);
oa_clean($mysqli);
$q("DELETE FROM user_client_permissions WHERE user_id IN (9101,9102,9103,9104,9105,9106)");
oa_seed_user($mysqli, 9101, 'Alice Admin', 'alice@oa.test', 'pw-Alice-1234!', 91);
oa_seed_user($mysqli, 9102, 'Tom Tech', 'tom@oa.test', 'pw-Tom-1234!', 92);
oa_seed_user($mysqli, 9103, 'Nora None', 'nora@oa.test', 'pw-Nora-1234!', 93);
oa_seed_user($mysqli, 9104, 'Dan Disabled', 'dan@oa.test', 'pw-Dan-1234!', 91);
$q("DELETE FROM tickets WHERE ticket_id IN (9101,9102)");
$q("DELETE FROM clients WHERE client_id IN (9101,9102)");
$q("INSERT INTO clients (client_id, client_name, client_currency_code, client_net_terms) VALUES (9101,'OA Alpha','USD',30)");
$q("INSERT IGNORE INTO ticket_statuses (ticket_status_id, ticket_status_name, ticket_status_color) VALUES (1,'Open','blue')");
$q("INSERT INTO tickets (ticket_id, ticket_number, ticket_subject, ticket_details, ticket_status, ticket_created_by, ticket_client_id) VALUES (9101,990001,'OA printer jam','details',1,9101,9101)");

$clearLimits = fn() => oa_reset_limits('*rl:oauth:*');
$clearLimits();

oa_start_server($port);

$store = new OAuthStore($mysqli);
/** A client created directly (not through the rate-limited HTTP registration). */
$mkClient = function (string $name = 'Test Client', array $uris = [REDIRECT]) use ($store): string {
    $id = 'rvtc_' . bin2hex(random_bytes(10));
    $store->createClient($id, $name, $uris, 'dynamic', '10.0.0.1', null);
    return $id;
};
$authParams = fn(string $cid, string $challenge, array $extra = []) => $extra + ['response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => REDIRECT,
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'st-' . bin2hex(random_bytes(4)), 'resource' => $resource, 'scope' => 'mcp:read'];
/** Sign in as $email and approve; returns [tokens-response, verifier, client id, auth params, jar]. */
$connect = function (string $email = 'alice@oa.test', string $pw = 'pw-Alice-1234!', ?string $cid = null) use ($mkClient, $authParams, $resource): array {
    $cid ??= $mkClient();
    $jar = oa_jar();
    oa_login($jar, $email, $pw);
    [$ver, $chal] = oa_pkce();
    $ap = $authParams($cid, $chal);
    $c = oa_consent($jar, $ap);
    $tok = oa_token(['grant_type' => 'authorization_code', 'client_id' => $cid, 'code' => $c['query']['code'] ?? 'none', 'redirect_uri' => REDIRECT, 'code_verifier' => $ver, 'resource' => $resource]);
    return [$tok, $ver, $cid, $ap, $jar];
};
/** HTTP status of an MCP `initialize` with this bearer (200 = accepted, 401 = rejected). */
$bearerCode = fn(?string $t) => oa_mcp($t, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'probe', 'version' => '1']])['code'];

echo "== discovery ==\n";
$as = oa_http('GET', '/.well-known/oauth-authorization-server');
$m = $as['json'] ?? [];
$ok($as['code'] === 200 && ($m['issuer'] ?? '') === $issuer, 'AS metadata (RFC 8414) served, issuer is the configured base URL');
$ok(($m['code_challenge_methods_supported'] ?? null) === ['S256'] && ($m['token_endpoint_auth_methods_supported'] ?? null) === ['none'], 'metadata: S256 only, public clients only');
$ok(($m['authorization_response_iss_parameter_supported'] ?? false) === true && ($m['grant_types_supported'] ?? []) === ['authorization_code', 'refresh_token'], 'metadata: RFC 9207 iss supported, auth-code + refresh grants');
$ok(str_starts_with($m['authorization_endpoint'] ?? '', $issuer) && str_starts_with($m['token_endpoint'] ?? '', $issuer) && isset($m['registration_endpoint'], $m['revocation_endpoint']), 'metadata: endpoints are on the issuer and registration is advertised');
$prm = oa_http('GET', '/.well-known/oauth-protected-resource');
$ok(($prm['json']['authorization_servers'] ?? null) === [$issuer] && ($prm['json']['resource'] ?? '') === $resource, 'protected-resource metadata (RFC 9728) points at the built-in issuer');
$un = oa_mcp(null, 'tools/list');
$ok($un['code'] === 401 && str_contains($un['headers']['www-authenticate'] ?? '', 'resource_metadata="' . $issuer . '/.well-known/oauth-protected-resource"'), '/mcp without a token: 401 + WWW-Authenticate resource_metadata');

echo "== registration (RFC 7591) ==\n";
[$r1, $regId] = oa_register(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']]);
$ok($r1['code'] === 201 && str_starts_with((string) $regId, 'rvtc_') && ($r1['json']['token_endpoint_auth_method'] ?? '') === 'none' && !isset($r1['json']['client_secret']), 'registers a public client (no secret), https redirect');
[$r2] = oa_register(['redirect_uris' => ['http://localhost:33418/cb']]);
$ok($r2['code'] === 201, 'loopback http redirect (localhost) accepted');
[$r2b] = oa_register(['redirect_uris' => ['http://[::1]:9/cb']]);
$ok($r2b['code'] === 201, 'loopback http redirect ([::1]) accepted');
$bad = [
    'http (non-loopback) redirect' => ['redirect_uris' => ['http://evil.example/cb']],
    'javascript: redirect' => ['redirect_uris' => ['javascript:alert(1)']],
    'custom scheme redirect' => ['redirect_uris' => ['claude://cb']],
    'redirect with fragment' => ['redirect_uris' => ['https://a.example/cb#x']],
    'redirect with userinfo' => ['redirect_uris' => ['https://u:p@a.example/cb']],
    'no redirect_uris' => ['redirect_uris' => []],
    'too many redirect_uris' => ['redirect_uris' => array_map(fn($i) => "https://a.example/$i", range(1, 6))],
    'redirect_uris not a list' => ['redirect_uris' => 'https://a.example/cb'],
];
$clearLimits();
foreach ($bad as $label => $meta) {
    [$rb] = oa_register($meta);
    $ok($rb['code'] === 400 && ($rb['json']['error'] ?? '') === 'invalid_redirect_uri', "registration refused: $label");
}
foreach (['client_secret_basic', 'client_secret_post', 'private_key_jwt'] as $method) {
    [$rb] = oa_register(['token_endpoint_auth_method' => $method]);
    $ok($rb['code'] === 400 && ($rb['json']['error'] ?? '') === 'invalid_client_metadata', "registration refused: token_endpoint_auth_method $method");
}
[$rb] = oa_register(['grant_types' => ['password']]);
$ok($rb['code'] === 400 && ($rb['json']['error'] ?? '') === 'invalid_client_metadata', 'registration refused: password grant');
$rb = oa_http('POST', '/oauth/register.php', ['form' => ['redirect_uris' => 'x']]);
$ok($rb['code'] === 400 && ($rb['json']['error'] ?? '') === 'invalid_client_metadata', 'registration refused: not application/json');
$rb = oa_http('POST', '/oauth/register.php', ['headers' => ['Content-Type: application/json'], 'body' => '[1,2]']);
$ok($rb['code'] === 400, 'registration refused: JSON list instead of object');
$rb = oa_http('POST', '/oauth/register.php', ['headers' => ['Content-Type: application/json'], 'body' => '{broken']);
$ok($rb['code'] === 400, 'registration refused: invalid JSON');
$rb = oa_http('GET', '/oauth/register.php');
$ok($rb['code'] === 405, 'registration endpoint: GET is 405');
$ok(!str_contains($rb['body'], 'Fatal') && !str_contains($rb['body'], 'mysqli'), 'error responses do not leak internals');

echo "== authorize: request validation ==\n";
$cid = $mkClient('Validation Client');
[$ver, $chal] = oa_pkce();
$ap = $authParams($cid, $chal);
$r = oa_http('GET', oa_authorize_url(['client_id' => 'rvtc_nope'] + $ap));
$ok($r['code'] === 400 && $r['location'] === null && str_contains($r['body'], 'not registered'), 'unknown client: error page, no redirect');
$r = oa_http('GET', oa_authorize_url(['redirect_uri' => 'http://127.0.0.1:8765/other'] + $ap));
$ok($r['code'] === 400 && $r['location'] === null, 'redirect_uri that is not registered exactly: error page, no redirect');
foreach (['http://127.0.0.1:8765/callback/', 'http://127.0.0.1:8766/callback', 'http://127.0.0.1:8765/callback?x=1', 'HTTP://127.0.0.1:8765/callback'] as $near) {
    $r = oa_http('GET', oa_authorize_url(['redirect_uri' => $near] + $ap));
    $ok($r['code'] === 400 && $r['location'] === null, "near-miss redirect_uri is not accepted ($near)");
}
$r = oa_http('GET', oa_authorize_url(array_diff_key($ap, ['redirect_uri' => 1])));
$ok($r['code'] === 400 && $r['location'] === null, 'missing redirect_uri: error page');
$expectError = function (array $params, string $error, string $label) use ($ok, $issuer) {
    $r = oa_http('GET', oa_authorize_url($params));
    $qs = oa_query($r['location']);
    $ok($r['code'] === 302 && str_starts_with((string) $r['location'], REDIRECT . '?') && ($qs['error'] ?? '') === $error && ($qs['state'] ?? null) === ($params['state'] ?? null) && ($qs['iss'] ?? '') === $issuer, "$label -> redirect with error=$error, state and iss");
};
$expectError(array_diff_key($ap, ['code_challenge' => 1, 'code_challenge_method' => 1]), 'invalid_request', 'PKCE missing');
$expectError(['code_challenge_method' => 'plain'] + $ap, 'invalid_request', 'PKCE method plain (downgrade)');
$expectError(array_diff_key($ap, ['code_challenge_method' => 1]), 'invalid_request', 'PKCE method omitted (defaults to plain in RFC 7636, refused here)');
$expectError(['code_challenge' => 'short'] + $ap, 'invalid_request', 'malformed code_challenge');
$expectError(['response_type' => 'token'] + $ap, 'unsupported_response_type', 'implicit flow');
$expectError(['resource' => 'https://evil.example/mcp'] + $ap, 'invalid_target', 'resource that is not this MCP server');
$expectError(['resource' => $resource . '/'] + $ap, 'invalid_target', 'resource with a trailing slash');
$expectError(['scope' => 'admin'] + $ap, 'invalid_scope', 'unsupported scope');
$r = oa_http('GET', oa_authorize_url($ap) . '&state=second');
$qs = oa_query($r['location']);
$ok(($qs['error'] ?? '') === 'invalid_request', 'parameter repeated twice -> invalid_request');
$r = oa_http('GET', oa_authorize_url(['state' => str_repeat('s', 2000)] + $ap));
$qs = oa_query($r['location']);
$ok(($qs['error'] ?? '') === 'invalid_request' && !isset($qs['state']), 'oversized state is rejected and not echoed back');

echo "== authorize: sign-in, consent screen ==\n";
$r = oa_http('GET', oa_authorize_url($ap));
$ok($r['code'] === 302 && str_starts_with((string) $r['location'], '/login.php?last_visited='), 'not signed in: sent to the normal login page');
$lv = base64_decode(oa_query($r['location'])['last_visited'] ?? '');
$ok(str_starts_with($lv, '/oauth/authorize.php?') && str_contains($lv, 'client_id=' . $cid), 'login returns to the authorization request');
$jar = oa_jar();
$ok(oa_login($jar, 'alice@oa.test', 'wrong-password') === false, 'wrong password does not sign in');
$ok(oa_login($jar, 'alice@oa.test', 'pw-Alice-1234!'), 'agent signs in through /login.php');
// the real login redirect: the authorization request survives the sign-in round trip
$jar2 = oa_jar();
oa_http('GET', '/login.php?last_visited=' . urlencode(base64_encode(oa_authorize_url($ap))), ['jar' => $jar2]);
$lg = oa_http('POST', '/login.php?last_visited=' . urlencode(base64_encode(oa_authorize_url($ap))), ['jar' => $jar2, 'form' => ['email' => 'alice@oa.test', 'password' => 'pw-Alice-1234!', 'login' => '1']]);
$ok($lg['code'] === 302 && str_contains((string) $lg['location'], '/oauth/authorize.php?') && str_contains((string) $lg['location'], 'client_id=' . $cid), 'after sign-in the browser comes back to the consent screen');
$page = oa_http('GET', oa_authorize_url($ap), ['jar' => $jar]);
$ok($page['code'] === 200 && str_contains($page['body'], 'Validation Client') && str_contains($page['body'], '127.0.0.1:8765'), 'consent screen names the app and the redirect host');
$ok(str_contains($page['body'], 'mcp:read') && str_contains($page['body'], 'Knowledge base') && str_contains($page['body'], 'Create, change or delete anything'), 'consent screen states exactly what is allowed (read-only) and what is not');
$ok(str_contains($page['body'], 'Alice Admin'), 'consent screen says who is signed in');
$ok(($page['headers']['x-frame-options'] ?? '') === 'DENY' && str_contains($page['headers']['content-security-policy'] ?? '', "frame-ancestors 'none'") && str_contains($page['headers']['cache-control'] ?? '', 'no-store'), 'consent screen: clickjacking headers and no-store');
$ok(!str_contains($page['body'], '<script'), 'consent screen runs no script');
$evil = $mkClient('<script>alert(1)</script><img src=x onerror=alert(2)>');
$pageEvil = oa_http('GET', oa_authorize_url($authParams($evil, $chal)), ['jar' => $jar]);
$ok($pageEvil['code'] === 200 && !str_contains($pageEvil['body'], '<script>alert(1)') && str_contains($pageEvil['body'], '&lt;script&gt;'), 'client name is HTML-escaped on the consent screen');
$pageNora = (function () use ($authParams, $mkClient, $chal) { $j = oa_jar(); oa_login($j, 'nora@oa.test', 'pw-Nora-1234!'); return oa_http('GET', oa_authorize_url($authParams($mkClient('X'), $chal)), ['jar' => $j]); })();
$ok(in_array($pageNora['code'], [302, 403], true) && !str_contains($pageNora['body'], 'name="decision"'), 'a module-only login (no Tickets/Assets/Departments) cannot reach the consent screen');
$q("DELETE FROM user_role_permissions WHERE user_role_id = 92 AND module_id = 4");
$pageTom = (function () use ($authParams, $mkClient, $chal) { $j = oa_jar(); oa_login($j, 'tom@oa.test', 'pw-Tom-1234!'); return oa_http('GET', oa_authorize_url($authParams($mkClient('X'), $chal)), ['jar' => $j]); })();
$ok($pageTom['code'] === 200 && substr_count($pageTom['body'], 'your role has no access') === 1, 'the consent screen marks what the signed-in role cannot read (here: knowledge base)');
$q("REPLACE INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (92,4,2)");
// CSRF
preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $cm);
$csrf = html_entity_decode($cm[1] ?? '');
$r = oa_http('POST', '/oauth/authorize.php', ['jar' => $jar, 'form' => $ap + ['decision' => 'approve']]);
$ok($r['code'] === 403 && $r['location'] === null, 'approve without a CSRF token: refused, no redirect');
$r = oa_http('POST', '/oauth/authorize.php', ['jar' => $jar, 'form' => $ap + ['decision' => 'approve', 'csrf_token' => 'forged']]);
$ok($r['code'] === 403 && $r['location'] === null, 'approve with a forged CSRF token: refused');
$r = oa_http('GET', oa_authorize_url($ap + ['decision' => 'approve', 'csrf_token' => $csrf]), ['jar' => $jar]);
$ok($r['code'] === 200 && $r['location'] === null, 'a GET with decision=approve does not approve (consent needs the POST)');
$r = oa_http('POST', '/oauth/authorize.php', ['jar' => $jar, 'form' => ['redirect_uri' => 'https://evil.example/', 'csrf_token' => $csrf, 'decision' => 'approve'] + $ap]);
$ok($r['code'] === 400 && $r['location'] === null, 'POST with a tampered redirect_uri is re-validated: error page, no redirect to the attacker');
$r = oa_http('POST', '/oauth/authorize.php', ['jar' => $jar, 'form' => $ap + ['csrf_token' => $csrf, 'decision' => 'maybe']]);
$ok($r['code'] === 400 && $r['location'] === null, 'unknown decision: refused');
$deny = oa_consent($jar, $ap, 'deny');
$ok(($deny['post']['code'] ?? 0) === 303 && ($deny['query']['error'] ?? '') === 'access_denied' && ($deny['query']['state'] ?? '') === $ap['state'] && ($deny['query']['iss'] ?? '') === $issuer && !isset($deny['query']['code']), 'deny: access_denied with state and iss, no code');
$appr = oa_consent($jar, $ap);
$ok(($appr['post']['code'] ?? 0) === 303 && str_starts_with((string) $appr['post']['location'], REDIRECT . '?') && !empty($appr['query']['code']) && ($appr['query']['state'] ?? '') === $ap['state'] && ($appr['query']['iss'] ?? '') === $issuer, 'approve: code + state + iss on the registered redirect URI');

echo "== token: authorization_code ==\n";
$code = $appr['query']['code'];
$t = oa_token(['grant_type' => 'authorization_code', 'client_id' => $cid, 'code' => $code, 'redirect_uri' => REDIRECT, 'code_verifier' => 'x' . $ver]);
$ok($t['code'] === 400 && ($t['json']['error'] ?? '') === 'invalid_grant', 'wrong PKCE verifier -> invalid_grant');
$t = oa_token(['grant_type' => 'authorization_code', 'client_id' => $cid, 'code' => $code, 'redirect_uri' => REDIRECT, 'code_verifier' => $ver]);
$ok($t['code'] === 400 && ($t['json']['error'] ?? '') === 'invalid_grant', 'a code that failed once is burned: the right verifier no longer works');

$mk = function () use ($jar, $cid, $authParams, $resource) {   // a fresh approved code for the same signed-in browser
    [$v, $c] = oa_pkce();
    $a = $authParams($cid, $c);
    $x = oa_consent($jar, $a);
    return [$x['query']['code'] ?? '', $v, $a];
};
[$c1, $v1] = $mk();
$t = oa_token(['grant_type' => 'authorization_code', 'client_id' => $cid, 'code' => $c1, 'redirect_uri' => 'http://127.0.0.1:8765/callback2', 'code_verifier' => $v1]);
$ok(($t['json']['error'] ?? '') === 'invalid_grant', 'wrong redirect_uri on exchange -> invalid_grant');
[$c1, $v1] = $mk();
$other = $mkClient('Other Client');
$t = oa_token(['grant_type' => 'authorization_code', 'client_id' => $other, 'code' => $c1, 'redirect_uri' => REDIRECT, 'code_verifier' => $v1]);
$ok(($t['json']['error'] ?? '') === 'invalid_grant', 'code redeemed by a different client -> invalid_grant');
[$c1, $v1] = $mk();
$ttl = (int) $mysqli->query("SELECT TIMESTAMPDIFF(SECOND, created_at, expires_at) AS s FROM mcp_oauth_codes ORDER BY created_at DESC, expires_at DESC LIMIT 1")->fetch_assoc()['s'];
$ok($ttl <= 60 && $ttl >= 1, "authorization codes live at most 60 s (stored lifetime $ttl s)");
$q("UPDATE mcp_oauth_codes SET expires_at = NOW() - INTERVAL 1 SECOND WHERE code_hash = UNHEX(SHA2('" . $mysqli->real_escape_string($c1) . "', 256))");
$t = oa_token(['grant_type' => 'authorization_code', 'client_id' => $cid, 'code' => $c1, 'redirect_uri' => REDIRECT, 'code_verifier' => $v1]);
$ok(($t['json']['error'] ?? '') === 'invalid_grant', 'expired code -> invalid_grant');
[$c1, $v1] = $mk();
$base = ['grant_type' => 'authorization_code', 'client_id' => $cid, 'code' => $c1, 'redirect_uri' => REDIRECT, 'code_verifier' => $v1];
$t = oa_token(array_diff_key($base, ['code_verifier' => 1]));
$ok(($t['json']['error'] ?? '') === 'invalid_request', 'missing code_verifier -> invalid_request (PKCE cannot be skipped)');
$t = oa_token(['client_secret' => 'x'] + $base);
$ok($t['code'] === 401 && ($t['json']['error'] ?? '') === 'invalid_client', 'a client_secret is refused: public clients only');
$t = oa_http('POST', '/oauth/token.php', ['form' => $base, 'headers' => ['Authorization: Basic ' . base64_encode("$cid:secret")]]);
$ok($t['code'] === 401 && ($t['json']['error'] ?? '') === 'invalid_client', 'HTTP Basic client credentials are refused');
$t = oa_token(['resource' => 'https://evil.example/mcp'] + $base);
$ok(($t['json']['error'] ?? '') === 'invalid_target', 'token request with another resource -> invalid_target');
$t = oa_token(['grant_type' => 'password', 'username' => 'a', 'password' => 'b', 'client_id' => $cid]);
$ok(($t['json']['error'] ?? '') === 'unsupported_grant_type', 'password grant -> unsupported_grant_type');
$t = oa_token(['client_id' => 'rvtc_unknown'] + $base);
$ok($t['code'] === 401 && ($t['json']['error'] ?? '') === 'invalid_client', 'unknown client_id -> invalid_client');
$t = oa_http('POST', '/oauth/token.php', ['json' => $base]);
$ok($t['code'] === 400 && ($t['json']['error'] ?? '') === 'invalid_request', 'token request as JSON -> invalid_request (strict content type)');
$t = oa_http('POST', '/oauth/token.php', ['body' => http_build_query($base) . '&grant_type=refresh_token', 'headers' => ['Content-Type: application/x-www-form-urlencoded']]);
$ok(($t['json']['error'] ?? '') === 'invalid_request', 'a repeated parameter -> invalid_request');
$t = oa_http('GET', '/oauth/token.php?' . http_build_query($base));
$ok($t['code'] === 405, 'token endpoint: GET is 405 (never reads parameters from a URL)');
$ok(($t['headers']['cache-control'] ?? '') === 'no-store', 'token endpoint answers are no-store');

// success, then reuse of the code revokes what the first use produced
$t1 = oa_token($base);
$ok($t1['code'] === 200 && ($t1['json']['token_type'] ?? '') === 'Bearer' && ($t1['json']['scope'] ?? '') === 'mcp:read' && ($t1['json']['expires_in'] ?? 9999) <= 900 && !empty($t1['json']['refresh_token']), 'code exchange: Bearer access token (<= 15 min), refresh token, scope mcp:read');
$ok(($t1['headers']['cache-control'] ?? '') === 'no-store' && ($t1['headers']['pragma'] ?? '') === 'no-cache', 'token response: Cache-Control no-store, Pragma no-cache');
$at1 = $t1['json']['access_token'];
$ok($bearerCode($at1) === 200, 'the new access token works on /mcp');
$t2 = oa_token($base);
$ok(($t2['json']['error'] ?? '') === 'invalid_grant', 'the same code a second time -> invalid_grant');
$ok($bearerCode($at1) === 401, 'code reuse revoked the grant: the access token from the first use is dead');
$ok(($mysqli->query("SELECT revoke_reason FROM mcp_oauth_grants WHERE origin_code_hash = UNHEX(SHA2('" . $mysqli->real_escape_string($c1) . "', 256))")->fetch_assoc()['revoke_reason'] ?? '') === 'code_reuse', 'revoke reason recorded as code_reuse');

echo "== the resource server ==\n";
[$tok, $verA, $cidA, $apA, $jarA] = $connect();
$ok($tok['code'] === 200, 'fresh connection for Alice');
$at = $tok['json']['access_token'];
$rt = $tok['json']['refresh_token'];
[$sess, $init] = oa_mcp_open($at);
$ok($init['code'] === 200 && $sess !== null && ($init['rpc']['result']['serverInfo']['name'] ?? '') === 'RivetIT', 'MCP initialize with the built-in token succeeds and opens a session');
$l = oa_mcp($at, 'tools/list', [], $sess);
$names = array_column($l['rpc']['result']['tools'] ?? [], 'name');
$ok(count($names) === 10 && in_array('rivetit_search_tickets', $names, true), 'tools/list returns the 10 read-only rivetit_* tools');
$prof = oa_tool($at, $sess, 'rivetit_my_profile');
$ok(($prof['data']['id'] ?? 0) === 9101 && ($prof['data']['email'] ?? '') === 'alice@oa.test', 'the call runs as the consenting user');
$tk = oa_tool($at, $sess, 'rivetit_search_tickets', ['query' => 'printer']);
$ok(($tk['success'] ?? false) === true && count($tk['data'] ?? []) >= 1, 'tool call returns RivetIT data (tickets)');
$ok($bearerCode('rvt_at_' . str_repeat('A', 43)) === 401, 'a well-formed but unknown access token -> 401');
$ok($bearerCode('garbage') === 401, 'a malformed token -> 401');
$ok($bearerCode($rt) === 401, 'a refresh token is not accepted as an access token');
$ok($bearerCode($appr['query']['code']) === 401, 'an authorization code is not accepted as an access token');
$jwt = 'eyJhbGciOiJSUzI1NiJ9.' . rtrim(strtr(base64_encode(json_encode(['iss' => $issuer, 'aud' => $resource, 'sub' => 'x', 'exp' => time() + 600, 'iat' => time()])), '+/', '-_'), '=') . '.sig';
$ok($bearerCode($jwt) === 401, 'a JWT is not accepted while the built-in server is on (no passthrough)');
$wa = oa_mcp($at, 'tools/list', [], null)['headers']['www-authenticate'] ?? '';
$ok(oa_mcp('rvt_at_' . str_repeat('B', 43), 'tools/list')['headers']['www-authenticate'] !== '' && str_contains(oa_mcp('rvt_at_' . str_repeat('B', 43), 'tools/list')['headers']['www-authenticate'], 'error="invalid_token"'), 'a bad token gets error="invalid_token" with resource_metadata');

echo "== audience and expiry ==\n";
$q("UPDATE mcp_oauth_grants SET resource = 'https://other.example/mcp' WHERE client_id = '" . $mysqli->real_escape_string($cidA) . "'");
$ok($bearerCode($at) === 401, 'a token whose audience is not this MCP URL is rejected');
$q("UPDATE mcp_oauth_grants SET resource = '" . $mysqli->real_escape_string($resource) . "' WHERE client_id = '" . $mysqli->real_escape_string($cidA) . "'");
$ok($bearerCode($at) === 200, 'restoring the audience restores access (the check is live)');
$q("UPDATE mcp_oauth_tokens SET expires_at = NOW() - INTERVAL 1 SECOND WHERE token_hash = UNHEX(SHA2('" . $mysqli->real_escape_string($at) . "', 256))");
$ok($bearerCode($at) === 401, 'an expired access token -> 401');
$life = $mysqli->query("SELECT MAX(TIMESTAMPDIFF(SECOND, created_at, expires_at)) AS s FROM mcp_oauth_tokens WHERE token_kind = 'access'")->fetch_assoc()['s'];
$ok((int) $life <= 900, "access tokens are stored with a lifetime of at most 15 minutes ($life s)");
$q("UPDATE mcp_oauth_grants SET expires_at = NOW() - INTERVAL 1 SECOND WHERE client_id = '" . $mysqli->real_escape_string($cidA) . "'");
$r = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidA, 'refresh_token' => $rt]);
$ok(($r['json']['error'] ?? '') === 'invalid_grant', 'a consent past its absolute lifetime cannot be refreshed');

echo "== refresh tokens: rotation and reuse detection ==\n";
[$tok, , $cidR] = $connect();
$rt0 = $tok['json']['refresh_token'];
$at0 = $tok['json']['access_token'];
$rf = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidR, 'refresh_token' => $rt0]);
$ok($rf['code'] === 200 && !empty($rf['json']['access_token']) && !empty($rf['json']['refresh_token']) && $rf['json']['refresh_token'] !== $rt0, 'refresh issues a new access token and a NEW refresh token (rotation)');
$rt1 = $rf['json']['refresh_token'];
$at1 = $rf['json']['access_token'];
$ok($bearerCode($at1) === 200, 'the refreshed access token works');
$ok(oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidR, 'refresh_token' => $rt1, 'scope' => 'mcp:read mcp:write'])['json']['error'] === 'invalid_scope', 'a refresh cannot widen the scope');
$ok(oa_token(['grant_type' => 'refresh_token', 'client_id' => $mkClient('Thief'), 'refresh_token' => $rt1])['json']['error'] === 'invalid_grant', 'a refresh token presented by another client -> invalid_grant (and nothing is revoked)');
$ok($bearerCode($at1) === 200, '... the legitimate token still works after that attempt');
$reuse = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidR, 'refresh_token' => $rt0]);
$ok(($reuse['json']['error'] ?? '') === 'invalid_grant', 'the old refresh token used again -> invalid_grant');
$ok($bearerCode($at1) === 401, 'refresh-token reuse revoked the whole grant: the newest access token is dead');
$ok(oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidR, 'refresh_token' => $rt1])['json']['error'] === 'invalid_grant', '... and the newest refresh token is dead too');
$ok(oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidR, 'refresh_token' => 'rvt_rt_' . str_repeat('C', 43)])['json']['error'] === 'invalid_grant', 'an unknown refresh token -> invalid_grant');

echo "== revocation (RFC 7009) ==\n";
[$tok, , $cidV] = $connect();
$atV = $tok['json']['access_token'];
$rtV = $tok['json']['refresh_token'];
$rv = oa_http('POST', '/oauth/revoke.php', ['form' => ['token' => 'rvt_rt_' . str_repeat('D', 43), 'client_id' => $cidV]]);
$ok($rv['code'] === 200, 'revoking an unknown token answers 200');
$rv = oa_http('POST', '/oauth/revoke.php', ['form' => ['token' => $rtV, 'client_id' => $mkClient('Not Owner')]]);
$ok($rv['code'] === 200 && $bearerCode($atV) === 200, "another client cannot revoke someone else's token (200, nothing happens)");
$rv = oa_http('POST', '/oauth/revoke.php', ['form' => ['token' => $rtV]]);
$ok($rv['code'] === 401, 'revocation without client_id -> invalid_client');
$rv = oa_http('POST', '/oauth/revoke.php', ['form' => ['token' => $rtV, 'client_id' => $cidV, 'token_type_hint' => 'refresh_token']]);
$ok($rv['code'] === 200, 'revoking the refresh token answers 200');
$ok($bearerCode($atV) === 401, 'revocation ended the access token as well');
$ok(oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidV, 'refresh_token' => $rtV])['json']['error'] === 'invalid_grant', 'a revoked refresh token cannot be used');
[$tok, , $cidV2] = $connect();
$rv = oa_http('POST', '/oauth/revoke.php', ['form' => ['token' => $tok['json']['access_token'], 'client_id' => $cidV2]]);
$ok($rv['code'] === 200 && $bearerCode($tok['json']['access_token']) === 401 && oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidV2, 'refresh_token' => $tok['json']['refresh_token']])['json']['error'] === 'invalid_grant', 'revoking the access token ends the whole consent');

echo "== permissions are re-checked on every request ==\n";
[$tok, , $cidT] = $connect('tom@oa.test', 'pw-Tom-1234!');
$atT = $tok['json']['access_token'];
[$sT] = oa_mcp_open($atT);
$ok((oa_tool($atT, $sT, 'rivetit_search_tickets')['success'] ?? false) === true, 'role with Tickets: ticket search works');
$q("DELETE FROM user_role_permissions WHERE user_role_id = 92 AND module_id = 1");
$den = oa_tool($atT, $sT, 'rivetit_search_tickets');
$ok(($den['success'] ?? true) === false && ($den['errors'][0]['code'] ?? '') === 'PERMISSION_DENIED', 'role downgraded between calls: the SAME token is now PERMISSION_DENIED');
$q("REPLACE INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (92,1,2)");
$q("INSERT IGNORE INTO clients (client_id, client_name, client_currency_code, client_net_terms) VALUES (9102,'OA Beta','USD',30)");
$q("INSERT INTO user_client_permissions (user_id, client_id) VALUES (9102, 9102)");
$scoped = oa_tool($atT, $sT, 'rivetit_search_tickets', ['query' => 'printer']);
$ok(($scoped['success'] ?? false) === true && count($scoped['data'] ?? []) === 0, 'a client restriction added between calls hides the other department at once');
$q("DELETE FROM user_client_permissions WHERE user_id = 9102");
$q("DELETE FROM user_role_permissions WHERE user_role_id = 92");
$ok(($c = oa_tool($atT, $sT, 'rivetit_search_kb', ['query' => 'x'])['errors'][0]['code'] ?? '') === 'PERMISSION_DENIED', 'a role stripped of every module gets PERMISSION_DENIED from the tools');
$q("REPLACE INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (92,1,2),(92,2,2),(92,3,2),(92,4,2)");
$q("UPDATE users SET user_status = 0 WHERE user_id = 9102");
$ok($bearerCode($atT) === 401, 'disabled user: the access token is dead on the next call');
$ok(oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidT, 'refresh_token' => $tok['json']['refresh_token']])['json']['error'] === 'invalid_grant', 'disabled user: cannot refresh either');
$q("UPDATE users SET user_status = 1, user_archived_at = NOW() WHERE user_id = 9102");
$ok($bearerCode($atT) === 401, 'archived user: token is dead');
$q("UPDATE users SET user_archived_at = NULL WHERE user_id = 9102");
$q("DELETE FROM users WHERE user_id = 9104");
[$tokD] = [$connect('alice@oa.test', 'pw-Alice-1234!')[0]];
$q("UPDATE users SET user_type = 2 WHERE user_id = 9101");
$ok($bearerCode($tokD['json']['access_token']) === 401, 'a user who is no longer an agent: token is dead');
$q("UPDATE users SET user_type = 1 WHERE user_id = 9101");
$ok($bearerCode($tokD['json']['access_token']) === 200, 'restored agent: the same consent works again (the check is live, not cached)');
// a disabled/removed user cannot approve a new consent either
$q("UPDATE users SET user_status = 0 WHERE user_id = 9103");
$jn = oa_jar();
$ok(oa_login($jn, 'nora@oa.test', 'pw-Nora-1234!') === false, 'a disabled user cannot sign in to consent');
$q("UPDATE users SET user_status = 1 WHERE user_id = 9103");

echo "== disabling an app ==\n";
[$tok, , $cidX] = $connect();
$q("UPDATE mcp_oauth_clients SET disabled_at = NOW() WHERE client_id = '" . $mysqli->real_escape_string($cidX) . "'");
$ok($bearerCode($tok['json']['access_token']) === 401, 'disabled app: its access token stops working');
$ok(oa_token(['grant_type' => 'refresh_token', 'client_id' => $cidX, 'refresh_token' => $tok['json']['refresh_token']])['code'] === 401, 'disabled app: refresh -> invalid_client');
$rx = oa_http('GET', oa_authorize_url($authParams($cidX, $chal)));
$ok($rx['code'] === 400 && $rx['location'] === null, 'disabled app: authorization requests are refused');

echo "== secrets at rest and in the audit trail ==\n";
[$tok, $verS, $cidS, $apS, $jarS] = $connect();
$secrets = [$tok['json']['access_token'], $tok['json']['refresh_token']];
$allRows = '';
foreach (['mcp_oauth_tokens', 'mcp_oauth_codes', 'mcp_oauth_grants', 'mcp_oauth_clients', 'mcp_oauth_config'] as $tbl) {
    foreach ($mysqli->query("SELECT * FROM $tbl")->fetch_all(MYSQLI_ASSOC) as $row) { $allRows .= json_encode(array_map(fn($v) => is_string($v) ? bin2hex($v) . $v : $v, $row)); }
}
$aud = '';
foreach ($mysqli->query("SELECT * FROM audit_events WHERE event_type LIKE 'mcp.oauth_%'")->fetch_all(MYSQLI_ASSOC) as $row) { $aud .= json_encode($row); }
$okSecret = true;
foreach (array_merge($secrets, [$apS['code_challenge']]) as $s) { if (str_contains($allRows, $s) && $s !== $apS['code_challenge']) { $okSecret = false; } }
$ok($okSecret, 'access and refresh tokens are not stored in plain text (only SHA-256 hashes)');
$ok(!str_contains($aud, 'rvt_at_') && !str_contains($aud, 'rvt_rt_') && !str_contains($aud, 'rvt_ac_') && !str_contains($aud, $verS), 'the audit trail holds no codes, tokens or verifiers');
$ev = array_column($mysqli->query("SELECT DISTINCT event_type FROM audit_events WHERE event_type LIKE 'mcp.oauth_%'")->fetch_all(MYSQLI_ASSOC), 'event_type');
foreach (['mcp.oauth_client_registered', 'mcp.oauth_consent_granted', 'mcp.oauth_consent_denied', 'mcp.oauth_token_issued', 'mcp.oauth_token_refreshed', 'mcp.oauth_grant_revoked', 'mcp.oauth_reuse_detected'] as $e) {
    $ok(in_array($e, $ev, true), "audit event recorded: $e");
}
$colType = $mysqli->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mcp_oauth_tokens' AND COLUMN_NAME = 'token_hash'")->fetch_assoc()['DATA_TYPE'];
$ok($colType === 'binary', 'hash columns are BINARY (collation-safe)');

echo "== switching the feature off ==\n";
$q("UPDATE mcp_oauth_config SET setting_value = '0' WHERE setting_key = 'registration_enabled'");
[$rOff] = oa_register();
$ok($rOff['code'] === 403 && ($rOff['json']['error'] ?? '') === 'access_denied', 'registration switched off: 403 access_denied');
$ok(!isset(oa_http('GET', '/.well-known/oauth-authorization-server')['json']['registration_endpoint']), 'registration switched off: metadata stops advertising registration_endpoint');
$ok($bearerCode($tok['json']['access_token']) === 200, '... existing connections keep working (only new self-registration stops)');
$cidM = $mkClient('Pre-registered by admin');
$jM = oa_jar(); oa_login($jM, 'alice@oa.test', 'pw-Alice-1234!');
$okM = oa_consent($jM, $authParams($cidM, $chal));
$ok(!empty($okM['query']['code']), '... and an administrator-registered client can still connect');
$q("UPDATE mcp_oauth_config SET setting_value = '1' WHERE setting_key = 'registration_enabled'");

$q("UPDATE mcp_oauth_config SET setting_value = '0' WHERE setting_key = 'builtin_enabled'");
foreach (['/.well-known/oauth-authorization-server' => 'GET', '/oauth/register.php' => 'POST', '/oauth/token.php' => 'POST', '/oauth/revoke.php' => 'POST', oa_authorize_url($ap) => 'GET'] as $path => $verb) {
    $ok(oa_http($verb, $path, $verb === 'POST' ? ['json' => []] : [])['code'] === 404, 'built-in sign-in off (the default): ' . strtok($path, '?') . ' is 404');
}
$ok($bearerCode($tok['json']['access_token']) !== 200, 'built-in sign-in off: built-in tokens are not accepted');
// external-provider mode is untouched: PRM names the configured external issuer, the built-in token is just an invalid bearer
$q("UPDATE settings SET config_mcp_issuer = 'https://idp.example.test/application/o/rivetit/', config_mcp_audience = 'rivetit-mcp-aud' WHERE company_id = 1");
$prmExt = oa_http('GET', '/.well-known/oauth-protected-resource');
$ok(($prmExt['json']['authorization_servers'] ?? null) === ['https://idp.example.test/application/o/rivetit/'], 'external mode: protected-resource metadata names the external issuer');
$ok(oa_mcp($tok['json']['access_token'], 'tools/list')['code'] === 401, 'external mode: a built-in token is not a valid bearer');
$ok(oa_mcp(null, 'tools/list')['code'] === 401, 'external mode: anonymous request still gets the 401 challenge');
$q("UPDATE settings SET config_mcp_issuer = '', config_mcp_audience = '' WHERE company_id = 1");
$q("UPDATE mcp_oauth_config SET setting_value = '1' WHERE setting_key = 'builtin_enabled'");
$q("UPDATE settings SET config_module_enable_mcp = 0 WHERE company_id = 1");
$ok(oa_http('GET', '/.well-known/oauth-authorization-server')['code'] === 404 && oa_http('POST', '/oauth/token.php', ['form' => ['grant_type' => 'x']])['code'] === 404, 'Remote MCP switched off: the OAuth endpoints are 404 as well');
$q("UPDATE settings SET config_module_enable_mcp = 1 WHERE company_id = 1");

echo "== rate limit on registration ==\n";
$clearLimits();
$q("DELETE FROM mcp_oauth_clients WHERE created_ip = '127.0.0.1'");
$codes = [];
for ($i = 0; $i < 13; $i++) { $codes[] = oa_register()[0]['code']; }
$ok(array_slice($codes, 0, 10) === array_fill(0, 10, 201) && $codes[10] === 429, 'the 11th registration from one address within an hour is 429');
$clearLimits();
$q("DELETE FROM mcp_oauth_clients WHERE created_ip = '127.0.0.1'");

oa_finish();
