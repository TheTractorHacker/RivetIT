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
ok(preg_match('/"(2\.6\.\d+)"/', file_get_contents($ROOT . '/includes/database_version.php'), $lv) === 1 && version_compare($lv[1], '2.6.204', '>='), 'LATEST_DATABASE_VERSION is 2.6.204 or later');
$runMigration = static function () use ($block, $db): void {
    $mysqli = $db;
    $rivetit_db_version = static fn() => q1($db, 'SELECT config_current_database_version FROM settings WHERE company_id = 1');
    // The suite deliberately runs the REAL migration text from the repository (trusted, in-tree code) against the scratch database.
    eval($block);
};
$runMigration();
ok(version_compare((string) q1($db, 'SELECT config_current_database_version FROM settings WHERE company_id = 1'), '2.6.204', '>='), 'the version moved to 2.6.204 or later');
ok(q1($db, "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_clients' AND COLUMN_NAME = 'client_scopes'") === 'text', 'client_scopes was widened to text');
ok(q1($db, "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_tokens' AND COLUMN_NAME = 'token_scope'") === 'text', 'token_scope was widened to text');
ok((int) q1($db, 'SELECT COUNT(*) FROM integration_clients') === count($K), 'one integration client per legacy key', (string) q1($db, 'SELECT COUNT(*) FROM integration_clients'));
ok((int) q1($db, 'SELECT COUNT(*) FROM integration_legacy_keys') === count($K), 'one link row per legacy key');
ok(q($db, 'SELECT * FROM api_keys ORDER BY api_key_id') === $beforeApiKeys, 'api_keys is untouched (rollback possible)');
ok((int) q1($db, 'SELECT COUNT(*) FROM integration_client_secrets') === 0, 'no secret is created for a migrated client (an administrator creates one)');
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
// A key created after the migration: there is no first-use conversion any more, so it never becomes a client.
$late = 'lk_late_' . bin2hex(random_bytes(10));
$lateId = legacyKey($db, 'key late', $late, 'read', 0, 0, $future);
ok((int) q1($db, "SELECT COUNT(*) FROM integration_legacy_keys WHERE lk_api_key_id = $lateId") === 0, 'a key added after the migration is not linked');

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

