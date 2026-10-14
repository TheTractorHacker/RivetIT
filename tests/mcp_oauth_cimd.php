<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Remote MCP built-in OAuth server: OAuth Client ID Metadata Documents (CIMD, docs/REMOTE_MCP.md). The client_id is an https URL;
 * RivetIT fetches the document, validates it and runs the normal consent, code, token, refresh and revocation machinery.
 *
 * The "somebody's document host" is tests/mcp_oauth_cimd_mock.php: a private HTTPS server with a self-signed certificate on
 * 127.0.0.1. For the test only, the PHP processes get two switches that are never set otherwise:
 *   RIVETIT_WEBHOOK_ALLOW_PRIVATE=1   (the existing webhook switch) so the SSRF policy lets the fetch reach a loopback address
 *   RIVETIT_CIMD_TEST_CA_FILE=<pem>   trust the mock's certificate
 * Same DISPOSABLE database requirements as tests/mcp_oauth_flow.php (name contains "scratch" or "test"; $config_https_only = FALSE;
 * $config_enable_setup = 0; throwaway Redis). Takes about a minute (one document is deliberately slow).
 */
require_once __DIR__ . '/mcp_oauth_lib.php';

use ITFlow\Mcp\OAuth\CimdFetcher;
use ITFlow\Mcp\OAuth\ClientMetadata;
use ITFlow\Mcp\OAuth\OAuthConfig;
use ITFlow\Mcp\OAuth\OAuthStore;

$host = $OA['host'];
$resource = "https://$host/mcp";
$port = 18000 + random_int(100, 900);
$mport = 19000 + random_int(100, 900);
$dir = sys_get_temp_dir() . '/cimd_mock_' . getmypid();
mkdir($dir, 0700);
$pem = "$dir/server.pem";
$control = "$dir/control.json";
$hits = "$dir/hits.log";
file_put_contents($hits, '');
file_put_contents($control, '{}');

