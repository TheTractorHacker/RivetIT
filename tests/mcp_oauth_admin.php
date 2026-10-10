<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Remote MCP built-in OAuth server: the administrator page (Administration > Settings > Remote MCP) and the per-person
 * "Connected AI tools" section on Account > Security, driven through the real pages and post handlers over HTTP against a
 * private `php -S` server. Same DISPOSABLE database requirements as tests/mcp_oauth_flow.php.
 */
require_once __DIR__ . '/mcp_oauth_lib.php';

use ITFlow\Mcp\OAuth\OAuthStore;

$port = 18000 + random_int(100, 900);
oa_seed_base($mysqli);
oa_clean($mysqli);
oa_seed_user($mysqli, 9101, 'Alice Admin', 'alice@oa.test', 'pw-Alice-1234!', 91);
oa_seed_user($mysqli, 9102, 'Tom Tech', 'tom@oa.test', 'pw-Tom-1234!', 92);
$resource = 'https://' . $OA['host'] . '/mcp';
oa_start_server($port);

$store = new OAuthStore($mysqli);
$store->createClient('rvtc_adminpagetest1', 'Claude (page test)', ['https://claude.ai/api/mcp/auth_callback'], 'dynamic', '10.0.0.5', null);
$gAlice = $store->createGrant('rvtc_adminpagetest1', 9101, 'mcp:read', $resource, OAuthStore::hash('origin-a'));
$gTom = $store->createGrant('rvtc_adminpagetest1', 9102, 'mcp:read', $resource, OAuthStore::hash('origin-t'));
$live = fn(int $id) => $store->grantIsLive($id);
$cfg = fn(string $k) => $mysqli->query("SELECT setting_value FROM mcp_oauth_config WHERE setting_key = '$k'")->fetch_row()[0] ?? null;
$csrfOf = function (string $body): string { preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m); return html_entity_decode($m[1] ?? ''); };
$base = 'http://' . $OA['host'] . ':' . $port;

echo "== administrator page ==\n";
$admin = oa_jar();
$ok(oa_login($admin, 'alice@oa.test', 'pw-Alice-1234!'), 'administrator signs in');
$page = oa_http('GET', '/admin/settings_mcp.php', ['jar' => $admin]);
$ok($page['code'] === 200 && str_contains($page['body'], 'Built-in sign-in') && str_contains($page['body'], 'Registered apps') && str_contains($page['body'], 'Active connections'), 'the page shows the built-in sign-in, registered apps and active connections');
$ok(str_contains($page['body'], 'Claude (page test)') && str_contains($page['body'], 'Tom Tech') && str_contains($page['body'], 'Alice Admin'), 'it lists the client and who connected');
$ok(str_contains($page['body'], 'https://' . $OA['host'] . '/.well-known/oauth-authorization-server') && str_contains($page['body'], 'location = /.well-known/oauth-authorization-server'), 'it shows the metadata address and the nginx block for it');
$ok(!str_contains($page['body'], 'People who tried to connect'), 'external-provider cards are hidden while the built-in sign-in is on');
$csrf = $csrfOf($page['body']);
$post = fn(string $path, array $form, string $jar = null, string $referer = '/admin/settings_mcp.php') => oa_http('POST', $path, ['jar' => $jar ?? $admin, 'form' => $form, 'headers' => ['Referer: ' . $base . $referer]]);

$post('/admin/post.php', ['csrf_token' => 'forged', 'save_mcp_oauth_settings' => 1]);
$ok($cfg('builtin_enabled') === '1', 'a forged CSRF token changes nothing');
$post('/admin/post.php', ['csrf_token' => $csrf, 'save_mcp_oauth_settings' => 1, 'mcp_oauth_builtin' => 1]);
$ok($cfg('registration_enabled') === '0' && $cfg('builtin_enabled') === '1', 'Disable registration: the unchecked switch is saved off');
$ok(oa_register()[0]['code'] === 403, '... and the endpoint refuses self-registration');
$post('/admin/post.php', ['csrf_token' => $csrf, 'save_mcp_oauth_settings' => 1, 'mcp_oauth_builtin' => 1, 'mcp_oauth_registration' => 1]);
$ok($cfg('registration_enabled') === '1' && oa_register()[0]['code'] === 201, 'registration switched back on');
oa_reset_limits('*rl:oauth:*');

$post('/admin/post.php', ['csrf_token' => $csrf, 'add_mcp_oauth_client' => 1, 'client_name' => 'ToolHive', 'redirect_uris' => "http://localhost:8666/callback\nhttp://127.0.0.1:8666/callback", 'client_id' => 'toolhive-preset-001']);
$tc = $store->client('toolhive-preset-001');
$ok($tc && $tc['registration_type'] === 'manual' && count($tc['redirect_uris']) === 2, 'admin pre-registers a client with a chosen client id');
$post('/admin/post.php', ['csrf_token' => $csrf, 'add_mcp_oauth_client' => 1, 'client_name' => 'Evil', 'redirect_uris' => 'http://evil.example/cb', 'client_id' => 'evil-client-0001']);
$ok($store->client('evil-client-0001') === null, 'a non-https, non-loopback redirect is refused here too');
$post('/admin/post.php', ['csrf_token' => $csrf, 'toggle_mcp_oauth_client' => 1, 'client_id' => 'toolhive-preset-001']);
$ok($store->client('toolhive-preset-001')['disabled_at'] !== null, 'Disable client');
$post('/admin/post.php', ['csrf_token' => $csrf, 'toggle_mcp_oauth_client' => 1, 'client_id' => 'toolhive-preset-001']);
$ok($store->client('toolhive-preset-001')['disabled_at'] === null, 'Enable client again');
$post('/admin/post.php', ['csrf_token' => $csrf, 'delete_mcp_oauth_client' => 1, 'client_id' => 'toolhive-preset-001']);
$ok($store->client('toolhive-preset-001') === null, 'Delete client');
$post('/admin/post.php', ['csrf_token' => $csrf, 'revoke_mcp_oauth_grant' => 1, 'grant_id' => $gAlice]);
$ok(!$live($gAlice) && $live($gTom), 'administrator revokes one connection and only that one');