// ---------------------------------------------------------------- 3. the X-Api-Key header is gone; migrated clients live on as ordinary clients
section('X-Api-Key is not accepted any more');
$secretsOf = static fn(string $label): int => (int) q1($db, 'SELECT COUNT(*) FROM integration_client_secrets WHERE secret_client_id = ' . (int) $clientOf($label)['client_id']);
foreach (array_keys($K) as $label) {
    [$st, $hdr, $j] = api('GET', 'tickets', xkey($K[$label][0]));
    ok($st === 401 && str_contains((string) ($j['error'] ?? ''), 'no longer accepted') && str_contains((string) $j['error'], 'oauth/token') && ($hdr['www-authenticate'] ?? '') === 'Bearer'
        && !isset($hdr['deprecation']) && !isset($hdr['sunset']) && !isset($hdr['x-api-key-notice']), "$label: the (still valid) key string answers 401 pointing at the token endpoint, with no deprecation headers", "HTTP $st");
}
[$st] = api('GET', 'tickets?api_key=' . urlencode($K['read_all'][0]), []);
ok($st === 401, 'the key in the query string authenticates nothing');
[$st] = api('POST', 'tickets', [], ['api_key' => $K['write_all'][0]]);
ok($st === 401, 'the key in the JSON body authenticates nothing');
[$st] = api('GET', 'lms/courses', xkey($K['read_all'][0]));
ok($st === 401, 'X-Api-Key on /lms is 401');
[$st] = api('GET', 'employees', xkey($K['read_all'][0]));
ok($st === 401, 'X-Api-Key on /employees is 401');
[$st] = http('GET', '/api/v1/validate_api_key.php', xkey($K['read_all'][0]));
[$st2] = http('GET', '/api/v1/validate_api_key.php?api_key=' . urlencode($K['read_all'][0]));
ok($st === 401 && $st2 === 401, 'the retired per-file handler gate (validate_api_key.php) answers 401 for a valid old key, header or query');
[$st] = api('GET', 'tickets', xkey($late));
ok($st === 401 && (int) q1($db, "SELECT COUNT(*) FROM integration_legacy_keys WHERE lk_api_key_id = $lateId") === 0 && (int) q1($db, "SELECT COUNT(*) FROM integration_clients WHERE client_name = 'key late'") === 0, 'first-use conversion is gone: a key added after the migration is 401 and creates no client');
// the migrated clients keep scopes, departments, IP list and expiry but have no secret yet
ok($secretsOf('read_all') === 0 && (int) q1($db, 'SELECT COUNT(*) FROM integration_clients') === count($K), 'migrated clients are ordinary clients with no secret yet');
[$st] = http('POST', '/api/v1/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'client_credentials', 'client_id' => $clientOf('read_all')['client_uid'], 'client_secret' => 'rvs_' . bin2hex(random_bytes(16))]));
ok($st === 401, 'a migrated client without a secret cannot get a token');
// Create secret -> token flow, as an administrator would do it
$migTok = static function (string $label) use ($db, $clientOf): string {
    static $cache = [];
    if (!isset($cache[$label])) {
        $c = $clientOf($label);
        $cache[$label] = tokenFor((string) $c['client_uid'], IntegrationAuth::addSecret($db, (int) $c['client_id']));
    }
    return $cache[$label];
};
foreach ([
    'read_all' => $all4, 'write_all' => $all4, 'write_one' => [$D['A']], 'read_several' => [$D['A'], $D['B']],
    'write_three' => [$D['A'], $D['B'], $D['C']], 'sentinel_norows' => [], 'zero_with_rows' => [$D['B']],
] as $label => $allowed) {
    $auth = bearer($migTok($label));
    $exp = $expNames($allowed);
    foreach (['tickets' => 'client', 'contacts' => 'client', 'assets' => 'client'] as $res => $col) {
        [$st, , $j] = api('GET', "$res?limit=50", $auth);
        ok($st === 200 && names($j, $col) === $exp, "$label: after Create secret, GET $res lists exactly its departments", "HTTP $st " . json_encode(names($j, $col)));
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

section('Migrated permission semantics through the scopes (by token)');
[$st] = api('POST', 'tickets/' . $ticketOf[$D['A']] . '/reply', bearer($migTok('read_all')), ['reply' => 'x']);
ok($st === 403, 'a read client cannot write (403)', "HTTP $st");
[$st, , $j] = api('POST', 'tickets/' . $ticketOf[$D['A']] . '/reply', bearer($migTok('write_all')), ['reply' => 'machine reply']);
ok(in_array($st, [200, 201], true), 'a write client can write', "HTTP $st " . json_encode($j));
[$st, , $j] = api('DELETE', 'tickets/' . $ticketOf[$D['A']], bearer($migTok('write_all')));
ok($st === 403 && str_contains((string) ($j['error'] ?? ''), 'api:tickets:delete'), 'a write client without delete cannot DELETE (403 names the missing scope)', "HTTP $st " . json_encode($j));
[$st, , $j] = api('DELETE', 'tickets/' . $ticketOf[$D['A']], bearer($migTok('write_del_all')));
ok(!(($st === 403) && str_contains((string) ($j['error'] ?? ''), 'scope')), 'a write + delete client passes the scope check for DELETE', "HTTP $st");
[$st] = api('POST', 'tickets/' . $ticketOf[$D['A']] . '/reply', bearer($migTok('read_with_delete_flag')), ['reply' => 'x']);
ok($st === 403, 'a read client converted from a key with the delete flag still cannot write', "HTTP $st");
foreach (['expired', 'revoked_today'] as $l) {
    $c = $clientOf($l);
    [$st] = http('POST', '/api/v1/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'client_credentials', 'client_id' => $c['client_uid'], 'client_secret' => IntegrationAuth::addSecret($db, (int) $c['client_id'])]));
    ok($st === 401, "$l: an expired migrated client cannot get a token", "HTTP $st");
}
$c = $clientOf('ip_locked');
[$st] = http('POST', '/api/v1/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'client_credentials', 'client_id' => $c['client_uid'], 'client_secret' => IntegrationAuth::addSecret($db, (int) $c['client_id'])]));
ok($st === 401, 'an IP-restricted migrated client (203.0.113.0/24) cannot get a token from this address', "HTTP $st");
[$st] = api('GET', 'tickets', bearer($migTok('ip_ok')));
ok($st === 200, 'a migrated client allowed from this address works', "HTTP $st");
foreach (['credentials', 'me', 'notifications', 'approvals', 'endpoint_devices', 'service_catalog', 'workflow_tasks'] as $dr) {
    [$st] = api('GET', $dr, bearer($migTok('write_del_all')));
    ok($st === 403, "a client -> $dr is 403 (person-bound or user-token only)", "HTTP $st");
}
[$st] = api('GET', 'validate_api_key', bearer($migTok('read_all')));
ok($st === 200, 'validate_api_key answers 200 for a valid access token', "HTTP $st");
[$st] = api('GET', 'validate_api_key', xkey('nope'));
ok($st === 401, 'validate_api_key answers 401 for an old key');
[$st] = api('GET', 'no-such-resource', bearer($migTok('read_all')));
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
ok($reply && (int) $reply[0]['ticket_reply_by'] === $svcId, 'a write made by a migrated client is recorded against the service identity', json_encode($reply ? $reply[0]['ticket_reply_by'] : null));
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

// ---------------------------------------------------------------- 7. the header's removal: audit, no counters, no machinery
section('X-Api-Key removal: audit, counters and machinery');
$useBefore = (int) q1($db, 'SELECT SUM(lk_use_count) FROM integration_legacy_keys');
$refBefore = (int) q1($db, "SELECT COUNT(*) FROM logs WHERE log_type = 'Integration API' AND log_action = 'Legacy Key Refused'");
$db->query("UPDATE integration_settings SET setting_value = '1' WHERE setting_key = 'legacy_header_enabled'");   // even a stale "accepted" row changes nothing
[$st, , $j] = api('GET', 'tickets', xkey($K['read_all'][0]));
ok($st === 401 && str_contains((string) ($j['error'] ?? ''), 'no longer accepted'), 'a stale "accepted" setting in the database does not bring the header back');
ok((int) q1($db, "SELECT COUNT(*) FROM logs WHERE log_type = 'Integration API' AND log_action = 'Legacy Key Refused'") > $refBefore, 'a refused X-Api-Key is audited (which address still sends it)');
ok((int) q1($db, 'SELECT SUM(lk_use_count) FROM integration_legacy_keys') === $useBefore, 'no per-key header usage is counted any more');
$logTxt = (string) q1($db, "SELECT GROUP_CONCAT(log_description) FROM logs WHERE log_type = 'Integration API'");
ok(!str_contains($logTxt, $K['read_all'][0]) && !str_contains($logTxt, hash('sha256', $K['read_all'][0])), 'the audit log never contains the key or its hash');
[$st, $hdr] = api('GET', 'tickets', bearer($tokT));
ok($st === 200 && !isset($hdr['deprecation']), 'a Bearer request carries no deprecation header');
$idxSrc = file_get_contents($ROOT . '/api/v1/index.php');
ok(!str_contains($idxSrc, 'Deprecation: true') && !str_contains($idxSrc, "'Sunset") && !str_contains($idxSrc, 'X-Api-Key-Notice') && !str_contains($idxSrc, 'LegacyKeys::resolve') && !str_contains($idxSrc, 'recordUse'), 'the router no longer carries the deprecation machinery');
ok(!method_exists(LegacyKeys::class, 'resolve') && !method_exists(LegacyKeys::class, 'recordUse') && !method_exists(LegacyKeys::class, 'policy') && !method_exists(LegacyKeys::class, 'setPolicy'), 'LegacyKeys keeps only the upgrade conversion (no resolve / usage / policy)');
ok(!str_contains(file_get_contents($ROOT . '/api/v1/validate_api_key.php'), 'mysqli') && !str_contains(file_get_contents($ROOT . '/api/v1/validate_api_key.php'), 'SELECT'), 'validate_api_key.php no longer reads the api_keys table');

// ---------------------------------------------------------------- 8. a migrated client is an ordinary client
section('A migrated client goes through the same rules');
$cw = $clientOf('write_all');
$cwId = (int) $cw['client_id'];
$tk = $migTok('write_all');
// an admin narrows it on the page: its token obeys at the next request
$db->query("UPDATE integration_clients SET client_scopes = 'api:tickets:read' WHERE client_id = $cwId");
[$st] = api('GET', 'tickets', bearer($tk));
[$st2] = api('GET', 'contacts', bearer($tk));
ok($st === 200 && $st2 === 403, 'editing a migrated client\'s scopes narrows its token at once');
IntegrationScope::save($db, $cwId, false, [$D['C']]);
[$st, , $j] = api('GET', 'tickets?limit=50', bearer($tk));
ok($st === 200 && names($j, 'client') === ['Dept C'], 'editing a migrated client\'s departments applies to its token');
$db->query("UPDATE integration_clients SET client_expires_at = NOW() - INTERVAL 1 MINUTE WHERE client_id = $cwId");
[$st] = api('GET', 'tickets', bearer($tk));
ok($st === 401, 'an expired client\'s token is refused');
$db->query("UPDATE integration_clients SET client_expires_at = NULL WHERE client_id = $cwId");
IntegrationAuth::revokeClient($db, $cwId);
[$st] = api('GET', 'tickets', bearer($tk));
ok($st === 401, 'revoking the client stops its token at the next request');
$db->query("UPDATE integration_clients SET client_allowed_ips = '203.0.113.9' WHERE client_id = " . (int) $clientOf('ip_ok')['client_id']);
[$st] = api('GET', 'tickets', bearer($migTok('ip_ok')));
ok(in_array($st, [401, 403], true), 'the client\'s IP allow-list applies to its token', "HTTP $st");
ok((int) q1($db, 'SELECT COUNT(*) FROM integration_legacy_keys') === count($K), 'every migrated client is linked to its old key row (rollback data)');

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
    $seen = [];
    for ($i = 0; $i < 14; $i++) {
        $seen[] = api('GET', 'tickets', bearer($tokR), null, $rb)[0];
    }
    $first429 = array_search(429, $seen, true);
    ok($first429 !== false && $first429 <= 10, 'one client\'s requests hit the limit (one bucket per client)', json_encode($seen));
    $tokR2 = tokenFor($uidR, $secR, [], $rb);
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
ok(kbMediaResolvePrincipal('k' . $K['read_all'][1]) === null && kbMediaResolvePrincipal('k' . $K['write_one'][1]) === null && kbMediaResolvePrincipal('k' . $K['expired'][1]) === null, 'k<api_key_id> (URLs signed before the migration) fails closed: legacy keys are not a credential any more');
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
// The old URL is only a redirect to the canonical page (query string kept), and answers before any login is needed
[$st, , $loc] = areq('GET', '/admin/integration_clients.php?x=1');
ok(in_array($st, [301, 302], true) && $loc === '/admin/api_keys.php?x=1', 'Administration > Integration clients redirects to /admin/api_keys.php', "HTTP $st $loc");
// a converted key that has no secret yet
$nsId = legacyKey($db, 'key nosecret', 'lk_ns_' . bin2hex(random_bytes(8)), 'read', 0, 0, $future);
LegacyKeys::migrateRow($db, q($db, "SELECT * FROM api_keys WHERE api_key_id = $nsId")[0]);
[$st, $html] = areq('GET', '/admin/api_keys.php');
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $cm2);
$csrf = $cm2[1] ?? '';
ok($st === 200 && str_contains($html, '<title>') && !str_contains($html, 'Old X-Api-Key header') && !str_contains($html, 'legacy-header') && !str_contains($html, 'legacy_header_enabled') && !str_contains($html, 'deprecated'), 'the page carries no old-header badge, banner or deprecation switch');
ok(str_contains($html, 'No secret yet') && str_contains($html, 'create one so the other system can sign in') && str_contains($html, 'Converted from old API key #'), 'a migrated client without a secret reads "No secret yet: create one so the other system can sign in"');
ok(str_contains($html, 'key read_all') && str_contains($html, 'Last used'), 'migrated keys are listed with their last use');
ok(str_contains($html, 'Read only: 20 areas') && str_contains($html, 'Read &amp; write: 20 areas') && !str_contains($html, 'api alerts: read'), 'access is a short summary, not one badge per scope');
ok(str_contains($html, 'data-ak-confirm="delete"') && str_contains($html, 'id="akConfirm"') && !str_contains($html, 'return confirm('), 'revoke / delete use the in-page confirmation pop-up, not the browser confirm()');
ok(!str_contains($html, 'Integration clients') && !str_contains($html, 'integration_clients.php'), 'no user-facing "Integration clients" wording or link remains on the page');
ok(!str_contains($html, $K['read_all'][0]) && !str_contains($html, hash('sha256', $K['read_all'][0])), 'the page never shows a legacy key or its hash');
[$st, $rawAdd] = areq('GET', '/admin/modals/api/api_key_add.php');
$addJson = json_decode($rawAdd, true)['content'] ?? '';
ok($st === 200 && str_contains($addJson, 'api:tickets:read') && str_contains($addJson, 'Tickets') && str_contains($addJson, 'data-ak-preset="readwrite"'), 'the Add API key pop-up offers the general API scopes per area, with presets');
$ref = 'http://127.0.0.1:8096/admin/api_keys.php';
[$st, , $loc] = areq('POST', '/admin/post.php', ['add_integration_client' => 1, 'name' => 'UI general', 'scopes' => ['api:tickets:read', 'api:contacts:read', 'api:tickets:*', '*', 'api:bogus:read'], 'csrf_token' => $csrf, 'dept_mode' => 'selected', 'departments' => [(string) $D['A'], (string) $D['B']]], $ref);
ok($loc === '/admin/api_keys.php', 'creating a key returns to /admin/api_keys.php', (string) $loc);
$row = q($db, "SELECT * FROM integration_clients WHERE client_name = 'UI general'")[0] ?? null;
$rs = $row ? IntegrationAuth::parseScopes((string) $row['client_scopes']) : [];
sort($rs);
ok($row && $rs === ['api:contacts:read', 'api:tickets:read'] && !str_contains((string) $row['client_scopes'], '*'), 'create via the page: general scopes saved, wildcard and unknown scopes dropped');
ok($row && IntegrationScope::allowedDepartmentIds($db, $row) === [$D['A'], $D['B']], 'create via the page: department selection saved');
// the one-time secret: shown in the rendered page right after Create key, copyable, hash-only in the database, gone on the next load
[$st, $html2] = areq('GET', '/admin/api_keys.php');
preg_match('/id="akSecretValue"[^>]*>(rvs_[^<]+)</', $html2, $sm);
$uiSecret = $sm[1] ?? '';
ok($uiSecret !== '' && str_contains($html2, 'data-ak-copy="akSecretValue"') && str_contains($html2, 'id="akSecretModal"') && str_contains($html2, 'shown once'), 'Create key: the one-time secret is in the page with a Copy button and a "shown once" warning');
$secRow = q($db, 'SELECT * FROM integration_client_secrets WHERE secret_client_id = ' . (int) $row['client_id'])[0] ?? [];
ok($uiSecret !== '' && ($secRow['secret_hash'] ?? '') !== $uiSecret && password_verify($uiSecret, (string) ($secRow['secret_hash'] ?? '')) && !in_array($uiSecret, array_map('strval', $secRow), true), 'Create key: only a hash of the secret is stored');
[, $htmlAgain] = areq('GET', '/admin/api_keys.php');
ok(!str_contains($htmlAgain, 'akSecretModal') && !str_contains($htmlAgain, $uiSecret), 'the secret is not shown a second time');
$tokUi = tokenFor((string) $row['client_uid'], $uiSecret);
[$st, , $j] = api('GET', 'tickets?limit=50', bearer($tokUi));
ok($st === 200 && names($j, 'client') === $expNames([$D['A'], $D['B']]), 'a key created on the page works on the general API within its departments');
[$st, , $loc] = areq('POST', '/admin/post.php', ['add_integration_client' => 1, 'name' => 'UI bad', 'scopes' => ['api:tickets:read'], 'csrf_token' => $csrf], $ref);
ok((int) q1($db, "SELECT COUNT(*) FROM integration_clients WHERE client_name = 'UI bad'") === 0, 'create without a department choice is refused');
// Create secret on a migrated key (no secret yet), then Rotate: both show the secret the same way
$migId = (int) $clientOf('read_all')['client_id'];
$db->query("DELETE FROM integration_client_secrets WHERE secret_client_id = $migId");
[, , $loc] = areq('POST', '/admin/post.php', ['rotate_integration_secret' => 1, 'client_id' => $migId, 'overlap_hours' => 24, 'csrf_token' => $csrf], $ref);
[, $htmlCs] = areq('GET', '/admin/api_keys.php');
preg_match('/id="akSecretValue"[^>]*>(rvs_[^<]+)</', $htmlCs, $sm2);
$csSecret = $sm2[1] ?? '';
$csRow = q($db, "SELECT * FROM integration_client_secrets WHERE secret_client_id = $migId ORDER BY secret_id DESC")[0] ?? [];
ok($loc === '/admin/api_keys.php' && $csSecret !== '' && str_contains($htmlCs, 'data-ak-copy="akSecretValue"'), 'Create secret on a migrated key shows the new secret in the page');
ok($csSecret !== '' && password_verify($csSecret, (string) ($csRow['secret_hash'] ?? '')) && ($csRow['secret_hash'] ?? '') !== $csSecret, 'Create secret: only a hash is stored');
areq('POST', '/admin/post.php', ['rotate_integration_secret' => 1, 'client_id' => $migId, 'overlap_hours' => 1, 'csrf_token' => $csrf], 'http://127.0.0.1:8096/admin/integration_clients.php');   // old Referer still reaches the handler
[, $htmlRot] = areq('GET', '/admin/api_keys.php');
preg_match('/id="akSecretValue"[^>]*>(rvs_[^<]+)</', $htmlRot, $sm3);
ok(($sm3[1] ?? '') !== '' && ($sm3[1] ?? '') !== $csSecret && str_contains($htmlRot, 'New secret created'), 'Rotate secret shows the new secret the same way (also through the old Referer)');
$db->query("DELETE FROM integration_client_secrets WHERE secret_client_id = $migId");
// the retired legacy-key actions change nothing
$nKeys = (int) q1($db, 'SELECT COUNT(*) FROM api_keys');
[$st] = areq('POST', '/admin/post.php', ['add_api_key' => 1, 'name' => 'new legacy', 'expire' => $future, 'permission' => 'write', 'key' => str_repeat('z', 32), 'password' => 'x', 'ack' => 1, 'all_departments' => 1, 'csrf_token' => $csrf], $ref);
ok((int) q1($db, 'SELECT COUNT(*) FROM api_keys') === $nKeys, 'new legacy API keys can no longer be created');
areq('GET', '/admin/api_keys.php');
[$st] = areq('GET', '/admin/post.php?delete_api_key=' . $K['expired'][1] . '&csrf_token=' . $csrf, [], $ref);
ok((int) q1($db, 'SELECT COUNT(*) FROM api_keys') === $nKeys, 'legacy API key rows are kept (no delete through the old handler)');
$polBefore = q1($db, "SELECT setting_value FROM integration_settings WHERE setting_key = 'legacy_header_enabled'");
areq('POST', '/admin/post.php', ['save_legacy_header_policy' => 1, 'csrf_token' => $csrf, 'legacy_header_enabled' => '0', 'legacy_header_sunset' => '2030-01-01'], $ref);
ok(q1($db, "SELECT setting_value FROM integration_settings WHERE setting_key = 'legacy_header_enabled'") === $polBefore, 'the removed deprecation switch is not handled any more');
[, $nav] = areq('GET', '/admin/api_keys.php');
ok(str_contains($nav, '>API Keys<') && str_contains($nav, '/admin/api_keys.php') && !str_contains($nav, 'API Keys (legacy)'), 'one side-nav entry: API Keys');
$sidenav = file_get_contents($ROOT . '/admin/includes/side_nav.php');
$ssi = file_get_contents($ROOT . '/includes/settings_search_index.php');
ok(!str_contains($sidenav, 'Integration clients') && !str_contains($sidenav, 'integration_clients.php'), 'the side nav no longer lists Integration clients');
ok(str_contains($ssi, "'label' => 'API Keys'") && str_contains($ssi, "'/admin/api_keys.php'") && !str_contains($ssi, 'integration_clients.php')
    && str_contains($ssi, "'integration'") && str_contains($ssi, "'oauth'") && str_contains($ssi, "'client credentials'") && str_contains($ssi, "'qms'"), 'settings search: one "API Keys" entry with the integration / oauth / client credentials / qms keywords');

// ---------------------------------------------------------------- 12. access summary helper
section('Access summary (ApiScopes::summary)');
$allRes = array_keys(ApiScopes::RESOURCES);
$mk = static function (array $acts, ?array $res = null) use ($allRes): array {
    $o = [];
    foreach ($res ?? $allRes as $r) {
        foreach ($acts as $a) {
            $o[] = "api:$r:$a";
        }
    }
    return $o;
};
ok(ApiScopes::summary($mk(['read']))['headline'] === 'Read only: 20 areas', 'every area read -> "Read only: 20 areas"');
ok(ApiScopes::summary($mk(['read', 'write']))['headline'] === 'Read & write: 20 areas', 'every area read+write -> "Read & write: 20 areas"');
ok(ApiScopes::summary($mk(['read', 'write', 'delete']))['headline'] === 'Read, write & delete: 20 areas', 'every area all three -> "Read, write & delete: 20 areas"');
ok(ApiScopes::summary($mk(['read'], ['tickets']))['headline'] === 'Read only: 1 area', 'one area is singular');
$cu = ApiScopes::summary(['api:tickets:read', 'api:tickets:write', 'api:assets:read', 'api:contacts:read', 'api:kb:read']);
ok($cu['headline'] === 'Custom: Assets (read), Contacts (read) +2' && $cu['areas'] === 4, 'mixed access -> "Custom: Assets (read), Contacts (read) +2"', $cu['headline']);
$tk = array_values(array_filter($cu['groups'], static fn($g) => $g['resource'] === 'tickets'))[0] ?? [];
ok(($tk['marks'] ?? '') === 'RW-' && str_contains($cu['text'], 'Tickets (and statuses): read, write'), 'groups carry R / W / D marks and the tooltip text lists every area');
ok(ApiScopes::summary([])['headline'] === 'No access' && ApiScopes::summary(['lms:read', 'sso'])['extras'] === ['LMS: read', 'Single sign-on'] && ApiScopes::summary(['lms:read'])['headline'] === 'No general API access', 'no scopes / only LMS and sign-in scopes are described plainly');
ok(ApiScopes::summary(['api:tickets:read', 'api:tickets:read'])['areas'] === 1 && ApiScopes::summary(['api:*:read'])['areas'] === 0, 'duplicates collapse and a wildcard counts as nothing');

// ---------------------------------------------------------------- 13. delete a key
section('Delete an API key');
$delSecret = 'lk_delete_' . bin2hex(random_bytes(10));
$delId = legacyKey($db, 'key to delete', $delSecret, 'read', 0, 0, $future);
LegacyKeys::migrateRow($db, q($db, "SELECT * FROM api_keys WHERE api_key_id = $delId")[0]);   // as the 2.6.204 update would have done
$delClient = (int) q1($db, "SELECT lk_client_id FROM integration_legacy_keys WHERE lk_api_key_id = $delId");
ok($delClient > 0, 'an old key converted by the update becomes a client');
[$st] = api('GET', 'tickets', xkey($delSecret));
ok($st === 401, 'its old key string is 401 before the delete too');
$delSecretNew = IntegrationAuth::addSecret($db, $delClient);
$delUid = (string) q1($db, "SELECT client_uid FROM integration_clients WHERE client_id = $delClient");
$delTok = tokenFor($delUid, $delSecretNew);
[$st] = api('GET', 'tickets', bearer($delTok));
ok($st === 200, 'its access token works before the delete');
$db->query("INSERT INTO integration_client_departments (client_id, department_id) VALUES ($delClient, {$D['A']}) ON DUPLICATE KEY UPDATE department_id = department_id");
// single sign-on registration on a separate client, to prove the oidc rows go too
[$ssoUid, , $ssoId] = mkClient($db, 'sso to delete', ['sso'], true);
\ITFlow\Oidc\OidcConfig::saveClient($db, $ssoId, 'https://sso-del.example/cb', null, false);
ok(\ITFlow\Oidc\OidcConfig::ssoClient($db, $ssoUid) !== null, 'the sso client resolves before the delete');
$db->query("INSERT INTO oidc_pending (opend_hash, opend_client_id, opend_redirect_uri, opend_scope, opend_state, opend_nonce, opend_challenge, opend_force_login, opend_max_age, opend_expires_at) VALUES ('" . str_repeat('a', 64) . "', $ssoId, 'https://sso-del.example/cb', 'openid', 's', 'n', 'c', 0, 0, NOW() + INTERVAL 5 MINUTE)");
// refused without a valid CSRF token / by GET; nothing changes
areq('POST', '/admin/post.php', ['delete_integration_client' => 1, 'client_id' => $delClient, 'csrf_token' => 'wrong'], $ref);
[$st] = areq('GET', '/admin/post.php?delete_integration_client=1&client_id=' . $delClient . '&csrf_token=' . $csrf, [], $ref);
ok((int) q1($db, "SELECT COUNT(*) FROM integration_clients WHERE client_id = $delClient") === 1, 'delete needs a valid CSRF token and a POST');
// the real delete, from the confirmation pop-up's form
$logsBefore = (int) q1($db, "SELECT COUNT(*) FROM logs WHERE log_type = 'Integration' AND log_action = 'Client Delete'");
[$st, , $loc] = areq('POST', '/admin/post.php', ['delete_integration_client' => 1, 'client_id' => $delClient, 'csrf_token' => $csrf], $ref);
ok($loc === '/admin/api_keys.php' && (int) q1($db, "SELECT COUNT(*) FROM integration_clients WHERE client_id = $delClient") === 0, 'Delete removes the client');
foreach (['integration_client_secrets' => 'secret_client_id', 'integration_tokens' => 'token_client_id', 'integration_client_departments' => 'client_id'] as $t => $col) {
    ok((int) q1($db, "SELECT COUNT(*) FROM $t WHERE $col = $delClient") === 0, "Delete removes its rows in $t");
}
[$st] = api('GET', 'tickets', xkey($delSecret));
ok($st === 401, 'the old X-Api-Key string answers 401 after the delete', "HTTP $st");
ok((int) q1($db, "SELECT COUNT(*) FROM integration_legacy_keys WHERE lk_api_key_id = $delId") === 0 && (int) q1($db, 'SELECT COUNT(*) FROM api_keys WHERE api_key_id = ' . $delId) === 1, 'the link row goes; the api_keys row is kept as rollback data');
ok((int) q1($db, "SELECT COUNT(*) FROM integration_clients WHERE client_name = 'key to delete'") === 0 && (int) q1($db, "SELECT COUNT(*) FROM integration_clients WHERE client_name = 'key to delete'") === 0, 'no client reappears for it (no first-use conversion)');
[$st] = api('GET', 'tickets', bearer($delTok));
ok($st === 401, 'an access token issued before the delete stops working at the next request', "HTTP $st");
[$st] = http('POST', '/api/v1/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'client_credentials', 'client_id' => $delUid, 'client_secret' => $delSecretNew]));
ok($st === 401, 'the deleted key\'s Client ID and secret no longer get a token', "HTTP $st");
ok(kbMediaResolvePrincipal('k' . $delId) === null && kbMediaResolvePrincipal('i' . $delClient) === null, 'signed media URLs of the deleted key stop resolving');
$line = q($db, "SELECT log_description FROM logs WHERE log_type = 'Integration' AND log_action = 'Client Delete' ORDER BY log_id DESC LIMIT 1")[0]['log_description'] ?? '';
ok((int) q1($db, "SELECT COUNT(*) FROM logs WHERE log_type = 'Integration' AND log_action = 'Client Delete'") === $logsBefore + 1 && str_contains($line, 'key to delete') && str_contains($line, (string) $delClient)
    && !str_contains($line, $delSecretNew) && !str_contains($line, $delSecret), 'the delete is audited (who, which client id and name) without any secret');
areq('POST', '/admin/post.php', ['delete_integration_client' => 1, 'client_id' => $ssoId, 'csrf_token' => $csrf], $ref);
ok((int) q1($db, "SELECT COUNT(*) FROM oidc_client_config WHERE ocfg_client_id = $ssoId") === 0 && (int) q1($db, "SELECT COUNT(*) FROM oidc_pending WHERE opend_client_id = $ssoId") === 0
    && \ITFlow\Oidc\OidcConfig::ssoClient($db, $ssoUid) === null, 'deleting an sso client removes its sso configuration and sign-in state; sign-in through it fails closed');
// Revoke stays the reversible-record option: the row is kept
[$uidRv, , $idRv] = mkClient($db, 'revoke keeps', ['api:tickets:read'], true);
areq('POST', '/admin/post.php', ['revoke_integration_client' => 1, 'client_id' => $idRv, 'csrf_token' => $csrf], $ref);
ok(q1($db, "SELECT client_status FROM integration_clients WHERE client_id = $idRv") === 'revoked', 'Revoke keeps the client, marked revoked');
[, $htmlRv] = areq('GET', '/admin/api_keys.php');
ok(str_contains($htmlRv, 'revoke keeps') && str_contains($htmlRv, 'Revoked') && !str_contains($htmlRv, 'key to delete'), 'a revoked key stays in the list; a deleted one does not');

// ---------------------------------------------------------------- done
echo "\n$PASS passed, " . count($FAIL) . " failed\n";
if ($FAIL) {
    echo "Failed: " . implode('; ', $FAIL) . "\n";
}
exit($FAIL ? 1 : 0);