// ---- the mock document host (self-signed certificate for 127.0.0.1)
$cert = "$dir/c.pem";
$key = "$dir/k.pem";
exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg($key) . ' -out ' . escapeshellarg($cert) . ' -days 2 -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1 2>&1', $o, $rc);
if ($rc !== 0) { fwrite(STDERR, "openssl failed\n"); exit(2); }
file_put_contents($pem, file_get_contents($key) . file_get_contents($cert));
$mock = proc_open(['php', __DIR__ . '/mcp_oauth_cimd_mock.php', (string) $mport, $pem, $control, $hits], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dir/mock.log", 'w'], 2 => ['file', "$dir/mock.log", 'w']], $pipes);
register_shutdown_function(function () use (&$mock, $dir) {
    if (is_resource($mock)) { $st = proc_get_status($mock); if (!empty($st['pid'])) { posix_kill($st['pid'], SIGTERM); } proc_close($mock); }
    foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
});
for ($i = 0; $i < 50; $i++) { $s = @fsockopen('127.0.0.1', $mport, $e, $m, 0.2); if ($s) { fclose($s); break; } usleep(100000); }

putenv('RIVETIT_WEBHOOK_ALLOW_PRIVATE=1');
putenv('RIVETIT_CIMD_TEST_CA_FILE=' . $cert);
$authority = "127.0.0.1:$mport";
$urlOf = fn(string $p) => "https://$authority$p";
$hitsOf = fn(string $p) => count(array_filter(explode("\n", (string) file_get_contents($hits)), fn($l) => $l === $p));
$allHits = fn() => count(array_filter(explode("\n", (string) file_get_contents($hits))));

$specs = [];
/** Publish (or replace) what the mock answers at $path. */
$serve = function (string $path, array $spec) use (&$specs, $control): void {
    $specs[$path] = $spec;
    file_put_contents($control, json_encode($specs));
};
/** A JSON document body; client_id defaults to the URL it is served at. */
$docBody = fn(array $over = []) => json_encode($over + [
    'client_id' => '{{SELF}}', 'client_name' => 'Mock Claude', 'client_uri' => 'https://claude.example',
    'redirect_uris' => ['https://claude.example/cb', 'http://localhost/callback', 'http://127.0.0.1/callback'],
    'grant_types' => ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer'],
    'response_types' => ['code'], 'token_endpoint_auth_method' => 'none',
]);
$json = ['Content-Type: application/json'];
$good = fn(array $over = [], array $headers = ['Content-Type: application/json', 'Cache-Control: max-age=600']) => ['status' => 200, 'headers' => $headers, 'body' => $docBody($over)];

$serve('/c/valid', $good());
$serve('/c/xss', $good(['client_name' => '<script>alert(1)</script>"\'&']));
$serve('/c/nocc', $good([], $json));
$serve('/c/noStore', $good([], ['Content-Type: application/json', 'Cache-Control: no-store, max-age=0']));
$serve('/c/long', $good([], ['Content-Type: application/json', 'Cache-Control: public, max-age=999999999']));
$serve('/c/mismatch', $good(['client_id' => 'https://other.example/c/mismatch']));
$serve('/c/redirect', ['status' => 302, 'headers' => ['Location: /c/valid', 'Content-Type: application/json'], 'body' => '{}']);
$serve('/c/big', ['status' => 200, 'headers' => $json, 'body' => $docBody(), 'repeat' => 70000]);
$serve('/c/slow', $good() + ['delay' => 6]);
$serve('/c/badjson', ['status' => 200, 'headers' => $json, 'body' => '{nope']);
$serve('/c/list', ['status' => 200, 'headers' => $json, 'body' => '[1,2]']);
$serve('/c/html', ['status' => 200, 'headers' => ['Content-Type: text/html'], 'body' => $docBody()]);
$serve('/c/s500', ['status' => 500, 'headers' => $json, 'body' => '{}']);
$serve('/c/s404', ['status' => 404, 'headers' => $json, 'body' => '{}']);
$serve('/c/basic', $good(['token_endpoint_auth_method' => 'client_secret_basic']));
$serve('/c/post', $good(['token_endpoint_auth_method' => 'client_secret_post']));
$serve('/c/pkjwt', $good(['token_endpoint_auth_method' => 'private_key_jwt', 'jwks_uri' => 'https://claude.example/jwks']));
$serve('/c/jwks', $good(['jwks' => ['keys' => []]]));
$serve('/c/secret', $good(['client_secret' => 'S3CR3T-SENTINEL-VALUE']));
$serve('/c/evilredir', $good(['redirect_uris' => ['http://evil.example/cb']]));
$serve('/c/jsredir', $good(['redirect_uris' => ['javascript:alert(1)']]));
$serve('/c/noredir', $good(['redirect_uris' => []]));
$serve('/c/nogrant', $good(['grant_types' => ['client_credentials']]));

$store = new OAuthStore($mysqli);
oa_seed_base($mysqli);
oa_clean($mysqli);
oa_seed_user($mysqli, 9101, 'Alice Admin', 'alice@oa.test', 'pw-Alice-1234!', 91);
$q("DELETE FROM user_client_permissions WHERE user_id = 9101");
$clearLimits = fn() => oa_reset_limits('*rl:oauth:*');
$clearLimits();
$mode = function (string $m, array $hosts = []) use ($mysqli): void { OAuthConfig::setCimd($mysqli, $m, $hosts); };
$mode('off');
oa_start_server($port);

$internal = fn(string $url) => ClientMetadata::internalId($url);
$docRow = fn(string $url) => $mysqli->query("SELECT * FROM mcp_oauth_client_documents WHERE client_id = '" . $internal($url) . "'")->fetch_assoc();
$clientRow = fn(string $url) => $store->client($internal($url));
$metadataClients = fn() => (int) $mysqli->query("SELECT COUNT(*) FROM mcp_oauth_clients WHERE registration_type = 'metadata'")->fetch_row()[0];
$audits = fn(string $type) => (int) $mysqli->query("SELECT COUNT(*) FROM audit_events WHERE event_type = '" . $mysqli->real_escape_string($type) . "'")->fetch_row()[0];

$LOOP = 'http://127.0.0.1:54321/callback';
$authParams = fn(string $cid, string $challenge, string $redirect) => ['response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => $redirect,
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'st-' . bin2hex(random_bytes(4)), 'resource' => $resource, 'scope' => 'mcp:read'];
/** The authorization page as a visitor with no session sees it (client problems are reported before any sign-in). */
$probe = function (string $cid, string $redirect) use ($authParams, $clearLimits): array {
    $clearLimits();
    [, $chal] = oa_pkce();
    return oa_http('GET', oa_authorize_url($authParams($cid, $chal, $redirect)));
};
$alice = oa_jar();
oa_login($alice, 'alice@oa.test', 'pw-Alice-1234!');
/** Full flow as Alice. @return array{tok:array, verifier:string, consent:array, params:array} */
$connect = function (string $cid, string $redirect, string $decision = 'approve') use ($authParams, $alice, $clearLimits, $resource): array {
    $clearLimits();
    [$ver, $chal] = oa_pkce();
    $ap = $authParams($cid, $chal, $redirect);
    $c = oa_consent($alice, $ap, $decision);
    $tok = isset($c['query']['code']) ? oa_token(['grant_type' => 'authorization_code', 'client_id' => $cid, 'code' => $c['query']['code'], 'redirect_uri' => $redirect, 'code_verifier' => $ver, 'resource' => $resource]) : ['code' => 0, 'json' => null];
    return ['tok' => $tok, 'verifier' => $ver, 'consent' => $c, 'params' => $ap];
};
$bearerCode = fn(?string $t) => oa_mcp($t, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'probe', 'version' => '1']])['code'];

