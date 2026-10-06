<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Remote MCP admin pieces (config, identity linking, unlinked capture, health checks).
 * Needs a DISPOSABLE schema-only database that already has migration 2.6.123 applied:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/mcp_admin.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../mcp_server/McpIdentityMiddleware.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as R;
use ITFlow\Mcp\{McpConfig, McpDiagnostics, McpIdentityLinks};

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$fail = 0;
$ok = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fail++; };
$ISS = 'https://auth.example.test/application/o/rivetit-mcp/';

// ---- seed (relaxed mode only so the sparse settings row can be inserted)
$db->query("SET SESSION sql_mode=''");
$db->query("INSERT INTO settings (company_id, config_module_enable_mcp) VALUES (1, 0)");
foreach ([[9101, 'Ann Agent', 'ann@example.test', 1, 1], [9102, 'Bob Agent', 'bob@example.test', 1, 1], [9103, 'Off Agent', 'off@example.test', 1, 0], [9104, 'Portal Person', 'p@example.test', 2, 1]] as [$id, $n, $e, $t, $s]) {
    $db->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_auth_method, user_type, user_status) VALUES ($id, '$n', '$e', '', 'local', $t, $s)");
}

// ---- McpConfig
$c = McpConfig::load($db);
$ok($c['schema_ready'] && !$c['enabled'] && !$c['configured'], 'fresh install: off and not configured');
$db->query("UPDATE settings SET config_module_enable_mcp = 1, config_mcp_issuer = '$ISS', config_mcp_audience = 'client-abc' WHERE company_id = 1");
$c = McpConfig::load($db);
$ok($c['enabled'] && $c['configured'] && $c['issuer'] === $ISS && $c['audience'] === 'client-abc', 'settings enable it without any env vars');
putenv('RIVETIT_MCP_ENABLED=0'); $ok(!McpConfig::load($db)['enabled'] && McpConfig::load($db)['killed'], 'RIVETIT_MCP_ENABLED=0 is a hard off'); putenv('RIVETIT_MCP_ENABLED');
putenv('RIVETIT_MCP_AUDIENCE=env-aud'); $e = McpConfig::load($db); $ok($e['audience'] === 'env-aud' && $e['audience_from_env'], 'env var overrides stored audience'); putenv('RIVETIT_MCP_AUDIENCE');
$ok(!McpConfig::issuerValid('http://x.test/') && !McpConfig::issuerValid('https://u:p@x.test/') && !McpConfig::issuerValid('https://x.test/?a=1') && McpConfig::issuerValid($ISS), 'issuer validation');
$ok(!McpConfig::audienceValid('has space') && !McpConfig::audienceValid('') && McpConfig::audienceValid('abc'), 'audience validation');

// ---- unlinked capture
McpIdentityLinks::recordUnlinked($db, $ISS, 'sub-1', ['email' => "ann@example.test\n", 'name' => "Ann\x00 A"]);
McpIdentityLinks::recordUnlinked($db, $ISS, 'sub-1', []);
$p = McpIdentityLinks::pending($db);
$ok(count($p) === 1 && (int) $p[0]['attempts'] === 2, 'repeat sign-in updates one row (attempts=2)');
$ok($p[0]['email'] === 'ann@example.test' && $p[0]['display_name'] === 'Ann A', 'control characters stripped; later call without claims keeps earlier email');
for ($i = 0; $i < 210; $i++) McpIdentityLinks::recordUnlinked($db, $ISS, "flood-$i", []);
$n = (int) $db->query('SELECT COUNT(*) FROM mcp_unlinked_identities')->fetch_row()[0];
$ok($n === 200, "pending list capped at 200 (got $n)");
McpIdentityLinks::recordUnlinked($db, $ISS, 'sub-1', []);
$ok((int) $db->query("SELECT attempts FROM mcp_unlinked_identities WHERE subject='sub-1'")->fetch_row()[0] === 3, 'existing identity still updates when list is full');
$db->query("DELETE FROM mcp_unlinked_identities WHERE subject LIKE 'flood-%'");

