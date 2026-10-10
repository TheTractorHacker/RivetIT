<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Conformance walk-through: behaves like a real MCP client (Claude custom connector, ToolHive) talking to RivetIT's built-in
 * authorization server, using ONLY what discovery tells it, in the order the MCP authorization spec (2025-06-18) prescribes:
 *
 *   1. POST /mcp without a token          -> 401 + WWW-Authenticate: Bearer resource_metadata="..."
 *   2. GET  resource_metadata (RFC 9728)  -> authorization_servers
 *   3. GET  <issuer>/.well-known/oauth-authorization-server (RFC 8414)
 *   4. POST registration_endpoint (RFC 7591), public client, loopback redirect
 *   5. authorization_endpoint in a "browser": sign in as an agent (programmatic login), consent, code + state + iss
 *   6. POST token_endpoint (code + PKCE verifier + RFC 8707 resource)
 *   7. MCP initialize / tools/list / tools/call with the bearer
 *   8. refresh_token grant (rotation)
 *   9. POST revocation_endpoint (RFC 7009), then the call fails and the refresh token is dead
 *
 * It runs against a private `php -S` server on a DISPOSABLE scratch database (see tests/mcp_oauth_flow.php for the setup).
 * The server publishes https:// URLs; the harness maps them to the plain-HTTP private server (see tests/mcp_oauth_lib.php).
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/mcp_oauth_walkthrough.php
 */
require_once __DIR__ . '/mcp_oauth_lib.php';

$step = 0;
$say = function (string $text) use (&$step) { echo sprintf("\n[%d] %s\n", ++$step, $text); };
$expect = function (bool $cond, string $label) use ($ok) { $ok($cond, "    $label"); return $cond; };
$die = function (string $why) { echo "    STOP: $why\n"; oa_finish(); };

oa_seed_base($mysqli);
oa_clean($mysqli);
oa_seed_user($mysqli, 9101, 'Walk Agent', 'walk@oa.test', 'pw-Walk-1234!', 91);
$q("DELETE FROM tickets WHERE ticket_id = 9101");
$q("INSERT IGNORE INTO ticket_statuses (ticket_status_id, ticket_status_name, ticket_status_color) VALUES (1,'Open','blue')");
$q("INSERT INTO tickets (ticket_id, ticket_number, ticket_subject, ticket_details, ticket_status, ticket_created_by, ticket_client_id) VALUES (9101,990101,'Walkthrough: VPN drops','details',1,9101,0)");
oa_reset_limits();
oa_start_server(18000 + random_int(100, 900));
$redirectUri = 'http://127.0.0.1:' . (20000 + random_int(0, 9000)) . '/callback';

$say('Anonymous request to the MCP endpoint');
$r = oa_mcp(null, 'tools/list');
$challenge = $r['headers']['www-authenticate'] ?? '';
$expect($r['code'] === 401, 'HTTP 401');
$expect(preg_match('/^Bearer .*resource_metadata="([^"]+)"/', $challenge, $m) === 1, 'WWW-Authenticate carries resource_metadata');
$prmUrl = $m[1] ?? $die('no resource_metadata in the challenge');

$say('Protected resource metadata (RFC 9728): ' . $prmUrl);
$prm = oa_http('GET', $prmUrl);
$expect($prm['code'] === 200 && !empty($prm['json']['authorization_servers'][0]), 'lists an authorization server');
$resource = $prm['json']['resource'] ?? $die('no resource');
$issuer = $prm['json']['authorization_servers'][0];
$expect(in_array('mcp:read', $prm['json']['scopes_supported'] ?? [], true), 'advertises scope mcp:read');
echo "    resource=$resource  issuer=$issuer\n";

$say('Authorization server metadata (RFC 8414)');
$md = oa_http('GET', rtrim($issuer, '/') . '/.well-known/oauth-authorization-server');
$meta = $md['json'] ?? $die('no AS metadata');
$expect($md['code'] === 200 && ($meta['issuer'] ?? '') === $issuer, 'issuer matches the one named by the resource');
$expect(($meta['code_challenge_methods_supported'] ?? []) === ['S256'], 'PKCE S256 is the only method');
$expect(in_array('none', $meta['token_endpoint_auth_methods_supported'] ?? [], true), 'public clients are supported');
foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint', 'revocation_endpoint'] as $k) { $expect(!empty($meta[$k]), "$k advertised"); }