$cValid = $urlOf('/c/valid');

echo "== client_id URL rules (no network) ==\n";
$bad = ['http scheme' => 'http://example.com/c/x', 'no path' => 'https://example.com', 'root path only' => 'https://example.com/', 'fragment' => 'https://example.com/c/x#f',
    'credentials' => 'https://user:pw@example.com/c/x', 'dot segment' => 'https://example.com/c/../x', 'encoded dot segment' => 'https://example.com/c/%2e%2e/x',
    'single dot segment' => 'https://example.com/./x', 'encoded slash' => 'https://example.com/c%2fx', 'backslash' => 'https://example.com/c\\x', 'space' => 'https://example.com/c x',
    'non-ascii' => "https://example.com/caf\u{e9}", 'upper-case host' => 'https://Example.com/c/x', 'too long' => 'https://example.com/' . str_repeat('a', 520), 'bad port' => 'https://example.com:99999/x',
    'ftp' => 'ftp://example.com/c/x', 'scheme-relative' => '//example.com/c/x', 'empty' => ''];
foreach ($bad as $label => $u) { $ok(!ClientMetadata::checkUrl($u)['ok'], "client_id refused: $label"); }
$g = ClientMetadata::checkUrl('https://claude.ai/oauth/mcp-oauth-client-metadata');
$ok($g['ok'] && $g['authority'] === 'claude.ai', 'Claude\'s published client_id is acceptable (authority claude.ai)');
$ok(ClientMetadata::checkUrl('https://example.com:8443/c/x')['authority'] === 'example.com:8443' && ClientMetadata::checkUrl('https://example.com:443/c/x')['authority'] === 'example.com', 'the port is part of the authority unless it is the https default');

echo "== redirect matching (no network) ==\n";
$entries = ['https://claude.ai/api/mcp/auth_callback', 'http://localhost/callback', 'http://127.0.0.1/callback'];
$rm = fn(string $r, array $e = null) => ClientMetadata::redirectMatches($e ?? $entries, $r);
$ok($rm('https://claude.ai/api/mcp/auth_callback'), 'the hosted Claude callback matches exactly');
$ok($rm('http://localhost:3118/callback') && $rm('http://127.0.0.1:54321/callback') && $rm('http://127.0.0.1/callback') && $rm('http://localhost/callback'), 'loopback entries match on any port (RFC 8252 7.3), localhost included');
$ok(!$rm('http://127.0.0.1:3118/other') && !$rm('http://127.0.0.1:3118/callback?x=1') && !$rm('http://127.0.0.1:3118/callback/'), 'loopback: path and query must still be identical');
$ok(!$rm('http://[::1]:3118/callback') && !$rm('http://127.0.0.2:3118/callback') && !$rm('http://localhost.evil.example:3118/callback'), 'loopback: only the listed host matches (no ::1 unless listed, no 127.0.0.2, no look-alike)');
$ok(!$rm('https://claude.ai:8443/api/mcp/auth_callback') && !$rm('https://claude.ai/api/mcp/auth_callback/') && !$rm('https://claude.com/api/mcp/auth_callback') && !$rm('https://claude.ai/api/mcp/auth_callback?x=1'), 'an https entry never gets a port, path, query or host wildcard');
$ok(!$rm('https://127.0.0.1:3118/callback') && !$rm('http://127.0.0.1:0/callback') && !$rm('http://127.0.0.1:70000/callback') && !$rm('javascript:alert(1)') && !$rm(''), 'loopback entry does not match https, port 0, port out of range, or junk');
$ok($rm('http://[::1]:5/cb', ['http://[::1]/cb']), 'a [::1] entry matches [::1] on any port');
$ok(ClientMetadata::cacheTtl('max-age=0') === 300 && ClientMetadata::cacheTtl('public, max-age=600') === 600 && ClientMetadata::cacheTtl('max-age=99999999') === 86400
    && ClientMetadata::cacheTtl(null) === 3600 && ClientMetadata::cacheTtl('no-store') === 3600 && ClientMetadata::cacheTtl('MAX-AGE=1200') === 1200, 'Cache-Control: honoured, floor 5 minutes, ceiling 24 hours, default 1 hour');

