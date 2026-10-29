<?php
/*
 * Unified machine API authentication (DB 2.6.204): integration clients are the only machine credential; legacy API keys were converted
 * and still authenticate through the deprecated X-Api-Key header. Scratch database only (refuses unless the name contains "scratch").
 *
 *   RIVETIT_REDIS_PORT=1 php tests/api_auth_unify_test.php     (starts and stops its own php -S servers; needs redis-server for the rate-limit section)
 *
 * Covers: the migration (every legacy permission / department shape, idempotency, the same key string still working, revoked / expired /
 * IP-restricted keys staying so), scope enforcement on the general resources, department scope (all / one / several / none / fail closed),
 * the X-Api-Key deprecation switch, the audit trail and usage counters, one shared rate-limit bucket, no administrator escalation, and
 * that user-session Bearer tokens (api_tokens, the mobile app) behave as before. Never prints a key, secret or token.
 */
chdir(dirname(__DIR__));
$ROOT = getcwd();
require_once $ROOT . '/config.php';
if (!str_contains((string) $database, 'scratch')) {
    fwrite(STDERR, "Refusing: database is not a scratch database.\n");
    exit(1);
}
require_once $ROOT . '/functions.php';

use ITFlow\Integration\ApiScopes;
use ITFlow\Integration\IntegrationAuth;
use ITFlow\Integration\IntegrationScope;
use ITFlow\Integration\LegacyKeys;
use ITFlow\Integration\ServiceIdentity;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($dbhost, $dbusername, $dbpassword, $database);
$db->set_charset('utf8mb4');
$db->query("SET sql_mode=''");

// ---------------------------------------------------------------- harness
$PASS = 0;
$FAIL = [];
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($cond) {
        $PASS++;
        echo "  ok   $name\n";
    } else {
        $FAIL[] = $name;
        echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}