// ---- linking
$pid = (int) $db->query("SELECT mcp_unlinked_id FROM mcp_unlinked_identities WHERE subject='sub-1'")->fetch_row()[0];
[$r, $m] = McpIdentityLinks::link($db, $pid, 9103); $ok(!$r, 'cannot link a disabled agent');
[$r, $m] = McpIdentityLinks::link($db, $pid, 9104); $ok(!$r, 'cannot link a portal (type 2) login');
[$r, $m] = McpIdentityLinks::link($db, $pid, 9101); $ok($r, 'links an active agent');
$u = $db->query('SELECT user_oidc_issuer, user_oidc_subject FROM users WHERE user_id = 9101')->fetch_assoc();
$ok($u['user_oidc_issuer'] === $ISS && $u['user_oidc_subject'] === 'sub-1', 'agent now carries issuer and subject');
$ok((int) $db->query("SELECT COUNT(*) FROM mcp_unlinked_identities WHERE subject='sub-1'")->fetch_row()[0] === 0, 'pending row removed after link');
[$r] = McpIdentityLinks::link($db, $pid, 9102); $ok(!$r, 'same pending id cannot be linked twice');
McpIdentityLinks::recordUnlinked($db, $ISS, 'sub-2', []);
$pid2 = (int) $db->query("SELECT mcp_unlinked_id FROM mcp_unlinked_identities WHERE subject='sub-2'")->fetch_row()[0];
[$r] = McpIdentityLinks::link($db, $pid2, 9101); $ok(!$r, 'cannot link a second identity onto an already-linked agent');
$db->query("UPDATE users SET user_oidc_issuer='$ISS', user_oidc_subject='sub-2' WHERE user_id = 9104");
[$r] = McpIdentityLinks::link($db, $pid2, 9102); $ok(!$r, 'cannot reuse an identity another account already holds');
$db->query("UPDATE users SET user_oidc_issuer=NULL, user_oidc_subject=NULL WHERE user_id = 9104");
$ids = array_column(McpIdentityLinks::linkableAgents($db), 'user_id');
$ok(in_array(9102, array_map('intval', $ids)) && !in_array(9101, array_map('intval', $ids)) && !in_array(9103, array_map('intval', $ids)) && !in_array(9104, array_map('intval', $ids)), 'linkable list: active, unlinked agents only');
$ok(array_map('intval', array_column(McpIdentityLinks::linkedAgents($db), 'user_id')) === [9101], 'linked list shows only linked agents');

// ---- middleware: unmapped valid token is captured, mapped one passes
$handler = new class implements Psr\Http\Server\RequestHandlerInterface {
    public function handle(Psr\Http\Message\ServerRequestInterface $r): Psr\Http\Message\ResponseInterface { return new Nyholm\Psr7\Response(200); }
};
$mw = new McpIdentityMiddleware($db, $ISS, 'client-abc');
$req = fn(string $sub, array $extra = []) => (new Nyholm\Psr7\ServerRequest('POST', 'https://x.test/mcp'))
    ->withAttribute('oauth.subject', $sub)->withAttribute('oauth.scopes', ['mcp:read'])
    ->withAttribute('oauth.claims', $extra + ['aud' => 'client-abc', 'iat' => time(), 'exp' => time() + 300]);
$ok($mw->process($req('sub-1'), $handler)->getStatusCode() === 200, 'linked person passes the middleware');
$ok($mw->process($req('new-person', ['email' => 'new@example.test', 'name' => 'New Person']), $handler)->getStatusCode() === 403, 'unlinked person is refused (403)');
$row = $db->query("SELECT email, display_name FROM mcp_unlinked_identities WHERE subject='new-person'")->fetch_assoc();
$ok($row && $row['email'] === 'new@example.test' && $row['display_name'] === 'New Person', '...and appears in the pending list with name and email');
$mw->process($req('bad-aud', ['aud' => 'other']), $handler);
$ok((int) $db->query("SELECT COUNT(*) FROM mcp_unlinked_identities WHERE subject='bad-aud'")->fetch_row()[0] === 0, 'wrong-audience token is NOT recorded');
$mw->process($req('too-long', ['exp' => time() + 7200]), $handler);
$ok((int) $db->query("SELECT COUNT(*) FROM mcp_unlinked_identities WHERE subject='too-long'")->fetch_row()[0] === 0, 'over-long-lived token is NOT recorded');
$db->query('DROP TABLE mcp_unlinked_identities');
$ok($mw->process($req('after-drop'), $handler)->getStatusCode() === 403, 'missing table (pre-migration): still a clean 403, no error');