echo "== the fetcher's SSRF policy (default: private addresses refused) ==\n";
putenv('RIVETIT_WEBHOOK_ALLOW_PRIVATE');   // the switch off, as in production
foreach (['the mock itself on loopback' => $cValid, 'cloud metadata' => 'https://169.254.169.254/latest/meta-data', 'IPv6 loopback' => 'https://[::1]/c/x', 'localhost' => 'https://localhost/c/x',
    '10/8' => 'https://10.0.0.1/c/x', '192.168/16' => 'https://192.168.1.1/c/x', '172.16/12' => 'https://172.16.5.5/c/x', 'IPv4-mapped IPv6 loopback' => 'https://[::ffff:127.0.0.1]/c/x'] as $label => $u) {
    $r = CimdFetcher::fetch($u);
    $ok(!$r['ok'] && str_contains($r['error'], 'not allowed'), "fetch refused before connecting: $label");
}
$ok($allHits() === 0, 'the mock saw no request from any refused fetch');
$ok(CimdFetcher::fetch('http://example.com/c/x')['ok'] === false, 'http is refused by the fetcher itself');
putenv('RIVETIT_WEBHOOK_ALLOW_PRIVATE=1');

echo "== the fetcher against the mock ==\n";
$f = CimdFetcher::fetch($cValid);
$ok($f['ok'] && $f['cache_control'] === 'max-age=600', 'a good document is fetched (cache-control passed through)');
$expect = ['/c/redirect' => 'invalid', '/c/big' => 'invalid', '/c/html' => 'invalid', '/c/s404' => 'invalid', '/c/s500' => 'unavailable'];
foreach ($expect as $p => $kind) {
    $r = CimdFetcher::fetch($urlOf($p));
    $ok(!$r['ok'] && $r['kind'] === $kind, "fetch of $p fails as $kind (" . ($r['error'] ?? '') . ')');
}
$t0 = microtime(true);
$r = CimdFetcher::fetch($urlOf('/c/slow'));
$took = microtime(true) - $t0;
$ok(!$r['ok'] && $r['kind'] === 'unavailable' && $took < 5.9 && $took >= 4.5, 'a slow server is cut off at 5 seconds (took ' . round($took, 1) . ' s)');
$ok($hitsOf('/c/valid') === 1 && $hitsOf('/c/redirect') === 1, 'the redirect was not followed (/c/valid was fetched only once, directly)');

echo "== discovery and mode: off (default) ==\n";
$m = oa_http('GET', '/.well-known/oauth-authorization-server')['json'] ?? [];
$ok(!isset($m['client_id_metadata_document_supported']), 'mode off: metadata does not advertise client_id_metadata_document_supported');
$before = $allHits();
$r = $probe($cValid, $LOOP);
$ok($r['code'] === 400 && str_contains($r['body'], 'not registered') && $allHits() === $before && $metadataClients() === 0, 'mode off: a URL client_id is refused and nothing is fetched');
$t = oa_token(['grant_type' => 'authorization_code', 'client_id' => $cValid, 'code' => 'x', 'redirect_uri' => $LOOP, 'code_verifier' => str_repeat('a', 43)]);
$ok($t['code'] === 401 && ($t['json']['error'] ?? '') === 'invalid_client' && $allHits() === $before, 'mode off: the token endpoint refuses it too, without fetching');

echo "== mode: listed hosts ==\n";
$mode('hosts', ['claude.ai']);
$m = oa_http('GET', '/.well-known/oauth-authorization-server')['json'] ?? [];
$ok(($m['client_id_metadata_document_supported'] ?? null) === true && ($m['token_endpoint_auth_methods_supported'] ?? null) === ['none'] && ($m['code_challenge_methods_supported'] ?? null) === ['S256'],
    'metadata advertises client_id_metadata_document_supported: true, with "none" and S256 (what Claude requires before it uses CIMD)');