$say('Dynamic client registration (RFC 7591)');
$reg = oa_http('POST', $meta['registration_endpoint'], ['json' => ['client_name' => 'Walkthrough client', 'redirect_uris' => [$redirectUri], 'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]]);
$expect($reg['code'] === 201 && !empty($reg['json']['client_id']), 'registered, got a client_id');
$clientId = $reg['json']['client_id'] ?? $die('registration failed');
$expect(!isset($reg['json']['client_secret']), 'no client secret (public client)');

$say('Authorization request in the browser (sign in, consent)');
[$verifier, $challengeS256] = oa_pkce();
$state = bin2hex(random_bytes(8));
$authUrl = $meta['authorization_endpoint'] . '?' . http_build_query(['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri,
    'code_challenge' => $challengeS256, 'code_challenge_method' => 'S256', 'state' => $state, 'scope' => 'mcp:read', 'resource' => $resource], '', '&', PHP_QUERY_RFC3986);
$jar = oa_jar();
$page = oa_http('GET', $authUrl, ['jar' => $jar]);
$expect($page['code'] === 302 && str_starts_with((string) $page['location'], '/login.php'), 'not signed in: the browser is sent to the RivetIT login page');
$login = oa_http('GET', (string) $page['location'], ['jar' => $jar]);
$expect($login['code'] === 200, 'login page loads');
$signedIn = oa_http('POST', (string) $page['location'], ['jar' => $jar, 'form' => ['email' => 'walk@oa.test', 'password' => 'pw-Walk-1234!', 'login' => '1']]);
$expect($signedIn['code'] === 302 && str_contains((string) $signedIn['location'], '/oauth/authorize.php'), 'signing in returns the browser to the authorization request');
$consent = oa_http('GET', (string) $signedIn['location'], ['jar' => $jar]);
$expect($consent['code'] === 200 && str_contains($consent['body'], 'Walkthrough client') && str_contains($consent['body'], '127.0.0.1'), 'consent screen shows the app name and redirect host');
$expect(($consent['headers']['x-frame-options'] ?? '') === 'DENY', 'consent screen cannot be framed');
preg_match('/name="csrf_token" value="([^"]+)"/', $consent['body'], $cm);
$approve = oa_http('POST', '/oauth/authorize.php', ['jar' => $jar, 'form' => ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'code_challenge' => $challengeS256, 'code_challenge_method' => 'S256', 'state' => $state, 'scope' => 'mcp:read', 'resource' => $resource, 'csrf_token' => html_entity_decode($cm[1] ?? ''), 'decision' => 'approve']]);
$back = oa_query($approve['location']);
$expect(str_starts_with((string) $approve['location'], $redirectUri . '?'), 'the browser is sent back to the registered redirect URI');
$expect(($back['state'] ?? '') === $state, 'state is echoed unchanged');
$expect(($back['iss'] ?? '') === $issuer, 'iss (RFC 9207) identifies the authorization server');
$code = $back['code'] ?? $die('no authorization code');

$say('Token request (authorization_code + PKCE + resource)');
$tok = oa_http('POST', $meta['token_endpoint'], ['form' => ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $verifier, 'resource' => $resource]]);
$expect($tok['code'] === 200 && ($tok['json']['token_type'] ?? '') === 'Bearer', 'Bearer access token issued');
$expect(($tok['json']['expires_in'] ?? 9999) <= 900, 'access token is short-lived (<= 15 minutes)');
$expect(!empty($tok['json']['refresh_token']) && ($tok['json']['scope'] ?? '') === 'mcp:read', 'refresh token and scope mcp:read');
$access = $tok['json']['access_token'] ?? $die('no access token');
$refresh = $tok['json']['refresh_token'] ?? '';

$say('MCP session with the bearer token');
[$session, $init] = oa_mcp_open($access);
$expect($init['code'] === 200 && $session !== null, 'initialize succeeds and returns a session');
$list = oa_mcp($access, 'tools/list', [], $session);
$names = array_column($list['rpc']['result']['tools'] ?? [], 'name');
$expect(count($names) >= 10 && in_array('rivetit_search_tickets', $names, true), 'tools/list: ' . count($names) . ' tools');
$prof = oa_tool($access, $session, 'rivetit_my_profile');
$expect(($prof['data']['email'] ?? '') === 'walk@oa.test', 'tools/call rivetit_my_profile runs as the consenting agent');
$tk = oa_tool($access, $session, 'rivetit_search_tickets', ['query' => 'VPN']);
$expect(($tk['success'] ?? false) === true && str_contains(json_encode($tk['data'] ?? []), 'VPN drops'), 'tools/call rivetit_search_tickets returns RivetIT data');

$say('Refresh (rotation)');
$rf = oa_http('POST', $meta['token_endpoint'], ['form' => ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $refresh, 'resource' => $resource]]);
$expect($rf['code'] === 200 && !empty($rf['json']['access_token']) && ($rf['json']['refresh_token'] ?? '') !== $refresh, 'new access token and a NEW refresh token');
$access2 = $rf['json']['access_token'] ?? $die('refresh failed');
$refresh2 = $rf['json']['refresh_token'];
[$s2] = oa_mcp_open($access2);
$expect(($p2 = oa_tool($access2, $s2, 'rivetit_my_profile')) && ($p2['success'] ?? false) === true, 'the refreshed token works');

$say('Revocation (RFC 7009)');
$rv = oa_http('POST', $meta['revocation_endpoint'], ['form' => ['token' => $refresh2, 'token_type_hint' => 'refresh_token', 'client_id' => $clientId]]);
$expect($rv['code'] === 200, 'revocation endpoint answers 200');
$after = oa_mcp($access2, 'tools/list', [], $s2);
$expect($after['code'] === 401, 'the access token is rejected after revocation (401)');
$expect(str_contains($after['headers']['www-authenticate'] ?? '', 'invalid_token'), '... with error="invalid_token"');
$again = oa_http('POST', $meta['token_endpoint'], ['form' => ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $refresh2]]);
$expect(($again['json']['error'] ?? '') === 'invalid_grant', 'the refresh token no longer works');

oa_clean($mysqli);
oa_finish();
