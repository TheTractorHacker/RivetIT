<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Remote MCP built-in OAuth server: service-level checks that do not need the web server (redirect URI rules, parameter
 * parsing, single-use gates, consent lifecycle, housekeeping). Needs the same DISPOSABLE database as tests/mcp_oauth_flow.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/mcp_oauth_service.php
 */
require_once __DIR__ . '/mcp_oauth_lib.php';

use ITFlow\Mcp\OAuth\OAuthConfig;
use ITFlow\Mcp\OAuth\OAuthService;
use ITFlow\Mcp\OAuth\OAuthStore;

oa_seed_base($mysqli);
oa_clean($mysqli);
oa_seed_user($mysqli, 9101, 'Alice Admin', 'alice@oa.test', 'pw-Alice-1234!', 91);
oa_seed_user($mysqli, 9102, 'Tom Tech', 'tom@oa.test', 'pw-Tom-1234!', 92);
$issuer = 'https://' . $OA['host'];
$resource = $issuer . '/mcp';
$service = new OAuthService($mysqli, $issuer, $resource);
$store = new OAuthStore($mysqli);
$esc = fn(string $v) => $mysqli->real_escape_string($v);
$hex = fn(string $secret) => bin2hex(OAuthStore::hash($secret));

echo "== redirect URI rules ==\n";
foreach (['https://claude.ai/api/mcp/auth_callback', 'https://a.example:8443/cb?x=1', 'http://127.0.0.1:3000/cb', 'http://localhost/cb', 'http://[::1]:1234/cb'] as $u) {
    $ok(OAuthService::redirectUriValid($u), "accepted: $u");
}
foreach (['http://example.com/cb', 'http://127.0.0.1.evil.example/cb', 'http://localhost.evil.example/cb', 'ftp://a.example/cb', 'javascript:alert(1)', 'data:text/html,x', 'claude://callback', '//evil.example/cb', '/relative', 'https://a.example/cb#frag', 'https://user:pw@a.example/cb', "https://a.example/\ncb", 'https://a.example/c b', 'https://a.example\\@evil.example/', str_repeat('a', 600), '', 'https://'] as $u) {
    $ok(!OAuthService::redirectUriValid($u), 'refused: ' . json_encode(substr($u, 0, 50)));
}

echo "== parameter parsing ==\n";
$p = OAuthService::parseParams('a=1&b=%20x&a=2&c');
$ok($p['values'] === ['a' => '1', 'b' => ' x', 'c' => ''] && $p['duplicates'] === ['a'], 'first value wins, repeats are reported, empty values kept');
$p = OAuthService::parseParams('');
$ok($p['values'] === [] && $p['duplicates'] === [], 'empty input');

echo "== manual registration ==\n";
[$o, $id] = $service->registerManual('ToolHive', ['http://localhost:8666/callback', ' http://localhost:8666/callback '], 'toolhive-client-1', 9101);
$ok($o === true && $id === 'toolhive-client-1' && $store->client($id)['redirect_uris'] === ['http://localhost:8666/callback'] && $store->client($id)['registration_type'] === 'manual', 'administrator registers a client with a chosen client id (duplicates removed)');
$ok($service->registerManual('Dup', ['https://a.example/cb'], 'toolhive-client-1', 9101)[0] === false, 'a client id cannot be registered twice');
$ok($service->registerManual('Bad id', ['https://a.example/cb'], 'short', 9101)[0] === false && $service->registerManual('Bad id', ['https://a.example/cb'], 'has space in it', 9101)[0] === false, 'client id must be 8-64 safe characters');
$ok($service->registerManual('Bad uri', ['http://evil.example/cb'], null, 9101)[0] === false, 'non-https, non-loopback redirect refused for manual clients as well');
$ok($service->registerManual('', ['https://a.example/cb'], null, 9101)[0] === false, 'a name is required');
[$o, $gen] = $service->registerManual('Generated', ['https://a.example/cb'], null, 9101);
$ok($o && str_starts_with($gen, 'rvtc_'), 'a client id is generated when none is given');