$r = $probe($cValid, $LOOP);
$ok($r['code'] === 400 && str_contains($r['body'], 'administrator trusts'), 'listed hosts: another host is refused');
$ok($allHits() === $before && $metadataClients() === 0, '... without fetching it or creating a client');
$mode('hosts', ['claude.ai', "127.0.0.1:$mport"]);

echo "== valid document: consent, code, PKCE, tokens (loopback, any port) ==\n";
$run = $connect($cValid, $LOOP);
$page = $run['consent']['page'];
$ok($page['code'] === 200 && str_contains($page['body'], 'Requested by ' . $authority) && str_contains($page['body'], 'Mock Claude'), 'consent screen shows the host of the client_id URL prominently, and the (self-chosen) name');
$ok(!str_contains($page['body'], 'Unverified application'), 'a host the administrator listed is not flagged unverified');
$ok(str_contains($page['body'], '<strong>this computer</strong>'), 'a loopback return address still gets the "this computer" warning');
$ok(!preg_match('/<img|logo/i', $page['body']), 'no image or logo from the document is ever displayed');
$tok = $run['tok'];
$ok($tok['code'] === 200 && !empty($tok['json']['access_token']) && !empty($tok['json']['refresh_token']), 'authorization code + PKCE exchanged for tokens with the URL as client_id');
$ok(($run['consent']['query']['iss'] ?? '') === "https://$host" && ($run['consent']['query']['state'] ?? null) === $run['params']['state'], 'the redirect carries state and iss, back to the loopback port the client asked for');
$ok($bearerCode($tok['json']['access_token'] ?? null) === 200, 'the access token works on /mcp');
$row = $clientRow($cValid);
$ok($row && $row['registration_type'] === 'metadata' && $row['client_name'] === 'Mock Claude' && count($row['redirect_uris']) === 3, 'the client appears in mcp_oauth_clients as a metadata-document client');
$ok(str_starts_with($row['client_id'], 'cimd_') && strlen($row['client_id']) <= 64 && $docRow($cValid)['source_url'] === $cValid, 'stored under a hash ID; the source URL is kept in the documents table');
$d = $docRow($cValid);
$ttl = strtotime($d['expires_at']) - strtotime($d['fetched_at']);
$ok($ttl >= 595 && $ttl <= 605, "cache lifetime follows Cache-Control: max-age=600 (got $ttl s)");
$ok($audits('mcp.oauth_client_registered') === 1 && $audits('mcp.oauth_consent_granted') === 1 && $audits('mcp.oauth_token_issued') === 1, 'audit: first seen once, consent granted, token issued');

echo "== refresh rotation, reuse detection, revocation work unchanged ==\n";
$rt1 = $tok['json']['refresh_token'];
$r2 = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cValid, 'refresh_token' => $rt1]);
$ok($r2['code'] === 200 && $r2['json']['refresh_token'] !== $rt1 && $bearerCode($r2['json']['access_token']) === 200, 'refresh rotates the refresh token');
$r3 = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cValid, 'refresh_token' => $rt1]);
$ok($r3['code'] === 400 && ($r3['json']['error'] ?? '') === 'invalid_grant', 'the old refresh token is refused');
$ok($bearerCode($r2['json']['access_token']) === 401 && $audits('mcp.oauth_reuse_detected') === 1, 'reuse revoked the whole connection (access token dead, audited)');
$run = $connect($cValid, 'http://localhost:3118/callback');
$ok(($run['tok']['code'] ?? 0) === 200, 'a second connection, localhost on another port, works');
$rev = oa_http('POST', '/oauth/revoke.php', ['form' => ['token' => $run['tok']['json']['refresh_token'], 'client_id' => $cValid]]);
$ok($rev['code'] === 200 && $bearerCode($run['tok']['json']['access_token']) === 401 && $audits('mcp.oauth_grant_revoked') >= 1, 'the revocation endpoint accepts the URL client_id and ends the connection');
$code2 = $connect($cValid, $LOOP, 'approve');
$ok(($code2['tok']['code'] ?? 0) === 200, 'a third connection works after the second was revoked');
$ok($bearerCode($run['tok']['json']['access_token']) === 401, 'and the revoked one stays dead');

echo "== redirect_uri must be one the document lists ==\n";
$cases = ['unlisted https host' => 'https://evil.example/cb', 'listed host, other path' => 'https://claude.example/cb2', 'listed host, other port' => 'https://claude.example:444/cb',
    'loopback, wrong path' => 'http://127.0.0.1:5555/evil', '[::1] not listed' => 'http://[::1]:5555/callback', 'loopback over https' => 'https://127.0.0.1:5555/callback'];