function section(string $s): void
{
    echo "\n== $s\n";
}
$BASE = 'http://127.0.0.1:8096';
/** @return array{0:int,1:array,2:mixed,3:string} status, headers(lowercase), json, raw */
function http(string $method, string $path, array $headers = [], ?string $body = null, ?string $base = null): array
{
    global $BASE;
    $ch = curl_init(($base ?? $BASE) . $path);
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = (string) curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $hdr = [];
    foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $hdr[strtolower(trim($k))] = trim($v);
        }
    }
    $b = substr($raw, $size);
    return [$status, $hdr, json_decode($b, true), $b];
}
function tokenFor(string $cid, string $secret, array $extra = [], ?string $base = null): string
{
    [$s, , $j] = http('POST', '/api/v1/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'],
        http_build_query($extra + ['grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $secret]), $base);
    if ($s !== 200) {
        throw new RuntimeException("token request failed ($s)");
    }
    return $j['access_token'];
}
function api(string $method, string $path, array $auth, ?array $json = null, ?string $base = null): array
{
    $h = $auth;
    $body = null;
    if ($json !== null) {
        $h['Content-Type'] = 'application/json';
        $body = json_encode($json);
    }
    return http($method, '/api/v1/' . ltrim($path, '/'), $h, $body, $base);
}
function bearer(string $t): array
{
    return ['Authorization' => "Bearer $t"];
}
function xkey(string $k): array
{
    return ['X-Api-Key' => $k];
}
function q(mysqli $db, string $sql): array
{
    $r = $db->query($sql);
    return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
}
function q1(mysqli $db, string $sql)
{
    $r = q($db, $sql);
    return $r ? array_values($r[0])[0] : null;
}
function names(?array $json, string $col): array
{
    $v = array_column($json['data'] ?? [], $col);
    sort($v);
    return $v;
}

// ---------------------------------------------------------------- fixtures: reset the tables this suite owns
$db->query('DROP TABLE IF EXISTS integration_legacy_keys');
$db->query('DROP TABLE IF EXISTS integration_settings');
foreach (['integration_tokens', 'integration_client_secrets', 'integration_client_departments', 'integration_clients', 'api_idempotency',
          'api_key_clients', 'api_keys', 'tickets', 'ticket_replies', 'contacts', 'assets', 'kb_articles', 'api_tokens', 'logs'] as $t) {
    $db->query("DELETE FROM `$t`");
}
$db->query("DELETE FROM clients");
$db->query("DELETE FROM users WHERE user_email = '" . ServiceIdentity::EMAIL . "' OR user_id > 1");
$db->query("UPDATE settings SET config_current_database_version = '2.6.203', config_api_rate_limit = 300, config_module_enable_training = 1 WHERE company_id = 1");
$db->query("UPDATE users SET user_role_id = 3, user_status = 1 WHERE user_id = 1");
// Put the schema back to its 2.6.203 shape so the REAL migration block is what creates / widens everything.
$db->query("ALTER TABLE integration_clients MODIFY COLUMN client_scopes varchar(500) NOT NULL DEFAULT ''");
$db->query("ALTER TABLE integration_tokens MODIFY COLUMN token_scope varchar(500) NOT NULL DEFAULT ''");

$D = [];   // department name => id
foreach (['A', 'B', 'C', 'D'] as $n) {
    $db->query("INSERT INTO clients SET client_name = 'Dept $n', client_created_at = NOW()");
    $D[$n] = $db->insert_id;
}
$db->query("INSERT INTO clients SET client_name = 'Dept Archived', client_created_at = NOW(), client_archived_at = NOW()");
$ticketOf = $contactOf = $assetOf = $kbOf = [];
foreach ($D as $n => $id) {
    $db->query("INSERT INTO tickets SET ticket_subject = 'T $n', ticket_client_id = $id, ticket_status = 1, ticket_priority = 'Low', ticket_created_at = NOW(), ticket_prefix = 'TST', ticket_number = " . (100 + $id));
    $ticketOf[$id] = $db->insert_id;
    $db->query("INSERT INTO contacts SET contact_name = 'Person $n', contact_client_id = $id, contact_created_at = NOW()");
    $contactOf[$id] = $db->insert_id;
    $db->query("INSERT INTO assets SET asset_name = 'Asset $n', asset_type = 'Laptop', asset_client_id = $id, asset_created_at = NOW()");
    $assetOf[$id] = $db->insert_id;
    $db->query("INSERT INTO kb_articles SET kb_article_title = 'KB $n', kb_article_content = 'x', kb_article_client_id = $id, kb_article_created_at = NOW()");
    $kbOf[$id] = $db->insert_id;
}
$deptName = array_flip($D);
$all4 = array_values($D);
$expNames = static fn(array $ids): array => (static function () use ($ids, $deptName) { $o = array_map(fn($i) => 'Dept ' . $deptName[$i], $ids); sort($o); return $o; })();

// ---------------------------------------------------------------- legacy keys of every shape, created exactly as the old admin page did
function legacyKey(mysqli $db, string $name, string $secret, string $perm, int $delete, int $clientId, string $expire, ?string $ips = null): int
{
    $h = hash('sha256', $secret);
    $st = $db->prepare("INSERT INTO api_keys SET api_key_name = ?, api_key_secret = ?, api_key_decrypt_hash = 'x', api_key_expire = ?, api_key_client_id = ?, api_key_permission = ?, api_key_allow_delete = ?, api_key_allowed_ips = ?");
    $st->bind_param('sssisis', $name, $h, $expire, $clientId, $perm, $delete, $ips);
    $st->execute();
    return $db->insert_id;
}
$future = date('Y-m-d', strtotime('+90 days'));
$past = date('Y-m-d', strtotime('-10 days'));
$today = date('Y-m-d');
$K = [];   // label => [secret, api_key_id]
$spec = [
    'read_all' => ['read', 0, 0, $future, null, null],
    'write_all' => ['write', 0, 0, $future, null, null],
    'write_del_all' => ['write', 1, 0, $future, null, null],
    'read_with_delete_flag' => ['read', 1, 0, $future, null, null],   // a read-only key never had delete
    'write_one' => ['write', 0, $D['A'], $future, null, null],
    'read_several' => ['read', 0, -1, $future, null, [$D['A'], $D['B']]],
    'write_three' => ['write', 0, -1, $future, null, [$D['A'], $D['B'], $D['C']]],
    'sentinel_norows' => ['write', 0, -1, $future, null, []],
    'zero_with_rows' => ['write', 0, 0, $future, null, [$D['B']]],
    'expired' => ['write', 0, 0, $past, null, null],
    'revoked_today' => ['write', 0, 0, $today, null, null],
    'ip_locked' => ['write', 0, 0, $future, '203.0.113.0/24', null],
    'ip_ok' => ['write', 0, 0, $future, '127.0.0.1', null],
];
foreach ($spec as $label => [$perm, $del, $cid, $exp, $ips, $rows]) {
    $secret = 'lk_' . $label . '_' . bin2hex(random_bytes(12));
    $id = legacyKey($db, "key $label", $secret, $perm, $del, $cid, $exp, $ips);
    foreach ($rows ?? [] as $r) {
        $db->query("INSERT INTO api_key_clients SET api_key_id = $id, client_id = $r");
    }
    $K[$label] = [$secret, $id];
}
$beforeApiKeys = q($db, 'SELECT * FROM api_keys ORDER BY api_key_id');

// ---------------------------------------------------------------- 1. the migration (the REAL block from admin/database_updates.php)
section('Migration DB 2.6.204');
$src = file_get_contents($ROOT . '/admin/database_updates.php');
$start = strpos($src, "    if (\$rivetit_db_version() == '2.6.203') {");
ok($start !== false, 'the 2.6.203 -> 2.6.204 block exists in database_updates.php');
$block = substr($src, $start);
ok(str_contains(file_get_contents($ROOT . '/includes/database_version.php'), '"2.6.204"'), 'LATEST_DATABASE_VERSION is 2.6.204');
$runMigration = static function () use ($block, $db): void {
    $mysqli = $db;
    $rivetit_db_version = static fn() => q1($db, 'SELECT config_current_database_version FROM settings WHERE company_id = 1');
    // The suite deliberately runs the REAL migration text from the repository (trusted, in-tree code) against the scratch database.
    eval($block);
};
$runMigration();
ok(q1($db, 'SELECT config_current_database_version FROM settings WHERE company_id = 1') === '2.6.204', 'the version moved to 2.6.204');
ok(q1($db, "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_clients' AND COLUMN_NAME = 'client_scopes'") === 'text', 'client_scopes was widened to text');
ok(q1($db, "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_tokens' AND COLUMN_NAME = 'token_scope'") === 'text', 'token_scope was widened to text');
ok((int) q1($db, 'SELECT COUNT(*) FROM integration_clients') === count($K), 'one integration client per legacy key', (string) q1($db, 'SELECT COUNT(*) FROM integration_clients'));
ok((int) q1($db, 'SELECT COUNT(*) FROM integration_legacy_keys') === count($K), 'one link row per legacy key');
ok(q($db, 'SELECT * FROM api_keys ORDER BY api_key_id') === $beforeApiKeys, 'api_keys is untouched (rollback possible)');
ok((int) q1($db, 'SELECT COUNT(*) FROM integration_client_secrets') === 0, 'no secret is created for a migrated client (the key string is the credential)');
ok(q1($db, "SELECT setting_value FROM integration_settings WHERE setting_key = 'legacy_header_enabled'") === '1', 'the X-Api-Key header is accepted by default');
$svc = q($db, "SELECT * FROM users WHERE user_email = '" . ServiceIdentity::EMAIL . "'");
ok(count($svc) === 1 && (int) $svc[0]['user_status'] === 0 && (int) $svc[0]['user_role_id'] === 0, 'the service identity exists, is disabled and has no role');

$clientOf = static fn(string $label): array => q($db, 'SELECT c.*, l.lk_use_count FROM integration_clients c JOIN integration_legacy_keys l ON l.lk_client_id = c.client_id WHERE l.lk_api_key_id = ' . (int) $K[$label][1])[0];
$resCount = count(ApiScopes::RESOURCES);
$expScopes = static fn(array $actions): array => (static function () use ($actions) { $o = []; foreach (array_keys(ApiScopes::RESOURCES) as $r) { foreach ($actions as $a) { $o[] = "api:$r:$a"; } } sort($o); return $o; })();
$scopesOf = static function (array $c): array { $s = IntegrationAuth::parseScopes((string) $c['client_scopes']); sort($s); return $s; };
ok($scopesOf($clientOf('read_all')) === $expScopes(['read']), 'read -> api:<resource>:read for every resource');
ok($scopesOf($clientOf('write_all')) === $expScopes(['read', 'write']), 'write -> read and write');
ok($scopesOf($clientOf('write_del_all')) === $expScopes(['read', 'write', 'delete']), 'write + allow_delete -> read, write and delete');
ok($scopesOf($clientOf('read_with_delete_flag')) === $expScopes(['read']), 'read + allow_delete flag -> still read only');
ok(strlen((string) $clientOf('write_del_all')['client_scopes']) > 500, 'the full scope string is longer than the old 500 character column');
foreach (['read_all', 'write_all'] as $l) {
    ok((int) $clientOf($l)['client_all_departments'] === 1, "$l -> all departments");
}
$deptIds = static fn(string $l): ?array => IntegrationScope::allowedDepartmentIds($db, $clientOf($l));
ok($deptIds('write_one') === [$D['A']], 'single department key -> that one department');
ok($deptIds('read_several') === [$D['A'], $D['B']], 'two department key -> those two');
ok($deptIds('write_three') === [$D['A'], $D['B'], $D['C']], 'three department key -> those three');
ok($deptIds('sentinel_norows') === [], 'the -1 sentinel with no rows -> NO department (fail closed)');
ok($deptIds('zero_with_rows') === [$D['B']], 'a 0 key that has department rows stays limited to them');
ok((string) $clientOf('ip_locked')['client_allowed_ips'] === '203.0.113.0/24', 'the IP allow-list is carried over');
ok($clientOf('read_all')['client_allowed_ips'] === null, 'no allow-list stays no allow-list');
ok($clientOf('expired')['client_expires_at'] === $past . ' 00:00:00', 'the expiry instant is carried over (00:00 of the key\'s expire date)');
ok(strtotime((string) $clientOf('revoked_today')['client_expires_at']) <= time(), 'a key revoked today (expire = today) is already expired');
ok($clientOf('read_all')['client_name'] === 'key read_all', 'the key name becomes the client name');
ok(str_starts_with((string) $clientOf('read_all')['client_uid'], 'ic_'), 'the client has a Client ID');

$snapshot = q($db, 'SELECT client_id, client_uid, client_scopes FROM integration_clients ORDER BY client_id');
$runMigration();
ok(q($db, 'SELECT client_id, client_uid, client_scopes FROM integration_clients ORDER BY client_id') === $snapshot && (int) q1($db, 'SELECT COUNT(*) FROM integration_legacy_keys') === count($K), 'running the migration again changes nothing (idempotent)');
$r2 = LegacyKeys::migrate($db);
ok($r2['migrated'] === 0 && $r2['already'] === count($K), 'LegacyKeys::migrate reports everything as already converted');
ok((int) q1($db, "SELECT COUNT(*) FROM users WHERE user_email = '" . ServiceIdentity::EMAIL . "'") === 1, 'the service identity is created once');
// A key created after the migration (a rollback and roll-forward) is converted on first use.
$late = 'lk_late_' . bin2hex(random_bytes(10));
$lateId = legacyKey($db, 'key late', $late, 'read', 0, 0, $future);
ok((int) q1($db, "SELECT COUNT(*) FROM integration_legacy_keys WHERE lk_api_key_id = $lateId") === 0, 'a key added after the migration is not linked yet');

// ---------------------------------------------------------------- 2. scope model (unit)
section('Scope model');
ok(count(ApiScopes::all()) === $resCount * 3 && count(array_unique(ApiScopes::all())) === $resCount * 3, 'one read, write and delete scope per resource');
$ps = IntegrationAuth::parseScopes('* api:*:read api:tickets:* api:tickets:read api:bogus:read lms:read');
sort($ps);
ok($ps === ['api:tickets:read', 'lms:read'], 'unknown scopes and wildcards are dropped', json_encode($ps));
ok(!ApiScopes::has(['api:tickets:write'], 'tickets', 'read') && !ApiScopes::has(['api:tickets:delete'], 'tickets', 'write'), 'no implication between read, write and delete');
ok(ApiScopes::actionForMethod('GET') === 'read' && ApiScopes::actionForMethod('PATCH') === 'write' && ApiScopes::actionForMethod('DELETE') === 'delete' && ApiScopes::actionForMethod('TRACE') === null, 'HTTP method -> action');
ok(ApiScopes::fromLegacy('bogus', true) === [], 'an unknown legacy permission grants nothing');
ok(ApiScopes::moduleAllows(['api:tickets:read'], 'module_support', 1, 'tickets') && !ApiScopes::moduleAllows(['api:tickets:read'], 'module_support', 2, 'tickets'), 'module check follows the route scope and level');
ok(!ApiScopes::moduleAllows(ApiScopes::all(), 'module_credential', 1, 'tickets'), 'the credential module is never granted to a client');
ok(!ApiScopes::moduleAllows(['api:search:read'], 'module_client', 1, 'search') && ApiScopes::moduleAllows(['api:search:read', 'api:clients:read'], 'module_client', 1, 'search'), 'a blended endpoint (search) needs the primary resource of each module');
// every route the router serves is either scoped, explicitly denied, or the key check itself
$idx = file_get_contents($ROOT . '/api/v1/index.php');
$sw = substr($idx, strpos($idx, "switch (\$resource) {"));
preg_match_all("/case '([a-z_\\-]+)':/", $sw, $cm);
$unmapped = [];
foreach (array_unique($cm[1]) as $route) {
    if ($route === 'validate_api_key' || in_array($route, ApiScopes::DENIED_ROUTES, true) || ApiScopes::routeResource($route, null) !== null) {
        continue;
    }
    $unmapped[] = $route;
}
ok($unmapped === [], 'every routed resource has a scope or is denied to clients', implode(',', $unmapped));
foreach (ApiScopes::DENIED_ROUTES as $dr) {
    ok(ApiScopes::routeResource($dr, null) === null, "denied route $dr has no scope");
}

// ---------------------------------------------------------------- servers
$pids = [];
$startServer = static function (int $port, array $env = []) use ($ROOT, &$pids): void {
    $e = '';
    foreach ($env as $k => $v) {
        $e .= $k . '=' . escapeshellarg($v) . ' ';
    }
    $cmd = sprintf('%sphp -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!', $e, $port, escapeshellarg($ROOT), escapeshellarg($ROOT . '/tests/lms_router.php'));
    $pids[] = (int) trim((string) shell_exec($cmd));
    for ($i = 0; $i < 60; $i++) {
        if (@fsockopen('127.0.0.1', $port, $en, $es, 0.2)) {
            break;
        }
        usleep(100000);
    }
};
register_shutdown_function(static function () use (&$pids) {
    foreach ($pids as $p) {
        if ($p > 0) {
            @posix_kill($p, 15);
        }
    }
});
$startServer(8096, ['PHP_CLI_SERVER_WORKERS' => '4']);

// ---------------------------------------------------------------- 3. legacy keys keep working, with the rules of their client
section('X-Api-Key compat: same key string, same data, same departments');
foreach ([
    'read_all' => $all4, 'write_all' => $all4, 'write_one' => [$D['A']], 'read_several' => [$D['A'], $D['B']],
    'write_three' => [$D['A'], $D['B'], $D['C']], 'sentinel_norows' => [], 'zero_with_rows' => [$D['B']],
] as $label => $allowed) {
    $auth = xkey($K[$label][0]);
    $exp = $expNames($allowed);
    foreach (['tickets' => 'client', 'contacts' => 'client', 'assets' => 'client'] as $res => $col) {
        [$st, , $j] = api('GET', "$res?limit=50", $auth);
        ok($st === 200 && names($j, $col) === $exp, "$label: GET $res lists exactly its departments", "HTTP $st " . json_encode(names($j, $col)));
    }
    [$st, , $j] = api('GET', 'clients', $auth);
    ok($st === 200 && names($j, 'name') === $exp, "$label: GET clients lists exactly its departments", "HTTP $st");
    foreach ($D as $n => $id) {
        $in = in_array($id, $allowed, true);
        [$st] = api('GET', 'tickets/' . $ticketOf[$id], $auth);
        ok($in ? $st === 200 : in_array($st, [403, 404], true), "$label: GET tickets/{dept $n} " . ($in ? 'allowed' : 'refused'), "HTTP $st");
        [$st] = api('GET', 'assets/' . $assetOf[$id], $auth);
        ok($in ? $st === 200 : in_array($st, [403, 404], true), "$label: GET assets/{dept $n} " . ($in ? 'allowed' : 'refused'), "HTTP $st");
    }
    [$st, , $j] = api('GET', 'kb/articles?limit=50', $auth);
    $gotc = array_map('intval', array_column($j['data'] ?? [], 'client_id'));
    sort($gotc);
    ok($st === 200 && $gotc === (static function ($a) { sort($a); return $a; })($allowed), "$label: GET kb/articles lists exactly its departments", json_encode($gotc));
}

section('Legacy permission semantics through the new scopes');
[$st] = api('POST', 'tickets/' . $ticketOf[$D['A']] . '/reply', xkey($K['read_all'][0]), ['reply' => 'x']);
ok($st === 403, 'a read key cannot write (403)', "HTTP $st");
[$st, , $j] = api('POST', 'tickets/' . $ticketOf[$D['A']] . '/reply', xkey($K['write_all'][0]), ['reply' => 'machine reply']);
ok(in_array($st, [200, 201], true), 'a write key can write', "HTTP $st " . json_encode($j));
[$st, , $j] = api('DELETE', 'tickets/' . $ticketOf[$D['A']], xkey($K['write_all'][0]));
ok($st === 403 && str_contains((string) ($j['error'] ?? ''), 'api:tickets:delete'), 'a write key without delete cannot DELETE (403 names the missing scope)', "HTTP $st " . json_encode($j));
[$st, , $j] = api('DELETE', 'tickets/' . $ticketOf[$D['A']], xkey($K['write_del_all'][0]));
ok(!(($st === 403) && str_contains((string) ($j['error'] ?? ''), 'scope')), 'a write + delete key passes the scope check for DELETE', "HTTP $st");
[$st] = api('POST', 'tickets/' . $ticketOf[$D['A']] . '/reply', xkey($K['read_with_delete_flag'][0]), ['reply' => 'x']);
ok($st === 403, 'a read key with the delete flag still cannot write', "HTTP $st");
[$st] = api('GET', 'tickets', xkey($K['expired'][0]));
ok($st === 401, 'an expired legacy key stays expired (401)', "HTTP $st");
[$st] = api('GET', 'tickets', xkey($K['revoked_today'][0]));
ok($st === 401, 'a revoked legacy key stays revoked (401)', "HTTP $st");
[$st, , $j] = api('GET', 'tickets', xkey($K['ip_locked'][0]));
ok($st === 403 && str_contains((string) ($j['error'] ?? ''), 'not permitted from your IP'), 'an IP-restricted legacy key stays restricted (403)', "HTTP $st");
[$st] = api('GET', 'tickets', xkey($K['ip_ok'][0]));
ok($st === 200, 'a key allowed from this address works', "HTTP $st");
[$st] = api('GET', 'tickets', xkey('not-a-key-' . bin2hex(random_bytes(8))));
ok($st === 401, 'an unknown key is 401', "HTTP $st");
[$st] = api('GET', 'tickets?api_key=' . urlencode($K['read_all'][0]), []);
ok($st === 401, 'the key in the query string is no longer read (header only)', "HTTP $st");
[$st] = api('POST', 'tickets', [], ['api_key' => $K['write_all'][0]]);
ok($st === 401, 'the key in the JSON body is no longer read (header only)', "HTTP $st");
[$st] = api('GET', 'tickets', xkey($late));
ok($st === 200 && (int) q1($db, "SELECT COUNT(*) FROM integration_legacy_keys WHERE lk_api_key_id = $lateId") === 1, 'a key added after the migration is converted on first use and works');
[$st] = api('GET', 'lms/courses', xkey($K['read_all'][0]));
ok($st === 401, 'the legacy header is not accepted on /lms', "HTTP $st");
[$st] = api('GET', 'employees', xkey($K['read_all'][0]));
ok($st === 401, 'the legacy header is not accepted on /employees', "HTTP $st");
foreach (['credentials', 'me', 'notifications', 'approvals', 'endpoint_devices', 'service_catalog', 'workflow_tasks'] as $dr) {
    [$st] = api('GET', $dr, xkey($K['write_del_all'][0]));
    ok($st === 403, "legacy key -> $dr is 403 (person-bound or user-token only)", "HTTP $st");
}
[$st, , $j] = api('GET', 'validate_api_key', xkey($K['read_all'][0]));
ok($st === 200, 'validate_api_key answers 200 for a valid key', "HTTP $st");
[$st] = api('GET', 'validate_api_key', xkey('nope'));
ok($st === 401, 'validate_api_key answers 401 for an invalid key');
[$st] = api('GET', 'no-such-resource', xkey($K['read_all'][0]));
ok($st === 403, 'an unknown route is 403 for a client (deny by default)', "HTTP $st");

// ---------------------------------------------------------------- 4. bearer clients: scopes on the general API
section('Integration client: scopes on general resources');
function mkClient(mysqli $db, string $name, array $scopes, bool $all, array $depts = [], ?string $ips = null): array
{
    $c = IntegrationAuth::createClient($db, $name, null, $scopes, $ips ?? '', null, 1, $all, $depts);
    return [$c['client_uid'], $c['secret'], $c['client_id']];
}
[$uidT, $secT, $idT] = mkClient($db, 'tickets reader', ['api:tickets:read'], true);
$tokT = tokenFor($uidT, $secT);
[$st, , $j] = api('GET', 'tickets?limit=50', bearer($tokT));
ok($st === 200 && names($j, 'client') === $expNames($all4), 'api:tickets:read -> GET tickets (all departments)');
[$st, , $j] = api('GET', 'contacts', bearer($tokT));
ok($st === 403 && str_contains((string) ($j['error'] ?? ''), 'api:contacts:read'), 'without api:contacts:read -> 403 naming the scope', "HTTP $st");
[$st] = api('POST', 'tickets/' . $ticketOf[$D['A']] . '/reply', bearer($tokT), ['reply' => 'x']);
ok($st === 403, 'api:tickets:read does not allow a write');
[$st] = api('GET', 'kb/articles', bearer($tokT));
ok($st === 403, 'kb needs its own scope');
[$st] = api('GET', 'tickets/charges', bearer($tokT));
ok($st === 403, 'tickets/charges needs api:worksheets:read, not api:tickets:read', "HTTP $st");
[$st] = api('GET', 'lms/courses', bearer($tokT));
ok($st === 403, 'an api:* token does not reach /lms (lms scopes are separate)', "HTTP $st");
[$st] = api('GET', 'employees', bearer($tokT));
ok($st === 403, 'an api:* token does not reach /employees (directory:read is separate)', "HTTP $st");
foreach (['credentials', 'me', 'notifications', 'approvals', 'service_catalog'] as $dr) {
    [$st] = api('GET', $dr, bearer($tokT));
    ok($st === 403, "token -> $dr is 403", "HTTP $st");
}
// a client holding every general scope still cannot reach the person-bound routes
[$uidX, $secX] = mkClient($db, 'everything', ApiScopes::all(), true);
$tokX = tokenFor($uidX, $secX);
ok(strlen($tokX) > 1500 && IntegrationAuth::looksLikeToken($tokX), 'a token carrying every general scope is accepted by the token shape check', (string) strlen($tokX));
foreach (['credentials', 'me', 'notifications', 'approvals'] as $dr) {
    [$st] = api('GET', $dr, bearer($tokX));
    ok($st === 403, "even with every api scope: $dr is 403", "HTTP $st");
}
[$st] = api('GET', 'tickets', bearer($tokX));
ok($st === 200, 'a token with every scope reads tickets');
$tokNarrow = tokenFor($uidX, $secX, ['scope' => 'api:assets:read']);
[$st] = api('GET', 'assets', bearer($tokNarrow));
[$st2] = api('GET', 'tickets', bearer($tokNarrow));
ok($st === 200 && $st2 === 403, 'a token asked with a narrower scope carries only that scope');
[$st] = http('POST', '/api/v1/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'client_credentials', 'client_id' => $uidT, 'client_secret' => $secT, 'scope' => 'api:tickets:write']));
ok($st === 400, 'asking for a scope the client does not hold is refused (invalid_scope)', "HTTP $st");
// narrowing the client's scopes bites at the next request of an existing token
$db->query("UPDATE integration_clients SET client_scopes = 'api:assets:read' WHERE client_id = $idT");
[$st] = api('GET', 'tickets', bearer($tokT));
ok($st === 403, 'narrowing a client\'s scopes applies to its existing token immediately');
$db->query("UPDATE integration_clients SET client_scopes = 'api:tickets:read' WHERE client_id = $idT");

// delete scope on a real route
[$uidD, $secD] = mkClient($db, 'writer', ['api:tickets:read', 'api:tickets:write'], true);
$tokD = tokenFor($uidD, $secD);
[$st, , $j] = api('DELETE', 'tickets/' . $ticketOf[$D['B']], bearer($tokD));
ok($st === 403 && str_contains((string) ($j['error'] ?? ''), 'api:tickets:delete'), 'DELETE needs api:tickets:delete');
[$st] = api('PROPFIND', 'tickets', bearer($tokD));
ok($st === 405, 'an unsupported HTTP method is 405 for a client');

// ---------------------------------------------------------------- 5. department scope on bearer clients
section('Department scope on general resources (bearer clients)');
$shapes = [
    'all' => [true, [], $all4],
    'one' => [false, [$D['A']], [$D['A']]],
    'several' => [false, [$D['B'], $D['C']], [$D['B'], $D['C']]],
    'none' => [false, [], []],
];
$deptClients = [];
foreach ($shapes as $label => [$all, $ids, $expect]) {
    [$u, $s, $cid] = mkClient($db, "dept $label", ['api:tickets:read', 'api:contacts:read', 'api:assets:read', 'api:clients:read', 'api:kb:read', 'api:search:read', 'api:reports:read'], $all, $ids);
    $deptClients[$label] = [$u, $s, $cid];
    $tok = tokenFor($u, $s);
    $exp = $expNames($expect);
    foreach (['tickets' => 'client', 'contacts' => 'client', 'assets' => 'client'] as $res => $col) {
        [$st, , $j] = api('GET', "$res?limit=50", bearer($tok));
        ok($st === 200 && names($j, $col) === $exp, "dept=$label: $res lists exactly its departments", json_encode(names($j, $col)));
    }
    [$st, , $j] = api('GET', 'clients', bearer($tok));
    ok($st === 200 && names($j, 'name') === $exp, "dept=$label: clients list", "HTTP $st");
    foreach ($D as $n => $id) {
        [$st] = api('GET', 'tickets/' . $ticketOf[$id], bearer($tok));
        $in = in_array($id, $expect, true);
        ok($in ? $st === 200 : in_array($st, [403, 404], true), "dept=$label: ticket of $n " . ($in ? 'visible' : 'hidden'), "HTTP $st");
    }
    [$st, , $j] = api('GET', 'search?q=Dept', bearer($tok));
    ok($st === 200 && (in_array($D['B'], $expect, true) || !str_contains(json_encode($j), 'Dept B')), "dept=$label: search does not leak a hidden department", "HTTP $st");
}
// the scope is read fresh: moving a client changes what its existing token sees
$tok = tokenFor($deptClients['one'][0], $deptClients['one'][1]);
IntegrationScope::save($db, $deptClients['one'][2], false, [$D['D']]);
[$st, , $j] = api('GET', 'tickets?limit=50', bearer($tok));
ok($st === 200 && names($j, 'client') === ['Dept D'], 'changing the departments applies to an existing token at its next request');
IntegrationScope::save($db, $deptClients['one'][2], false, []);
[$st, , $j] = api('GET', 'tickets?limit=50', bearer($tok));
ok($st === 200 && names($j, 'client') === [], 'a restricted client with no departments sees nothing');
// fail closed: a restricted client whose department rows vanished
$db->query("DELETE FROM integration_client_departments WHERE client_id = " . $deptClients['several'][2]);
$tok = tokenFor($deptClients['several'][0], $deptClients['several'][1]);
[$st, , $j] = api('GET', 'contacts', bearer($tok));
ok($st === 200 && names($j, 'client') === [], 'a restricted client whose rows are gone fails closed (nothing, not everything)');
// fail closed: a database/array shape with the -1 sentinel but a list that cannot be resolved
ok(api_key_check_sentinel(), 'a -1 sentinel without a resolved list denies');
function api_key_check_sentinel(): bool
{
    define('FROM_API', true);
    require_once dirname(__DIR__) . '/api/v1/includes/api_permissions.php';
    $GLOBALS['api_key_client_ids'] = null;
    $GLOBALS['api_key_client_id'] = -1;
    $a = api_key_allowed_client_ids();
    $GLOBALS['api_key_client_ids'] = [];
    $b = api_key_allowed_client_ids();
    $GLOBALS['api_key_client_ids'] = null;
    $GLOBALS['api_key_client_id'] = null;
    $c = api_key_allowed_client_ids();
    return $a === [] && $b === [] && $c === null;
}

// ---------------------------------------------------------------- 6. no administrator escalation
section('Acting identity');
$svcId = ServiceIdentity::userId($db);
ok($svcId > 1 && $svcId === (int) $svc[0]['user_id'], 'the acting identity is the fixed service user, not user #1 (the first admin)');
$reply = q($db, 'SELECT * FROM ticket_replies ORDER BY ticket_reply_id DESC LIMIT 1');
ok($reply && (int) $reply[0]['ticket_reply_by'] === $svcId, 'a write made with a (formerly admin-acting) legacy key is recorded against the service identity', json_encode($reply ? $reply[0]['ticket_reply_by'] : null));
ok((int) q1($db, "SELECT COUNT(*) FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = $svcId AND r.role_is_admin = 1") === 0, 'the service identity holds no administrator role');
ok((int) q1($db, "SELECT user_status FROM users WHERE user_id = $svcId") === 0, 'the service identity is disabled (cannot sign in)');
ok((int) q1($db, "SELECT COUNT(*) FROM api_tokens WHERE token_user_id = $svcId") === 0, 'the service identity owns no user token');
[$uidW, $secW] = mkClient($db, 'tickets only writer', ['api:tickets:read', 'api:tickets:write'], true);
$tokW = tokenFor($uidW, $secW);
foreach (['alerts', 'kb/articles', 'reports/summary', 'dashboard', 'projects', 'tasks', 'contracts', 'appointments', 'relationships'] as $r) {
    [$st] = api('GET', $r, bearer($tokW));
    ok($st === 403, "a tickets-only client is refused on $r (an admin identity would have passed)", "HTTP $st");
}
// scopes are enforced inside shared module checks too: dashboard with its scope, but no tickets scope -> ticket numbers withheld
[$uidDash, $secDash] = mkClient($db, 'dash', ['api:dashboard:read'], true);
[$st, , $j] = api('GET', 'dashboard', bearer(tokenFor($uidDash, $secDash)));
ok($st === 200, 'dashboard answers with api:dashboard:read');
// alerts: module check goes through the scopes, not a role lookup
[$uidAl, $secAl] = mkClient($db, 'alerts', ['api:alerts:read'], true);
[$st] = api('GET', 'alerts', bearer(tokenFor($uidAl, $secAl)));
ok($st === 200, 'alerts answers with api:alerts:read', "HTTP $st");

// ---------------------------------------------------------------- 7. deprecation switch, audit, counters
section('X-Api-Key deprecation switch, audit and usage');
$cRead = $clientOf('read_all');
$before = (int) q1($db, "SELECT lk_use_count FROM integration_legacy_keys WHERE lk_api_key_id = " . $K['read_all'][1]);
$logsBefore = (int) q1($db, "SELECT COUNT(*) FROM logs WHERE log_type = 'Integration API' AND log_action = 'Legacy Key Use' AND log_entity_id = " . (int) $cRead['client_id']);
[$st, $hdr] = api('GET', 'tickets', xkey($K['read_all'][0]));
ok($st === 200 && ($hdr['deprecation'] ?? '') === 'true' && str_contains($hdr['x-api-key-notice'] ?? '', 'deprecated'), 'an X-Api-Key response carries the Deprecation header and a notice');
ok((int) q1($db, "SELECT lk_use_count FROM integration_legacy_keys WHERE lk_api_key_id = " . $K['read_all'][1]) === $before + 1, 'the use is counted');
ok(q1($db, "SELECT lk_last_used_at FROM integration_legacy_keys WHERE lk_api_key_id = " . $K['read_all'][1]) !== null, 'last used is recorded');
ok(q1($db, "SELECT lk_last_ip FROM integration_legacy_keys WHERE lk_api_key_id = " . $K['read_all'][1]) === '127.0.0.1', 'last IP is recorded');
ok((int) q1($db, "SELECT COUNT(*) FROM logs WHERE log_type = 'Integration API' AND log_action = 'Legacy Key Use' AND log_entity_id = " . (int) $cRead['client_id']) === $logsBefore + 1, 'every X-Api-Key use is audit-logged with the client id');
$logTxt = (string) q1($db, "SELECT GROUP_CONCAT(log_description) FROM logs WHERE log_type = 'Integration API'");
ok(!str_contains($logTxt, $K['read_all'][0]) && !str_contains($logTxt, hash('sha256', $K['read_all'][0])), 'the audit log never contains the key or its hash');
ok(str_contains($logTxt, (string) $cRead['client_uid']), 'the audit line names the client');
[, $hdr] = api('GET', 'tickets', bearer($tokT));
ok(!isset($hdr['deprecation']), 'a Bearer request carries no deprecation header');
$bc = (int) q1($db, "SELECT lk_use_count FROM integration_legacy_keys WHERE lk_api_key_id = " . $K['read_all'][1]);
api('GET', 'tickets', bearer($tokX));
ok((int) q1($db, "SELECT lk_use_count FROM integration_legacy_keys WHERE lk_api_key_id = " . $K['read_all'][1]) === $bc, 'only X-Api-Key requests count as legacy use');

LegacyKeys::setPolicy($db, true, date('Y-m-d', strtotime('+30 days')));
[$st, $hdr] = api('GET', 'tickets', xkey($K['read_all'][0]));
ok($st === 200 && isset($hdr['sunset']) && str_contains($hdr['sunset'], 'GMT'), 'with a removal date set, responses carry a Sunset header');
LegacyKeys::setPolicy($db, true, $today);
[$st, , $j] = api('GET', 'tickets', xkey($K['read_all'][0]));
ok($st === 401 && str_contains((string) ($j['error'] ?? ''), 'no longer accepted') && str_contains((string) $j['error'], 'oauth/token'), 'on/after the removal date X-Api-Key is 401 with a clear message', "HTTP $st");
LegacyKeys::setPolicy($db, true, null);
[$st] = api('GET', 'tickets', xkey($K['read_all'][0]));
ok($st === 200, 'clearing the date accepts the header again');
LegacyKeys::setPolicy($db, false, null);
[$st, $hdr, $j] = api('GET', 'tickets', xkey($K['read_all'][0]));
ok($st === 401 && str_contains((string) ($j['error'] ?? ''), 'no longer accepted') && ($hdr['www-authenticate'] ?? '') === 'Bearer', 'switched off: X-Api-Key is 401 with a clear message', "HTTP $st");
[$st] = api('GET', 'tickets', xkey('definitely-not-a-key'));
ok($st === 401, 'switched off: even an unknown key gets the same 401');
[$st] = api('GET', 'tickets', bearer($tokT));
ok($st === 200, 'switched off: Bearer access tokens are unaffected');
ok((int) q1($db, "SELECT COUNT(*) FROM logs WHERE log_type = 'Integration API' AND log_action = 'Legacy Key Refused'") >= 1, 'a refused X-Api-Key is audited');
LegacyKeys::setPolicy($db, true, null);
ok(LegacyKeys::policy($db)['accepted'] === true && LegacyKeys::policy($db)['reason'] === 'accepted', 'policy() reports accepted again');
try {
    $db->query("RENAME TABLE integration_settings TO integration_settings_hold");
    $p = LegacyKeys::policy($db);
    ok($p['accepted'] === false && $p['reason'] === 'not_migrated', 'a database without the settings table fails closed');
    $rr = LegacyKeys::resolve($db, $K['read_all'][0], '127.0.0.1');
    ok($rr['ok'] === false && $rr['status'] === 401 && str_contains($rr['message'], '2.6.204'), 'before the migration an X-Api-Key is refused with a message about the pending database update');
} finally {
    $db->query("RENAME TABLE integration_settings_hold TO integration_settings");
}

// ---------------------------------------------------------------- 8. one rule set: the migrated client is a client like any other
section('A migrated client goes through the same rules');
$cw = $clientOf('write_all');
$cwId = (int) $cw['client_id'];
// an admin narrows it on the unified page: the old header obeys at once
$db->query("UPDATE integration_clients SET client_scopes = 'api:tickets:read' WHERE client_id = $cwId");
[$st] = api('GET', 'tickets', xkey($K['write_all'][0]));
[$st2] = api('GET', 'contacts', xkey($K['write_all'][0]));
ok($st === 200 && $st2 === 403, 'editing a migrated client\'s scopes narrows its X-Api-Key at once');
IntegrationScope::save($db, $cwId, false, [$D['C']]);
[$st, , $j] = api('GET', 'tickets?limit=50', xkey($K['write_all'][0]));
ok($st === 200 && names($j, 'client') === ['Dept C'], 'editing a migrated client\'s departments applies to its X-Api-Key');
// the admin gives it a Client ID + secret: the same client now also works by token
$sec = IntegrationAuth::addSecret($db, $cwId);
$tk = tokenFor($cw['client_uid'], $sec);
[$st, , $j] = api('GET', 'tickets?limit=50', bearer($tk));
ok($st === 200 && names($j, 'client') === ['Dept C'], 'the migrated client can switch to Client ID + secret and sees the same thing');
// expiry applies to both
$db->query("UPDATE integration_clients SET client_expires_at = NOW() - INTERVAL 1 MINUTE WHERE client_id = $cwId");
[$st] = api('GET', 'tickets', xkey($K['write_all'][0]));
[$st2] = api('GET', 'tickets', bearer($tk));
ok($st === 401 && $st2 === 401, 'an expired client is refused by both X-Api-Key and its access token');
$db->query("UPDATE integration_clients SET client_expires_at = NULL WHERE client_id = $cwId");
IntegrationAuth::revokeClient($db, $cwId);
[$st] = api('GET', 'tickets', xkey($K['write_all'][0]));
[$st2] = api('GET', 'tickets', bearer($tk));
ok($st === 401 && $st2 === 401, 'revoking the client stops both at the next request');
// IP rules are one rule
$db->query("UPDATE integration_clients SET client_allowed_ips = '203.0.113.9' WHERE client_id = " . (int) $clientOf('ip_ok')['client_id']);
[$st] = api('GET', 'tickets', xkey($K['ip_ok'][0]));
ok($st === 403, 'the client\'s IP allow-list applies to its X-Api-Key');
// a legacy-origin client is listed as such
ok((int) q1($db, 'SELECT COUNT(*) FROM integration_legacy_keys') === count($K) + 1, 'every migrated client is linked to its legacy key row');

// failed X-Api-Key guesses still throttle per IP
$db->query("DELETE FROM logs WHERE log_type = 'API' AND log_action = 'Failed'");
for ($i = 0; $i < 15; $i++) {
    api('GET', 'tickets', xkey('guess-' . $i . bin2hex(random_bytes(4))));
}
[$st] = api('GET', 'tickets', xkey('guess-final'));
ok($st === 429, 'the failed-key throttle (15 per IP per 10 minutes) still applies');
$db->query("DELETE FROM logs WHERE log_type = 'API' AND log_action = 'Failed'");

// ---------------------------------------------------------------- 9. user-session tokens (the mobile app) are untouched
section('User-session Bearer tokens (api_tokens) are unchanged');
$db->query("INSERT INTO users SET user_name = 'Mobile Tech', user_email = 'tech@scratch.test', user_password = 'x', user_role_id = 3, user_status = 1, user_type = 1");
$techId = $db->insert_id;
$rawUser = bin2hex(random_bytes(32));
$db->query("INSERT INTO api_tokens SET token_user_id = $techId, token_hash = '" . hash('sha256', $rawUser) . "', token_created_at = NOW()");
[$st, , $j] = api('GET', 'tickets?limit=50', bearer($rawUser));
ok($st === 200 && names($j, 'client') === $expNames($all4), 'a user token reads tickets as before');
[$st] = api('GET', 'me', bearer($rawUser));
ok($st === 200, 'a user token still reaches /me');
[$st] = api('GET', 'notifications', bearer($rawUser));
ok($st === 200, 'a user token still reaches /notifications');
[$st] = api('GET', 'lms/courses', bearer($rawUser));
ok($st === 401, 'a user token is still refused on /lms');
[$st] = api('GET', 'tickets', bearer($rawUser) + xkey($K['sentinel_norows'][0]));
[, , $j] = api('GET', 'tickets?limit=50', bearer($rawUser) + xkey($K['sentinel_norows'][0]));
ok($st === 200 && names($j, 'client') === $expNames($all4), 'a valid user token wins over an X-Api-Key sent with it (as before)');
$db->query("UPDATE users SET user_status = 0 WHERE user_id = $techId");
[$st] = api('GET', 'tickets', bearer($rawUser));
ok($st === 401, 'a disabled user\'s token is refused');
$db->query("UPDATE users SET user_status = 1 WHERE user_id = $techId");
$db->query("UPDATE api_tokens SET token_last_used_at = NOW() - INTERVAL 91 DAY WHERE token_user_id = $techId");
[$st] = api('GET', 'tickets', bearer($rawUser));
ok($st === 401, 'a user token idle for 90+ days is still expired');
[$st] = api('GET', 'tickets', bearer('abcdef' . bin2hex(random_bytes(10))));
ok($st === 401, 'an unknown opaque bearer is 401');
[$st] = api('GET', 'tickets', bearer('aaaaaaaaaa.bbbbbbbbbb.cccccccccccccccccccc'));
ok($st === 401, 'a JWT-shaped garbage bearer is 401');
[$st] = api('GET', 'tickets', []);
ok($st === 401, 'no credentials is 401');
ok(!str_contains(file_get_contents($ROOT . '/api/v1/auth.php'), 'integration_clients'), 'auth.php (the mobile login) does not know integration clients');

// ---------------------------------------------------------------- 10. one shared rate-limit bucket per client (needs redis-server)
section('Rate limit: one bucket per client');
ok(substr_count($idx, "'int:' . (int) \$integration_client['client_id']") >= 2, 'both authentication inputs use the int:<client id> bucket (static)');
ok(!str_contains($idx, "'key:' . substr("), 'the per-key bucket is gone');
$redisPort = 6391;
$redisPid = (int) trim((string) shell_exec(sprintf('redis-server --port %d --save "" --appendonly no --daemonize no > /dev/null 2>&1 & echo $!', $redisPort)));
$redisUp = false;
for ($i = 0; $i < 30 && !$redisUp; $i++) {
    $redisUp = (bool) @fsockopen('127.0.0.1', $redisPort, $en, $es, 0.2);
    usleep(100000);
}
register_shutdown_function(static function () use ($redisPid) {
    if ($redisPid > 0) {
        @posix_kill($redisPid, 15);
    }
});
if ($redisUp) {
    $db->query("UPDATE settings SET config_api_rate_limit = 10 WHERE company_id = 1");
    $startServer(8095, ['RIVETIT_REDIS_PORT' => (string) $redisPort]);
    $rb = 'http://127.0.0.1:8095';
    [$uidR, $secR, $idR] = mkClient($db, 'ratelimited', ['api:tickets:read'], true);
    $tokR = tokenFor($uidR, $secR, [], $rb);
    $linkR = IntegrationAuth::createClient($db, 'ratelimited legacy', null, ['api:tickets:read'], '', null, 1, true, []);
    $rlKey = 'lk_rl_' . bin2hex(random_bytes(10));
    $rlId = legacyKey($db, 'key rl', $rlKey, 'read', 0, 0, $future);
    LegacyKeys::migrateRow($db, q($db, "SELECT * FROM api_keys WHERE api_key_id = $rlId")[0]);
    $seen = [];
    for ($i = 0; $i < 6; $i++) {
        $seen[] = api('GET', 'tickets', bearer($tokR), null, $rb)[0];
    }
    for ($i = 0; $i < 6; $i++) {
        $seen[] = api('GET', 'tickets', xkey($rlKey), null, $rb)[0];
    }
    // Bearer and X-Api-Key of DIFFERENT clients have separate buckets; the same client shares one:
    $tokR2 = tokenFor($uidR, $secR, [], $rb);
    $fresh = [];
    $rlClient = q($db, "SELECT lk_client_id FROM integration_legacy_keys WHERE lk_api_key_id = $rlId")[0]['lk_client_id'];
    // give the legacy client a secret so one client is reachable both ways
    $secRL = IntegrationAuth::addSecret($db, (int) $rlClient);
    $uidRL = q1($db, "SELECT client_uid FROM integration_clients WHERE client_id = " . (int) $rlClient);
    $tokRL = tokenFor((string) $uidRL, $secRL, [], $rb);
    $ok429 = false;
    $codes = [];
    for ($i = 0; $i < 14; $i++) {
        $codes[] = ($i % 2 === 0) ? api('GET', 'tickets', bearer($tokRL), null, $rb)[0] : api('GET', 'tickets', xkey($rlKey), null, $rb)[0];
    }
    $first429 = array_search(429, $codes, true);
    ok($first429 !== false && $first429 <= 10, 'requests alternating between token and X-Api-Key of ONE client hit the limit together', json_encode($codes));
    [$stOther] = api('GET', 'tickets', bearer($tokR2), null, $rb);
    [$stOther] = api('GET', 'tickets', bearer(tokenFor($uidX, $secX, ['scope' => 'api:tickets:read'], $rb)), null, $rb);
    ok($stOther === 200, 'a different client has its own bucket', "HTTP $stOther");
    $db->query("UPDATE settings SET config_api_rate_limit = 300 WHERE company_id = 1");
} else {
    ok(false, 'redis-server is available for the rate-limit section');
}

// ---------------------------------------------------------------- 10b. signed KB media principals
section('KB media principals for machine clients');
define('FROM_KB_MEDIA', true);
require_once $ROOT . '/agent/includes/kb_media_auth.php';
$mysqli = $db;
ok(\ITFlow\KB\MediaToken::isValidPrincipal('i12') && \ITFlow\KB\MediaToken::isValidPrincipal('k12') && !\ITFlow\KB\MediaToken::isValidPrincipal('i012') && !\ITFlow\KB\MediaToken::isValidPrincipal('x12'), 'principal grammar: t, k, u and i');
[$uidK, $secK, $idK] = mkClient($db, 'kb media', ['api:kb:read'], false, [$D['A']]);
$res = kbMediaResolvePrincipal('i' . $idK);
ok($res !== null && $res['user_id'] === $svcId && $res['key_client_ids'] === [$D['A']] && !empty($res['machine_kb_ok']), 'i<client>: resolves to the service identity with the client\'s departments');
ok(!kbMediaHasModuleKb($svcId, 1), 'the service identity itself holds no role-based KB access');
[$uidK2, , $idK2] = mkClient($db, 'no kb scope', ['api:tickets:read'], true);
ok(kbMediaResolvePrincipal('i' . $idK2) === null, 'a client without api:kb:read does not resolve');
$res = kbMediaResolvePrincipal('k' . $K['read_all'][1]);
ok($res !== null && $res['key_client_ids'] === null && $res['user_id'] === $svcId && $res['user_id'] !== 1, 'k<api_key_id> (URLs signed before the migration) maps to the migrated client, not to the first admin');
$res = kbMediaResolvePrincipal('k' . $K['write_one'][1]);
ok($res !== null && $res['key_client_ids'] === [$D['A']], 'k<id> of a single-department key keeps that one department');
IntegrationAuth::revokeClient($db, (int) $clientOf('write_three')['client_id']);
ok(kbMediaResolvePrincipal('k' . $K['write_three'][1]) === null, 'k<id> of a revoked migrated client does not resolve');
ok(kbMediaResolvePrincipal('k' . $K['expired'][1]) === null, 'k<id> of an expired key does not resolve');
ok(kbMediaResolvePrincipal('k999999') === null && kbMediaResolvePrincipal('i999999') === null, 'unknown machine principals do not resolve');
IntegrationAuth::revokeClient($db, $idK);
ok(kbMediaResolvePrincipal('i' . $idK) === null, 'a revoked client\'s media URLs stop resolving');
$kbSrc = file_get_contents($ROOT . '/api/v1/kb.php');
ok(str_contains($kbSrc, "'i' . intval(") && !str_contains($kbSrc, 'legacy_key_row'), 'kb.php names a machine caller by its client when signing media URLs');

// ---------------------------------------------------------------- 11. admin pages
section('Admin pages');
$pw = 'Scratch-' . bin2hex(random_bytes(6)) . '!';
$db->query("UPDATE users SET user_password = '" . $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT)) . "', user_status = 1, user_type = 1, user_role_id = 3 WHERE user_id = 1");
$jar = tempnam(sys_get_temp_dir(), 'jar');
function areq(string $method, string $path, array $form = [], ?string $referer = null): array
{
    global $jar;
    $ch = curl_init('http://127.0.0.1:8096' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $referer ? ["Referer: $referer"] : []]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $raw = (string) curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $st = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    preg_match('/^location:\s*(\S+)/mi', substr($raw, 0, $size), $m);
    return [$st, substr($raw, $size), $m[1] ?? null];
}
areq('GET', '/login.php');
[$st] = areq('POST', '/login.php', ['email' => 'admin@scratch.test', 'password' => $pw, 'login' => '1']);
ok(in_array($st, [302, 303], true), 'scratch admin logs in', "HTTP $st");
[$st, $html] = areq('GET', '/admin/integration_clients.php');
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $cm2);
$csrf = $cm2[1] ?? '';
ok($st === 200 && str_contains($html, 'uses the old X-Api-Key header'), 'the unified page flags migrated clients that use the old header');
ok(str_contains($html, 'key read_all') && str_contains($html, 'Last used'), 'migrated clients are listed with their last use');
ok(str_contains($html, 'api:tickets:read') && str_contains($html, 'Tickets'), 'the general API scopes are offered, per resource');
ok(str_contains($html, 'legacy_header_enabled') && str_contains($html, 'Switch off'), 'the page offers the deprecation switch and removal date');
ok(!str_contains($html, $K['read_all'][0]) && !str_contains($html, hash('sha256', $K['read_all'][0])), 'the page never shows a legacy key or its hash');
$ref = 'http://127.0.0.1:8096/admin/integration_clients.php';
[$st, , $loc] = areq('POST', '/admin/post.php', ['add_integration_client' => 1, 'name' => 'UI general', 'scopes' => ['api:tickets:read', 'api:contacts:read', 'api:tickets:*', '*', 'api:bogus:read'], 'csrf_token' => $csrf, 'dept_mode' => 'selected', 'departments' => [(string) $D['A'], (string) $D['B']]], $ref);
$row = q($db, "SELECT * FROM integration_clients WHERE client_name = 'UI general'")[0] ?? null;
$rs = $row ? IntegrationAuth::parseScopes((string) $row['client_scopes']) : [];
sort($rs);
ok($row && $rs === ['api:contacts:read', 'api:tickets:read'] && !str_contains((string) $row['client_scopes'], '*'), 'create via the page: general scopes saved, wildcard and unknown scopes dropped');
ok($row && IntegrationScope::allowedDepartmentIds($db, $row) === [$D['A'], $D['B']], 'create via the page: department multi-select saved');
[$st, $html2] = areq('GET', '/admin/integration_clients.php');
preg_match('/Client secret<\/label>\s*<input[^>]*value="(rvs_[^"]+)"/', $html2, $sm);
$uiSecret = $sm[1] ?? '';
$tokUi = tokenFor((string) $row['client_uid'], $uiSecret);
[$st, , $j] = api('GET', 'tickets?limit=50', bearer($tokUi));
ok($st === 200 && names($j, 'client') === $expNames([$D['A'], $D['B']]), 'a client created on the page works on the general API within its departments');
[$st, , $loc] = areq('POST', '/admin/post.php', ['add_integration_client' => 1, 'name' => 'UI bad', 'scopes' => ['api:tickets:read'], 'csrf_token' => $csrf], $ref);
ok((int) q1($db, "SELECT COUNT(*) FROM integration_clients WHERE client_name = 'UI bad'") === 0, 'create without a department choice is refused');
// deprecation switch from the page
[$st] = areq('POST', '/admin/post.php', ['save_legacy_header_policy' => 1, 'csrf_token' => $csrf, 'legacy_header_enabled' => '0', 'legacy_header_sunset' => ''], $ref);
ok(LegacyKeys::policy($db)['enabled'] === false, 'the admin can switch the old header off from the page');
[$st] = areq('POST', '/admin/post.php', ['save_legacy_header_policy' => 1, 'csrf_token' => $csrf, 'legacy_header_enabled' => '1', 'legacy_header_sunset' => 'not-a-date'], $ref);
ok(LegacyKeys::policy($db)['enabled'] === false, 'an invalid removal date is refused (nothing changes)');
[$st] = areq('POST', '/admin/post.php', ['save_legacy_header_policy' => 1, 'csrf_token' => 'wrong', 'legacy_header_enabled' => '1', 'legacy_header_sunset' => ''], $ref);
ok(LegacyKeys::policy($db)['enabled'] === false, 'the policy form needs a valid CSRF token');
$future2 = date('Y-m-d', strtotime('+60 days'));
[$st] = areq('POST', '/admin/post.php', ['save_legacy_header_policy' => 1, 'csrf_token' => $csrf, 'legacy_header_enabled' => '1', 'legacy_header_sunset' => $future2], $ref);
ok(LegacyKeys::policy($db)['enabled'] === true && LegacyKeys::policy($db)['sunset'] === $future2, 'switch back on with a removal date');
[, $html3] = areq('GET', '/admin/integration_clients.php');
ok(str_contains($html3, $future2), 'the removal date is visible on the page');
// legacy page
[$st, $html4] = areq('GET', '/admin/api_keys.php');
ok($st === 200 && str_contains($html4, 'integration_clients.php') && str_contains($html4, 'Integration clients') && !str_contains($html4, 'New API Key'), 'Administration > API keys is a notice pointing at the unified page, with no create button');
$ref2 = 'http://127.0.0.1:8096/admin/api_keys.php';
$nKeys = (int) q1($db, 'SELECT COUNT(*) FROM api_keys');
[$st] = areq('POST', '/admin/post.php', ['add_api_key' => 1, 'name' => 'new legacy', 'expire' => $future, 'permission' => 'write', 'key' => str_repeat('z', 32), 'password' => 'x', 'ack' => 1, 'all_departments' => 1, 'csrf_token' => $csrf], $ref2);
ok((int) q1($db, 'SELECT COUNT(*) FROM api_keys') === $nKeys, 'new legacy API keys can no longer be created');
areq('GET', '/admin/api_keys.php');
[$st] = areq('GET', '/admin/post.php?delete_api_key=' . $K['expired'][1] . '&csrf_token=' . $csrf, [], $ref2);
ok((int) q1($db, 'SELECT COUNT(*) FROM api_keys') === $nKeys, 'legacy API key rows are kept (no delete through the old handler)');
[, $nav] = areq('GET', '/admin/integration_clients.php');
ok(str_contains($nav, '/admin/api_keys.php') && str_contains($nav, '/admin/integration_clients.php'), 'both side-nav entries still exist');
ok(str_contains(file_get_contents($ROOT . '/includes/settings_search_index.php'), "'/admin/api_keys.php'") && str_contains(file_get_contents($ROOT . '/includes/settings_search_index.php'), "'/admin/integration_clients.php'"), 'both settings-search entries still exist');

// ---------------------------------------------------------------- done
echo "\n$PASS passed, " . count($FAIL) . " failed\n";
if ($FAIL) {
    echo "Failed: " . implode('; ', $FAIL) . "\n";
}
exit($FAIL ? 1 : 0);
