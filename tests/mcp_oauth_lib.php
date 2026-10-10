<?php
/*
 * Shared helpers for the Remote MCP built-in OAuth tests (tests/mcp_oauth_*.php): a private `php -S` server, a small curl
 * client that behaves like an MCP client (cookie jar for the browser step, PKCE, JSON-RPC over Streamable HTTP), and seed
 * helpers. Test use only; CLI only; refuses any database whose name does not contain "scratch" or "test".
 *
 * The app answers with https:// URLs (it is meant to sit behind TLS). The private server is plain HTTP, so every request here
 * maps https://<config_base_url> to http://<config_base_url>:<port>, with the name resolved to 127.0.0.1 by curl. That mapping
 * is the only thing this harness does that a real MCP client would not.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "Set RIVETIT_TEST_DB=1 and the RIVETIT_TEST_DB_* variables (scratch database only).\n"); exit(2); }
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }

require_once __DIR__ . '/../vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));

/** config.php must point at the same scratch database the test seeds: the server child reads it. */
function oa_config(): array
{
    $src = (string) file_get_contents(__DIR__ . '/../config.php');
    preg_match("/\\\$config_base_url = '([^']+)'/", $src, $host);
    preg_match("/\\\$database = '([^']+)'/", $src, $db);
    if (empty($host[1]) || ($db[1] ?? '') !== getenv('RIVETIT_TEST_DB_NAME')) {
        fwrite(STDERR, "config.php must use the same scratch database as RIVETIT_TEST_DB_NAME\n");
        exit(2);
    }
    return ['host' => $host[1], 'db' => $db[1]];
}

$OA = ['host' => oa_config()['host'], 'port' => 0, 'proc' => null];
$fail = 0;
$pass = 0;
$ok = function (bool $cond, string $label) use (&$fail, &$pass): bool {
    echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
    $cond ? $pass++ : $fail++;
    return $cond;
};
$q = fn(string $sql) => $mysqli->query($sql);

function oa_start_server(int $port): void
{
    global $OA;
    $OA['port'] = $port;
    $root = dirname(__DIR__);
    $log = sys_get_temp_dir() . '/mcp_oauth_server_' . $port . '.log';
    $OA['proc'] = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, __DIR__ . '/mcp_oauth_router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']], $pipes, $root);
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $e, $m, 0.2);
        if ($s) { fclose($s); return; }
        usleep(100000);
    }
    fwrite(STDERR, "private server did not start (see $log)\n");
    exit(2);
}

function oa_stop_server(): void
{
    global $OA;
    if (is_resource($OA['proc'] ?? null)) {
        $st = proc_get_status($OA['proc']);
        if (!empty($st['pid'])) { posix_kill($st['pid'], SIGTERM); }
        proc_close($OA['proc']);
        $OA['proc'] = null;
    }
}

/** https://<base_url>/x (as the server publishes it) or /x -> the private server's URL. */
function oa_url(string $pathOrUrl): string
{
    global $OA;
    $own = 'https://' . $OA['host'];
    if (str_starts_with($pathOrUrl, $own)) { $pathOrUrl = substr($pathOrUrl, strlen($own)); }
    if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) { return $pathOrUrl; }
    return 'http://' . $OA['host'] . ':' . $OA['port'] . $pathOrUrl;
}

/**
 * @param array{headers?:list<string>,body?:string,form?:array,json?:mixed,jar?:string,host?:string} $o
 * @return array{code:int,headers:array<string,string>,body:string,json:mixed,location:?string}
 */