foreach ($cases as $label => $redir) {
    $r = $probe($cValid, $redir);
    $ok($r['code'] === 400 && str_contains($r['body'], 'return address') && $r['location'] === null, "refused (no redirect to it): $label");
}
$r = $probe($cValid, 'https://claude.example/cb');
$ok($r['code'] === 302 && str_contains((string) $r['location'], '/login.php'), 'a listed https redirect is accepted (the visitor goes on to sign in)');

echo "== loopback port matching is for metadata clients only ==\n";
$regId = 'rvtc_' . bin2hex(random_bytes(8));
$store->createClient($regId, 'Registered', ['http://127.0.0.1:8765/callback'], 'dynamic', '10.0.0.1', null);
$r = $probe($regId, 'http://127.0.0.1:8766/callback');
$ok($r['code'] === 400 && str_contains($r['body'], 'return address'), 'a registered client: another loopback port is refused (exact match)');
$r = $probe($regId, 'http://127.0.0.1:8765/callback');
$ok($r['code'] === 302, 'a registered client: its exact address is accepted');
$r = $probe($internal($cValid), $LOOP);
$ok($r['code'] === 400 && str_contains($r['body'], 'not registered'), 'a metadata client cannot be reached by its internal ID');
$t = oa_token(['grant_type' => 'refresh_token', 'client_id' => $internal($cValid), 'refresh_token' => 'x']);
$ok($t['code'] === 401, '... nor at the token endpoint');

echo "== refused documents (nothing is stored, each failure is audited) ==\n";
$baseline = $metadataClients();
$failBefore = $audits('mcp.oauth_cimd_fetch_failed');
$refused = ['client_id differs from the URL' => '/c/mismatch', 'redirect response' => '/c/redirect', 'oversized (70 KB)' => '/c/big', 'invalid JSON' => '/c/badjson', 'JSON list' => '/c/list',
    'not application/json' => '/c/html', 'HTTP 404' => '/c/s404', 'client_secret_basic' => '/c/basic', 'client_secret_post' => '/c/post', 'private_key_jwt + jwks_uri' => '/c/pkjwt',
    'inline jwks' => '/c/jwks', 'carries a client_secret' => '/c/secret', 'http redirect_uri' => '/c/evilredir', 'javascript: redirect_uri' => '/c/jsredir', 'empty redirect_uris' => '/c/noredir',
    'no authorization_code grant' => '/c/nogrant'];
foreach ($refused as $label => $p) {
    $r = $probe($urlOf($p), $LOOP);
    $ok($r['code'] === 400 && str_contains($r['body'], 'metadata document is not acceptable'), "refused: $label");
}
$r = $probe($urlOf('/c/s500'), $LOOP);
$ok($r['code'] === 503 && str_contains($r['body'], 'could not be fetched'), 'HTTP 500 from the host: refused as temporarily unavailable (503)');
$t0 = microtime(true);
$r = $probe($urlOf('/c/slow'), $LOOP);
$ok($r['code'] === 503 && microtime(true) - $t0 < 8, 'a slow document is refused as unavailable after the 5 second limit');
$ok($metadataClients() === $baseline, 'none of them created a client row');
$ok($mysqli->query("SELECT COUNT(*) FROM mcp_oauth_client_documents WHERE source_url LIKE '%/c/%' AND source_url NOT LIKE '%/c/valid'")->fetch_row()[0] === '0', 'none of them left a cached document');
$ok($audits('mcp.oauth_cimd_fetch_failed') - $failBefore >= count($refused), 'every failure was audited (mcp.oauth_cimd_fetch_failed)');
$leak = (int) $mysqli->query("SELECT COUNT(*) FROM audit_events WHERE summary LIKE '%S3CR3T%' OR metadata_json LIKE '%S3CR3T%' OR summary LIKE '%max-age%'")->fetch_row()[0];
$ok($leak === 0, 'the audit trail holds the reason and the host, never the document contents or a secret it carried');
foreach (['http' => 'http://127.0.0.1:' . $mport . '/c/valid', 'dot segments' => $urlOf('/c/../c/valid'), 'fragment' => $urlOf('/c/valid') . '#x', 'credentials' => "https://u:p@$authority/c/valid", 'no path' => "https://$authority"] as $label => $u) {
    $h0 = $allHits();
    $r = $probe($u, $LOOP);
    $ok($r['code'] === 400 && $allHits() === $h0, "URL refused before any fetch: $label");
}