echo "== main settings with the built-in sign-in ==\n";
$q("UPDATE settings SET config_module_enable_mcp = 0, config_mcp_issuer = '', config_mcp_audience = '' WHERE company_id = 1");
$post('/admin/post.php', ['csrf_token' => $csrf, 'save_mcp_settings' => 1, 'mcp_enabled' => 1, 'mcp_issuer' => '', 'mcp_audience' => '']);
$ok((int) $mysqli->query('SELECT config_module_enable_mcp FROM settings WHERE company_id = 1')->fetch_row()[0] === 1, 'Remote MCP can be turned on without an issuer or audience while the built-in sign-in is on');
$q("UPDATE mcp_oauth_config SET setting_value = '0' WHERE setting_key = 'builtin_enabled'");
$q("UPDATE settings SET config_module_enable_mcp = 0 WHERE company_id = 1");
$post('/admin/post.php', ['csrf_token' => $csrf, 'save_mcp_settings' => 1, 'mcp_enabled' => 1, 'mcp_issuer' => '', 'mcp_audience' => '']);
$ok((int) $mysqli->query('SELECT config_module_enable_mcp FROM settings WHERE company_id = 1')->fetch_row()[0] === 0, 'with the built-in sign-in off, the external issuer and audience are still required to turn it on');
$off = oa_http('GET', '/admin/settings_mcp.php', ['jar' => $admin]);
$ok($off['code'] === 200 && str_contains($off['body'], 'People who tried to connect') && !str_contains($off['body'], 'Active connections'), 'built-in off: the page is the external-provider page again');
$q("UPDATE mcp_oauth_config SET setting_value = '1' WHERE setting_key = 'builtin_enabled'");
$q("UPDATE settings SET config_module_enable_mcp = 1 WHERE company_id = 1");

echo "== a non-administrator ==\n";
$tom = oa_jar();
$ok(oa_login($tom, 'tom@oa.test', 'pw-Tom-1234!'), 'agent signs in');
$adm = oa_http('GET', '/admin/settings_mcp.php', ['jar' => $tom]);
$ok($adm['code'] !== 200 || !str_contains($adm['body'], 'Registered apps'), 'a non-administrator cannot open the administrator page');
$tomCsrf = $csrfOf(oa_http('GET', '/agent/user/user_security.php', ['jar' => $tom])['body']);
$post('/admin/post.php', ['csrf_token' => $tomCsrf, 'revoke_mcp_oauth_grant' => 1, 'grant_id' => $gTom], $tom);
$ok($live($gTom), 'a non-administrator cannot use the administrator handler to revoke');
$post('/admin/post.php', ['csrf_token' => $tomCsrf, 'save_mcp_oauth_settings' => 1], $tom);
$ok($cfg('builtin_enabled') === '1', 'a non-administrator cannot switch the built-in sign-in off');

echo "== each person's own connections ==\n";
$sec = oa_http('GET', '/agent/user/user_security.php', ['jar' => $tom]);
$ok($sec['code'] === 200 && str_contains($sec['body'], 'Connected AI tools') && str_contains($sec['body'], 'Claude (page test)'), 'Account > Security lists the person\'s connected AI tools');
$own = $store->createGrant('rvtc_adminpagetest1', 9101, 'mcp:read', $resource, OAuthStore::hash('origin-a2'));
$post('/agent/user/post.php', ['csrf_token' => $tomCsrf, 'revoke_mcp_connection' => 1, 'mcp_grant_id' => $own], $tom, '/agent/user/user_security.php');
$ok($live($own), "a person cannot remove someone else's connection");
$post('/agent/user/post.php', ['csrf_token' => 'forged', 'revoke_mcp_connection' => 1, 'mcp_grant_id' => $gTom], $tom, '/agent/user/user_security.php');
$ok($live($gTom), 'removing a connection needs a valid CSRF token');
$post('/agent/user/post.php', ['csrf_token' => $tomCsrf, 'revoke_mcp_connection' => 1, 'mcp_grant_id' => $gTom], $tom, '/agent/user/user_security.php');
$ok(!$live($gTom), 'a person removes their own connection');
$sec2 = oa_http('GET', '/agent/user/user_security.php', ['jar' => $tom]);
$ok(!str_contains($sec2['body'], 'Claude (page test)'), '... and it disappears from their list');

echo "== audit trail ==\n";
$ev = array_column($mysqli->query("SELECT DISTINCT event_type FROM audit_events WHERE event_type LIKE 'mcp.oauth_%'")->fetch_all(MYSQLI_ASSOC), 'event_type');
foreach (['mcp.oauth_settings_changed', 'mcp.oauth_client_registered', 'mcp.oauth_client_disabled', 'mcp.oauth_client_enabled', 'mcp.oauth_client_deleted', 'mcp.oauth_grant_revoked'] as $e) {
    $ok(in_array($e, $ev, true), "audit event recorded: $e");
}
oa_clean($mysqli);
oa_finish();