echo "== authorization request: scopes and resource ==\n";
[$ver, $chal] = oa_pkce();
$req = fn(array $extra = []) => $service->validateAuthorizationRequest(OAuthService::parseParams(http_build_query($extra + ['response_type' => 'code', 'client_id' => 'toolhive-client-1', 'redirect_uri' => 'http://localhost:8666/callback', 'code_challenge' => $chal, 'code_challenge_method' => 'S256'])));
$r = $req();
$ok($r['ok'] === true && $r['scope'] === 'mcp:read' && $r['resource'] === $resource, 'no scope and no resource: defaults to mcp:read on the MCP URL');
$ok($req(['scope' => 'mcp:read offline_access'])['scope'] === 'mcp:read', 'offline_access is ignored, mcp:read granted');
$ok($req(['scope' => 'offline_access'])['scope'] === 'mcp:read', 'only offline_access: still just mcp:read');
$ok(($req(['scope' => 'openid profile'])['error'] ?? '') === 'invalid_scope', 'unknown scopes alone -> invalid_scope');
$ok(($req(['scope' => 'mcp:write'])['error'] ?? '') === 'invalid_scope', 'there is no write scope');
$ok(($req(['resource' => $resource])['ok'] ?? false) === true, 'the exact resource is accepted');
$ok(($req(['resource' => $resource . '?x=1'])['error'] ?? '') === 'invalid_target', 'resource with a query is refused');
$ok(($req(['resource' => ''])['error'] ?? '') === 'invalid_target', 'empty resource is refused');
$ok(isset($req(['client_id' => 'nope-nope-nope'])['fatal']), 'unknown client is fatal (never redirected)');

echo "== single-use gates ==\n";
$cid = 'toolhive-client-1';
$hash = OAuthStore::hash('code-single-use');
$store->insertCode($hash, $cid, 9101, 'http://localhost:8666/callback', $chal, 'mcp:read', $resource);
$first = $store->redeemCode($hash);
$second = $store->redeemCode($hash);
$ok($first['status'] === 'ok' && $second['status'] === 'reused', 'a code redeems once; the second redemption is reported as reuse');
$ok($store->redeemCode(OAuthStore::hash('never-issued'))['status'] === 'unknown', 'an unknown code is unknown');
$h2 = OAuthStore::hash('code-expired');
$store->insertCode($h2, $cid, 9101, 'http://localhost:8666/callback', $chal, 'mcp:read', $resource);
$q("UPDATE mcp_oauth_codes SET expires_at = NOW() - INTERVAL 1 SECOND WHERE code_hash = UNHEX('" . bin2hex($h2) . "')");
$ok($store->redeemCode($h2)['status'] === 'expired', 'an expired code is expired (and not redeemable)');
$ok(strlen($hash) === 32, 'hashes are 32 raw bytes');

echo "== consent lifecycle ==\n";
$g1 = $store->createGrant($cid, 9101, 'mcp:read', $resource, OAuthStore::hash('origin-1'));
$g2 = $store->createGrant($cid, 9102, 'mcp:read', $resource, OAuthStore::hash('origin-2'));
$store->insertToken(OAuthStore::hash('rvt_rt_x1'), $g1, 'refresh', 3600);
$store->insertToken(OAuthStore::hash('rvt_at_x1'), $g1, 'access', 600);
$ok(count($store->activeGrants()) === 2 && count($store->activeGrants(9101)) === 1 && $store->activeGrants(9101)[0]['user_name'] === 'Alice Admin', 'active grants list: all, and per person');
$ok($service->revokeForUser($g2, 9101) === false && $store->grantIsLive($g2), "a person cannot revoke someone else's connection");
$ok($service->revokeForUser($g1, 9101) === true && !$store->grantIsLive($g1), 'a person revokes their own connection');
$ok($store->token(OAuthStore::hash('rvt_rt_x1')) === null && $store->token(OAuthStore::hash('rvt_at_x1')) === null, 'revoking removes the tokens under it');
$ok($service->revokeForUser($g1, 9101) === false, 'revoking twice is a no-op');
$ok($service->revokeByAdmin($g2, 9101) === true && !$store->grantIsLive($g2), 'an administrator can revoke anyone\'s connection');
$g3 = $store->createGrant($cid, 9102, 'mcp:read', $resource, OAuthStore::hash('origin-3'));
$g4 = $store->createGrant($cid, 9102, 'mcp:read', $resource, OAuthStore::hash('origin-4'));
$ok($store->revokeGrantsOfUser(9102, 'user_disabled') === 2 && !$store->grantIsLive($g3) && !$store->grantIsLive($g4), 'all consents of a user can be ended at once');
$ok(count($store->activeGrants()) === 0, 'revoked consents are not listed as active');