echo "== caching: honoured, refetched on expiry, fail closed ==\n";
$h = $hitsOf('/c/valid');
$probe($cValid, $LOOP);
oa_token(['grant_type' => 'refresh_token', 'client_id' => $cValid, 'refresh_token' => 'unknown']);
$ok($hitsOf('/c/valid') === $h, 'within the cache lifetime neither the authorization nor the token endpoint fetches again');
foreach (['/c/nocc' => 3600, '/c/noStore' => 300, '/c/long' => 86400] as $p => $want) {
    $probe($urlOf($p), $LOOP);
    $d = $docRow($urlOf($p));
    $got = $d ? strtotime($d['expires_at']) - strtotime($d['fetched_at']) : -1;
    $ok(abs($got - $want) <= 5, "$p: cache lifetime $want s (no header: 1 h; no-store/max-age=0: floor 5 min; huge max-age: ceiling 24 h), got $got");
}
$mysqli->query("UPDATE mcp_oauth_client_documents SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE client_id = '" . $internal($cValid) . "'");
$serve('/c/valid', $good(['client_name' => 'Renamed Claude', 'redirect_uris' => ['https://claude.example/cb', 'http://127.0.0.1/callback']]));
$probe($cValid, $LOOP);
$ok($hitsOf('/c/valid') === $h + 1 && $clientRow($cValid)['client_name'] === 'Renamed Claude' && count($clientRow($cValid)['redirect_uris']) === 2, 'after expiry the document is fetched again and the name and redirect list are refreshed');
$ok($audits('mcp.oauth_client_registered') === 1 + 3, 'a refetch is not a first sight (audited once per client; the 3 others are the cached-TTL probes above)');
$r = $probe($cValid, 'http://localhost:3118/callback');
$ok($r['code'] === 400, 'the removed localhost entry no longer matches');
$serve('/c/valid', $good());
$mysqli->query("UPDATE mcp_oauth_client_documents SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE client_id = '" . $internal($cValid) . "'");
$run = $connect($cValid, $LOOP);
$liveRefresh = $run['tok']['json']['refresh_token'] ?? '';
$ok(($run['tok']['code'] ?? 0) === 200, 'refetched and working again');
$serve('/c/valid', ['status' => 500, 'headers' => $json, 'body' => '{}']);
$mysqli->query("UPDATE mcp_oauth_client_documents SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE client_id = '" . $internal($cValid) . "'");
$r = $probe($cValid, $LOOP);
$ok($r['code'] === 503, 'expired copy and the fetch fails: authorization fails closed');
$t = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cValid, 'refresh_token' => $liveRefresh]);
$ok($t['code'] === 503 && ($t['json']['error'] ?? '') === 'temporarily_unavailable', 'expired copy and the fetch fails: the token endpoint fails closed with a retryable 503 (the refresh token is not burned)');
$ok(!empty($docRow($cValid)['last_error']), 'the failure is recorded on the app for the administrator');
$serve('/c/valid', $good(['client_id' => 'https://elsewhere.example/c/valid']));
$mysqli->query("UPDATE mcp_oauth_client_documents SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE client_id = '" . $internal($cValid) . "'");
$t = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cValid, 'refresh_token' => $liveRefresh]);
$ok($t['code'] === 401 && ($t['json']['error'] ?? '') === 'invalid_client', 'a document that now names a different client_id is refused at the token endpoint (invalid_client)');
$serve('/c/valid', $good());
$mysqli->query("UPDATE mcp_oauth_client_documents SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE client_id = '" . $internal($cValid) . "'");
$t = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cValid, 'refresh_token' => $liveRefresh]);
$ok($t['code'] === 200, 'once the host serves a valid document again the same refresh token still works');
$liveAccess = $t['json']['access_token'] ?? '';
$liveRefresh = $t['json']['refresh_token'] ?? '';