// ---- unlink / dismiss
McpIdentityLinks::unlink($db, 9101);
$u = $db->query('SELECT user_oidc_issuer, user_oidc_subject FROM users WHERE user_id = 9101')->fetch_assoc();
$ok($u['user_oidc_subject'] === null && $u['user_oidc_issuer'] === null, 'unlink clears the identity');

// ---- diagnostics (mock identity provider)
$doc = fn(array $o = []) => new R(200, [], json_encode($o + ['issuer' => $ISS, 'jwks_uri' => 'https://auth.example.test/jwks/', 'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => ['openid', 'mcp:read']]));
$jwks = fn(array $keys) => new R(200, [], json_encode(['keys' => $keys]));
$diag = function (array $responses) use ($db) {
    return new McpDiagnostics($db, new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
};
$by = fn(array $checks, string $label) => array_values(array_filter($checks, fn($c) => $c['label'] === $label))[0]['status'] ?? 'missing';
$cfg = McpConfig::load($db); $cfg['enabled'] = false;

$out = $diag([$doc(), $jwks([['kty' => 'RSA', 'use' => 'sig', 'kid' => 'a']])])->run($cfg, 'rivet.example.test');
$ok($by($out, 'Identity provider reachable') === 'ok' && $by($out, 'Issuer matches exactly') === 'ok' && $by($out, 'Signing keys') === 'ok' && $by($out, 'PKCE supported') === 'ok' && $by($out, 'mcp:read scope') === 'ok', 'healthy provider: all provider checks pass');
$ok($by($out, 'This server answers /mcp') === 'skip', 'route check skipped while module is off');

$out = $diag([$doc(['issuer' => 'https://auth.example.test/application/o/rivetit-mcp']), $jwks([['kty' => 'RSA']])])->run($cfg, 'h');
$ok($by($out, 'Issuer matches exactly') === 'fail', 'missing trailing slash is caught');
$out = $diag([$doc(), $jwks([['kty' => 'oct', 'k' => 'x']])])->run($cfg, 'h');
$ok($by($out, 'Signing keys') === 'fail', 'HS256-style (oct) keys only -> fail with Signing Key advice');
$out = $diag([new R(404)])->run($cfg, 'h');
$ok($by($out, 'Identity provider reachable') === 'fail' && $by($out, 'Issuer matches exactly') === 'missing', 'unreachable provider: one clear failure');
$out = $diag([$doc(['code_challenge_methods_supported' => [], 'scopes_supported' => ['openid']]), $jwks([['kty' => 'RSA']])])->run($cfg, 'h');
$ok($by($out, 'PKCE supported') === 'warn' && $by($out, 'mcp:read scope') === 'warn', 'no PKCE / no mcp:read scope -> warnings');

$on = $cfg; $on['enabled'] = true; $on['module_on'] = true;
$meta = new R(200, [], json_encode(['authorization_servers' => [$ISS]]));
$out = $diag([$doc(), $jwks([['kty' => 'RSA']]), $meta, new R(401, ['WWW-Authenticate' => 'Bearer resource_metadata="x"'])])->run($on, 'h');
$ok($by($out, 'This server answers /mcp') === 'ok', 'route check passes when /mcp challenges with 401');
$out = $diag([$doc(), $jwks([['kty' => 'RSA']]), new R(404)])->run($on, 'h');
$ok($by($out, 'This server answers /mcp') === 'fail', 'route check fails on 404 (nginx routes missing)');
$out = $diag([$doc(), $jwks([['kty' => 'RSA']]), $meta, new R(200, [], '{}')])->run($on, 'h');
$ok($by($out, 'This server answers /mcp') === 'warn', 'route check warns when /mcp does not challenge');

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