echo "== client lifecycle and housekeeping ==\n";
$g5 = $store->createGrant($cid, 9101, 'mcp:read', $resource, OAuthStore::hash('origin-5'));
$store->insertToken(OAuthStore::hash('rvt_rt_y1'), $g5, 'refresh', 3600);
$store->insertCode(OAuthStore::hash('code-y'), $cid, 9101, 'http://localhost:8666/callback', $chal, 'mcp:read', $resource);
$store->deleteClient($cid);
$cnt = fn(string $sql) => (int) $mysqli->query($sql)->fetch_row()[0];
$ok($store->client($cid) === null && $cnt("SELECT COUNT(*) FROM mcp_oauth_grants WHERE client_id = '$cid'") === 0 && $cnt('SELECT COUNT(*) FROM mcp_oauth_tokens WHERE grant_id = ' . $g5) === 0 && $cnt("SELECT COUNT(*) FROM mcp_oauth_codes WHERE client_id = '$cid'") === 0, 'deleting a client removes its grants, tokens and codes');
$keep = 'rvtc_keepkeepkeep1';
$old = 'rvtc_oldoldoldold1';
$store->createClient($keep, 'Keep', ['https://a.example/cb'], 'dynamic', '10.0.0.9', null);
$store->createClient($old, 'Old unused', ['https://a.example/cb'], 'dynamic', '10.0.0.9', null);
$q("UPDATE mcp_oauth_clients SET created_at = NOW() - INTERVAL 8 DAY WHERE client_id = '$old'");
$gOld = $store->createGrant($keep, 9101, 'mcp:read', $resource, OAuthStore::hash('origin-old'));
$q("UPDATE mcp_oauth_grants SET revoked_at = NOW() - INTERVAL 40 DAY WHERE grant_id = $gOld");
$store->insertToken(OAuthStore::hash('rvt_at_stale'), $gOld, 'access', 60);
$q("UPDATE mcp_oauth_tokens SET expires_at = NOW() - INTERVAL 9 DAY WHERE token_hash = UNHEX('" . $hex('rvt_at_stale') . "')");
$store->purge();
$ok($store->client($old) === null && $store->client($keep) !== null, 'purge removes self-registered clients nobody used after a week, keeps the rest');
$ok($store->grant($gOld) === null && $store->token(OAuthStore::hash('rvt_at_stale')) === null, 'purge removes long-dead grants and tokens');

echo "== metadata ==\n";
$ok(!isset($service->metadata(false)['registration_endpoint']) && isset($service->metadata(true)['registration_endpoint']), 'registration_endpoint only while self-registration is on');
$ok(OAuthConfig::resource('h.example') === 'https://h.example/mcp' && OAuthConfig::issuer('h.example') === 'https://h.example', 'issuer and resource derive from the configured base URL');
$ok(OAuthConfig::ACCESS_TTL <= 900 && OAuthConfig::CODE_TTL <= 60, 'lifetimes: access token <= 15 min, code <= 60 s');

oa_clean($mysqli);
oa_finish();