echo "== trust policy ==\n";
$mode('hosts', ['claude.ai']);
$r = $probe($cValid, $LOOP);
$ok($r['code'] === 400, 'host removed from the list: new authorizations are refused even with a fresh cached copy');
$t = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cValid, 'refresh_token' => $liveRefresh]);
$ok($t['code'] === 401, '... and so is the token endpoint');
$mode('off');
$ok($probe($cValid, $LOOP)['code'] === 400, 'mode off again: refused');
$mode('any');
$page = oa_consent($alice, $authParams($cValid, oa_pkce()[1], $LOOP))['page'];
$ok($page['code'] === 200 && str_contains($page['body'], 'Unverified application'), 'any host: a host the administrator did not list gets the "unverified application" notice');
$mode('any', ["127.0.0.1:$mport"]);
$page = oa_consent($alice, $authParams($cValid, oa_pkce()[1], $LOOP))['page'];
$ok($page['code'] === 200 && !str_contains($page['body'], 'Unverified application'), 'any host: a listed host is not flagged');
$mode('any');
$other = $probe($urlOf('/c/nocc'), $LOOP);
$ok($other['code'] === 302, 'any host: an unlisted host is accepted');
$mode('hosts', ["127.0.0.1:$mport"]);

echo "== a disabled client is blocked even while its document stays valid ==\n";
$run = $connect($cValid, $LOOP);
$acc = $run['tok']['json']['access_token'] ?? '';
$ref = $run['tok']['json']['refresh_token'] ?? '';
$ok($bearerCode($acc) === 200, 'connected (precondition)');
$h = $allHits();
$store->setClientDisabled($internal($cValid), true);
$ok($bearerCode($acc) === 401, 'disabled: the existing access token stops working at once');
$t = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cValid, 'refresh_token' => $ref]);
$ok($t['code'] === 401 && ($t['json']['error'] ?? '') === 'invalid_client', 'disabled: refresh refused');
$r = $probe($cValid, $LOOP);
$ok($r['code'] === 400 && $allHits() === $h, 'disabled: new authorizations refused, without even fetching the document');
$ok($clientRow($cValid)['disabled_at'] !== null && $docRow($cValid) !== null, 'the document is still cached and valid (it is the flag that blocks)');
$store->setClientDisabled($internal($cValid), false);
$t = oa_token(['grant_type' => 'refresh_token', 'client_id' => $cValid, 'refresh_token' => $ref]);
$ok($t['code'] === 200, 'enabled again: the connection works');

echo "== hostile document content is escaped everywhere ==\n";
$xssUrl = $urlOf('/c/xss');
$run = $connect($xssUrl, $LOOP);
$body = $run['consent']['page']['body'];
$ok($run['consent']['page']['code'] === 200 && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($body, '<script>alert(1)'), 'consent screen: client_name is HTML-escaped');
$admin = oa_http('GET', '/admin/settings_mcp.php', ['jar' => $alice]);
$ok($admin['code'] === 200 && str_contains($admin['body'], '&lt;script&gt;alert(1)') && !str_contains($admin['body'], '<script>alert(1)'), 'administrator page: escaped (Active connections and Registered apps)');
$ok(str_contains($admin['body'], 'Metadata document') && str_contains($admin['body'], htmlspecialchars($cValid)), 'Registered apps marks metadata-document clients and shows the source URL');
$sec = oa_http('GET', '/agent/user/user_security.php', ['jar' => $alice]);
$ok($sec['code'] === 200 && str_contains($sec['body'], 'Connected AI tools') && !str_contains($sec['body'], '<script>alert(1)') && str_contains($sec['body'], 'from ' . '127.0.0.1'), 'Account > Security: escaped, and shows which host the app came from');

echo "== housekeeping ==\n";
$oldUnused = $urlOf('/c/long');
$mysqli->query("UPDATE mcp_oauth_clients SET created_at = UTC_TIMESTAMP() - INTERVAL 30 DAY WHERE client_id = '" . $internal($oldUnused) . "'");
$mysqli->query("UPDATE mcp_oauth_clients SET created_at = UTC_TIMESTAMP() - INTERVAL 30 DAY WHERE client_id = '" . $internal($urlOf('/c/nocc')) . "'");
$store->setClientDisabled($internal($urlOf('/c/nocc')), true);
$store->purgeUnusedMetadataClients(7 * 86400);
$ok($clientRow($oldUnused) === null && $docRow($oldUnused) === null, 'an old, never-used metadata client (and its document) is purged');
$ok($clientRow($urlOf('/c/nocc')) !== null, 'an administrator-disabled one is kept so the block survives');
$ok($clientRow($cValid) !== null, 'a client with connections is kept');
$store->deleteClient($internal($xssUrl));
$ok($clientRow($xssUrl) === null && $docRow($xssUrl) === null, 'Delete removes the client, its document and its connections');

oa_finish();