function oa_http(string $method, string $pathOrUrl, array $o = []): array
{
    global $OA;
    $url = oa_url($pathOrUrl);
    $ch = curl_init($url);
    $headers = $o['headers'] ?? [];
    $body = $o['body'] ?? null;
    if (isset($o['form'])) { $body = http_build_query($o['form']); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if (array_key_exists('json', $o)) { $body = json_encode($o['json']); $headers[] = 'Content-Type: application/json'; }
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_RESOLVE => [$OA['host'] . ':' . $OA['port'] . ':127.0.0.1']];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = $body; }
    if (!empty($o['jar'])) { $opts[CURLOPT_COOKIEJAR] = $o['jar']; $opts[CURLOPT_COOKIEFILE] = $o['jar']; }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $hdrs = [];
    foreach (explode("\r\n", substr($raw, 0, $hs)) as $line) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $hdrs[strtolower(trim($k))] = trim($v); }
    }
    $resp = substr($raw, $hs);
    $json = json_decode($resp, true);
    return ['code' => $code, 'headers' => $hdrs, 'body' => $resp, 'json' => is_array($json) ? $json : null, 'location' => $hdrs['location'] ?? null];
}

/** @return array{0:string,1:string} [code_verifier, S256 code_challenge] */
function oa_pkce(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

function oa_jar(): string
{
    $f = tempnam(sys_get_temp_dir(), 'oajar');
    return $f;
}

/** Delete throwaway-Redis rate-limit counters (login, OAuth) so a test is not throttled by its own earlier requests. */
function oa_reset_limits(string $pattern = '*rl:*'): void
{
    static $redis = null;
    $redis ??= new Predis\Client(['scheme' => 'tcp', 'host' => getenv('RIVETIT_REDIS_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('RIVETIT_REDIS_PORT') ?: 6391)]);
    foreach ($redis->keys($pattern) as $k) { $redis->del([$k]); }
}

/** Sign in through the real /login.php form. Returns true when the session is authenticated for an agent page. */
function oa_login(string $jar, string $email, string $password): bool
{
    oa_reset_limits('*rl:login:*');
    oa_http('GET', '/login.php', ['jar' => $jar]);
    $r = oa_http('POST', '/login.php', ['jar' => $jar, 'form' => ['email' => $email, 'password' => $password, 'login' => '1']]);
    return in_array($r['code'], [301, 302, 303], true);
}

/** Parse the query string of a redirect Location into an array. */
function oa_query(?string $location): array
{
    $qs = (string) parse_url((string) $location, PHP_URL_QUERY);
    parse_str($qs, $out);
    return $out;
}

/** The authorization endpoint URL for a request. */
function oa_authorize_url(array $p): string
{
    return '/oauth/authorize.php?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
}

/** Register a client through the real endpoint. @return array{0:array,1:?string} [response, client_id] */
function oa_register(array $meta = []): array
{
    $meta += ['client_name' => 'Test Client', 'redirect_uris' => ['http://127.0.0.1:8765/callback'], 'token_endpoint_auth_method' => 'none'];
    $r = oa_http('POST', '/oauth/register.php', ['json' => $meta]);
    return [$r, $r['json']['client_id'] ?? null];
}

/**
 * Drive the consent screen as a signed-in browser. $decision approve|deny. Returns the redirect back to the client.
 * @return array{page:array,post:?array,query:array,csrf:?string}
 */
function oa_consent(string $jar, array $authParams, string $decision = 'approve'): array
{
    $page = oa_http('GET', oa_authorize_url($authParams), ['jar' => $jar]);
    $csrf = null;
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $m)) { $csrf = html_entity_decode($m[1]); }
    if ($page['code'] !== 200 || $csrf === null) {
        return ['page' => $page, 'post' => null, 'query' => oa_query($page['location']), 'csrf' => null];
    }
    $form = $authParams + ['csrf_token' => $csrf, 'decision' => $decision];
    $post = oa_http('POST', '/oauth/authorize.php', ['jar' => $jar, 'form' => $form]);
    return ['page' => $page, 'post' => $post, 'query' => oa_query($post['location']), 'csrf' => $csrf];
}

function oa_token(array $form): array
{
    return oa_http('POST', '/oauth/token.php', ['form' => $form]);
}

/** MCP JSON-RPC over Streamable HTTP. Returns the decoded JSON-RPC message (SSE or JSON body), plus HTTP info. */
function oa_mcp(?string $accessToken, string $method, array $params = [], ?string $session = null, bool $notification = false, int $id = 1): array
{
    $h = ['Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'];
    if ($accessToken !== null) { $h[] = 'Authorization: Bearer ' . $accessToken; }
    if ($session !== null) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $msg = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];
    if (!$notification) { $msg['id'] = $id; }
    $r = oa_http('POST', '/mcp', ['headers' => $h, 'json' => $msg]);
    $rpc = $r['json'];
    if ($rpc === null && str_contains($r['headers']['content-type'] ?? '', 'event-stream')) {
        foreach (explode("\n", $r['body']) as $line) {
            if (str_starts_with($line, 'data:')) { $d = json_decode(trim(substr($line, 5)), true); if (is_array($d)) { $rpc = $d; } }
        }
    }
    $r['rpc'] = $rpc;
    $r['session'] = $r['headers']['mcp-session-id'] ?? $session;
    return $r;
}

/** initialize + initialized; returns [session id or null, http response of initialize]. */
function oa_mcp_open(string $accessToken): array
{
    $init = oa_mcp($accessToken, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'oauth-test', 'version' => '1']]);
    $session = $init['session'];
    if ($init['code'] === 200) { oa_mcp($accessToken, 'notifications/initialized', [], $session, true); }
    return [$session, $init];
}

/** Call an rivetit_* tool; returns the tool envelope ({success, data, errors}) or null. */
function oa_tool(string $accessToken, ?string $session, string $tool, array $args = []): ?array
{
    $r = oa_mcp($accessToken, 'tools/call', ['name' => $tool, 'arguments' => (object) $args], $session, false, 7);
    $text = $r['rpc']['result']['content'][0]['text'] ?? null;
    $env = is_string($text) ? json_decode($text, true) : null;
    return is_array($env) ? $env : ($r['rpc']['result']['structuredContent'] ?? null);
}

/** Seed: modules, one admin role, a tech role, and a module switch + built-in switch. Idempotent. */
function oa_seed_base(mysqli $db): void
{
    foreach (['module_support' => 1, 'module_assets' => 2, 'module_client' => 3, 'module_kb' => 4] as $n => $id) {
        $db->query("INSERT IGNORE INTO modules (module_id, module_name) VALUES ($id, '$n')");
    }
    $db->query("INSERT IGNORE INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'oa_admin',1),(92,'oa_tech',0),(93,'oa_none',0)");
    $db->query("REPLACE INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (92,1,2),(92,2,2),(92,3,2),(92,4,2)");
    $db->query("UPDATE settings SET config_module_enable_mcp = 1, config_mcp_issuer = '', config_mcp_audience = '' WHERE company_id = 1");
    $db->query("INSERT INTO mcp_oauth_config (setting_key, setting_value) VALUES ('builtin_enabled','1'),('registration_enabled','1')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
}

function oa_seed_user(mysqli $db, int $id, string $name, string $email, string $password, int $role, int $status = 1): void
{
    $h = password_hash($password, PASSWORD_DEFAULT);
    $db->query("DELETE FROM users WHERE user_id = $id");
    $stmt = $db->prepare("INSERT INTO users (user_id, user_name, user_email, user_password, user_auth_method, user_type, user_status, user_role_id) VALUES (?, ?, ?, ?, 'local', 1, ?, ?)");
    $stmt->bind_param('isssii', $id, $name, $email, $h, $status, $role);
    $stmt->execute();
    $db->query("INSERT IGNORE INTO user_settings (user_id) VALUES ($id)");
}

function oa_clean(mysqli $db): void
{
    foreach (['mcp_oauth_tokens', 'mcp_oauth_grants', 'mcp_oauth_codes', 'mcp_oauth_clients'] as $t) { $db->query("DELETE FROM $t"); }
    $db->query("DELETE FROM audit_events WHERE event_type LIKE 'mcp.oauth_%'");
}

function oa_finish(): never
{
    global $fail, $pass;
    oa_stop_server();
    echo "\n" . ($fail === 0 ? 'ALL PASS' : "FAILURES: $fail") . " ($pass passed)\n";
    exit($fail === 0 ? 0 : 1);
}
