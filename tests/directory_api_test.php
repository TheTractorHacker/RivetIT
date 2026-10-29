<?php
/*
 * Directory API v1 test suite: GET /api/v1/employees and /api/v1/departments for integration clients (scope directory:read).
 * Runs against a SCRATCH database only (refuses unless the name contains "scratch") through a php -S server on 127.0.0.1:8099.
 *
 *   RIVETIT_REDIS_PORT=1 php tests/directory_api_test.php        Redis off (the guards fail open), as the LMS suite runs
 *   DIRTEST_RATELIMIT=1  php tests/directory_api_test.php        also checks the 600/min rate limit, using Redis database 15 only
 *
 * Never prints a secret or token.
 */
chdir(dirname(__DIR__));
$ROOT = getcwd();
$RATE = getenv('DIRTEST_RATELIMIT') === '1';
if ($RATE) {
    putenv('RIVETIT_REDIS_DB=15');                 // an isolated Redis database; flushed before and after
    shell_exec('redis-cli -p 6380 -n 15 flushdb > /dev/null 2>&1');
} elseif (getenv('RIVETIT_REDIS_PORT') === false) {
    putenv('RIVETIT_REDIS_PORT=1');                // Redis unreachable: rate guards fail open
}
require_once $ROOT . '/config.php';
if (!str_contains((string) $database, 'scratch')) {
    fwrite(STDERR, "Refusing: database is not a scratch database.\n");
    exit(1);
}
require_once $ROOT . '/functions.php';

use ITFlow\Integration\IntegrationAuth;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($dbhost, $dbusername, $dbpassword, $database);
$db->set_charset('utf8mb4');
$db->query("SET sql_mode=''");

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
const BASE = 'http://127.0.0.1:8099';
/** @return array{0:int,1:array,2:mixed,3:string} status, headers(lowercase), json, raw */
function http(string $method, string $path, array $headers = [], ?string $body = null): array
{
    $ch = curl_init(BASE . $path);
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
function tokenReq(string $cid, string $secret, array $extra = []): array
{
    return http('POST', '/api/v1/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'],
        http_build_query($extra + ['grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $secret]));
}
function tokenFor(array $client, array $extra = []): string
{
    [$s, , $j] = tokenReq($client['client_uid'], $client['secret'], $extra);
    if ($s !== 200) {
        throw new RuntimeException("token request failed ($s)");
    }
    return $j['access_token'];
}
function get(string $path, ?string $tok, array $h = []): array
{
    return http('GET', $path, $h + ($tok ? ['Authorization' => "Bearer $tok"] : []));
}
function q(mysqli $db, string $sql): array
{
    $r = $db->query($sql);
    return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
}
function code(array $r): ?string
{
    return $r[2]['error']['code'] ?? null;
}
/** @return list<string> the ids of a list response, in order */
function ids(array $r): array
{
    return array_map(static fn($e) => (string) $e['id'], $r[2]['data'] ?? []);
}
/** @return array an error body without its per-request correlation id */
function errShape(array $r): array
{
    $j = $r[2];
    unset($j['error']['correlation_id']);
    return [$r[0], $j];
}

// ---------------------------------------------------------------- server
$cmd = sprintf('php -S 127.0.0.1:8099 -t %s %s > /dev/null 2>&1 & echo $!', escapeshellarg($ROOT), escapeshellarg($ROOT . '/tests/lms_router.php'));
$serverPid = (int) trim((string) shell_exec($cmd));
register_shutdown_function(static function () use ($serverPid, $RATE) {
    if ($serverPid > 0) {
        posix_kill($serverPid, 15);
    }
    if ($RATE) {
        shell_exec('redis-cli -p 6380 -n 15 flushdb > /dev/null 2>&1');
    }
});
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', 8099, $en, $es, 0.2);
    if ($fp) {
        fclose($fp);
        break;
    }
    usleep(100000);
}

// ---------------------------------------------------------------- fixtures
$db->query("UPDATE settings SET config_module_enable_training = 1, config_timezone = 'America/Chicago' WHERE company_id = 1");
function mkDept(mysqli $db, string $name, array $o = []): int
{
    $db->query("INSERT INTO clients SET client_name = '" . $db->real_escape_string($name) . "', client_created_at = '2026-01-01 08:00:00', client_updated_at = '2026-01-01 08:00:00',
        client_status = '" . ($o['status'] ?? 'Active') . "', client_lead = " . (int) ($o['lead'] ?? 0)
        . (isset($o['parent']) ? ', client_parent_id = ' . (int) $o['parent'] : '')
        . (!empty($o['archived']) ? ", client_archived_at = '2026-02-01 08:00:00'" : ''));
    return $db->insert_id;
}
function mkEmp(mysqli $db, string $name, int $dept, array $o = []): int
{
    $db->query("INSERT INTO contacts SET contact_name = '" . $db->real_escape_string($name) . "', contact_client_id = $dept,
        contact_employment_status = '" . ($o['status'] ?? 'active') . "', contact_created_at = '2026-01-01 08:00:00', contact_updated_at = '2026-01-01 08:00:00',
        contact_email = " . (isset($o['email']) ? "'" . $o['email'] . "'" : 'NULL') . ', contact_title = ' . (isset($o['title']) ? "'" . $o['title'] . "'" : 'NULL')
        . ", contact_pin = 'PINSECRET-" . $dept . "', contact_phone = '555-0100', contact_mobile = '555-0199', contact_notes = 'NOTESECRET'"
        . ', contact_vendor_id = ' . (int) ($o['vendor'] ?? 0) . ", contact_employee_type = '" . ($o['type'] ?? 'employee') . "'"
        . (isset($o['manager']) ? ', contact_manager_id = ' . (int) $o['manager'] : '')
        . (!empty($o['archived']) ? ", contact_archived_at = '2026-03-01 08:00:00'" : ''));
    return $db->insert_id;
}
$dA = mkDept($db, 'Assembly');
$dB = mkDept($db, 'Quality Lab');
$dC = mkDept($db, 'Shipping');
$dArch = mkDept($db, 'Old Dept', ['archived' => true]);
$dLead = mkDept($db, 'A CRM Lead', ['lead' => true]);
$dInactive = mkDept($db, 'Dormant', ['status' => 'Inactive']);
$dChild = mkDept($db, 'Assembly Line 2', ['parent' => $dA]);
$dChildB = mkDept($db, 'Lab Annex', ['parent' => $dB]);
$db->query("INSERT INTO client_odoo_links SET client_id = $dA, odoo_integration_id = 1, odoo_department_id = 901");
$db->query("INSERT INTO client_odoo_links SET client_id = $dB, odoo_integration_id = 1, odoo_department_id = 902");

$eA2 = mkEmp($db, 'Ann Head', $dA, ['email' => 'ann@example.test', 'title' => 'Line Lead']);
$eA1 = mkEmp($db, 'Al Assembler', $dA, ['email' => 'al@example.test', 'title' => 'Technician', 'manager' => $eA2]);
$eA3 = mkEmp($db, 'Tom Terminated', $dA, ['status' => 'terminated']);
$eA4 = mkEmp($db, 'Ava Archived', $dA, ['archived' => true]);
$eA5 = mkEmp($db, 'Pat Prehire', $dA, ['status' => 'pre-hire']);
$eB1 = mkEmp($db, 'Bea Lab', $dB, ['email' => 'bea@example.test', 'manager' => $eA2]);   // manager in another department
$eB2 = mkEmp($db, 'Bo Lab', $dB, ['manager' => $eB1]);
$eC1 = mkEmp($db, 'Cy Shipper', $dC);
$eN1 = mkEmp($db, 'Nina NoDept', 0);
$eV1 = mkEmp($db, 'Vic Vendor', $dA, ['vendor' => 5]);
$eV2 = mkEmp($db, 'Vee VendorType', $dA, ['type' => 'vendor']);
$db->query("UPDATE clients SET client_head_contact_id = $eA2 WHERE client_id = $dA");
$db->query("UPDATE clients SET client_head_contact_id = $eA1 WHERE client_id = $dB");    // head in another department
foreach ([$eA1 => 1001, $eA2 => 1002, $eA3 => 1003, $eB1 => 2001, $eB2 => 2002, $eC1 => 3001, $eN1 => 4001] as $cid => $odoo) {
    $db->query("INSERT INTO contact_odoo_links SET contact_id = $cid, odoo_integration_id = 1, odoo_employee_id = $odoo");
}
$db->query("INSERT INTO contact_odoo_links SET contact_id = $eA1, odoo_integration_id = 2, odoo_employee_id = 9001");   // second integration: the lowest id is returned

$mk = fn(string $name, array $scopes, bool $all, array $depts = []) => IntegrationAuth::createClient($db, $name, null, $scopes, '', null, 1, $all, $depts);
$cAll = $mk('Dir all', ['directory:read'], true);
$cA = $mk('Dir A', ['directory:read'], false, [$dA]);
$cAB = $mk('Dir A+B', ['directory:read'], false, [$dA, $dB]);
$cNone = $mk('Dir none', ['directory:read'], false, []);
$cLms = $mk('LMS only', ['lms:read', 'lms:assign', 'lms:completions:read', 'lms:evidence:read', 'quality:events'], true);
$cBoth = $mk('LMS + dir', ['lms:read', 'directory:read'], true);
$tAll = tokenFor($cAll);
$tA = tokenFor($cA);
$tAB = tokenFor($cAB);
$tNone = tokenFor($cNone);
$tLms = tokenFor($cLms);
$tBoth = tokenFor($cBoth);

// ================================================================= A. auth and scope
section('A. authentication and scope');
foreach (['/api/v1/employees', '/api/v1/employees/' . $eA1, '/api/v1/departments', '/api/v1/departments/' . $dA] as $p) {
    [$s0, $h0] = get($p, null);
    [$s1] = get($p, 'deadbeefdeadbeefdeadbeefdeadbeef');
    [$s2] = http('GET', $p, ['X-Api-Key' => 'legacy-' . bin2hex(random_bytes(8))]);
    ok($s0 === 401 && $s1 === 401 && $s2 === 401 && isset($h0['www-authenticate']), "GET $p: no token, a user-style Bearer value and a legacy key are all 401");
    [$s3, , $j3] = get($p, $tLms);
    ok($s3 === 403 && ($j3['error']['code'] ?? '') === 'insufficient_scope', "GET $p with every lms scope and quality:events but not directory:read: 403 insufficient_scope");
    [$s4] = get($p, $tAll);
    ok($s4 === 200, "GET $p with directory:read: 200");
}
[$s, , $j] = get('/api/v1/employees', $tBoth);
ok($s === 200, 'a client holding lms:read and directory:read can read the directory');
[$s] = get('/api/v1/lms/courses', $tAll);
ok($s === 403, 'directory:read does not grant /lms (lms:read needed): 403');
[$s] = get('/api/v1/tickets', $tAll);
[$s2] = get('/api/v1/contacts', $tAll);
[$s3] = get('/api/v1/clients', $tAll);
ok($s === 403 && $s2 === 403 && $s3 === 403, 'a directory token on /tickets, /contacts and /clients stays 403 (integration tokens never act as users)');
[$s, , $j] = tokenReq($cLms['client_uid'], $cLms['secret'], ['scope' => 'directory:read']);
ok($s === 400 && ($j['error']['code'] ?? '') === 'invalid_scope', 'a client without directory:read cannot ask for it at the token endpoint (400 invalid_scope)');
[$s, , $j] = tokenReq($cAll['client_uid'], $cAll['secret'], ['scope' => 'lms:read']);
ok($s === 400, 'a directory-only client cannot ask for lms:read (400)');
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
    [$s, $h] = http($m, '/api/v1/employees', ['Authorization' => "Bearer $tAll", 'Content-Type' => 'application/json'], '{}');
    [$s2] = http($m, '/api/v1/departments/' . $dA, ['Authorization' => "Bearer $tAll", 'Content-Type' => 'application/json'], '{}');
    ok($s === 405 && $s2 === 405 && ($h['allow'] ?? '') === 'GET', "$m on the directory is 405 with Allow: GET");
}
// removing the scope from the client bites an already-issued token at the next request
$cDrop = $mk('Dir dropped', ['directory:read'], true);
$tDrop = tokenFor($cDrop);
[$s1] = get('/api/v1/employees', $tDrop);
$db->query("UPDATE integration_clients SET client_scopes = 'lms:read' WHERE client_id = " . (int) $cDrop['client_id']);
[$s2, , $j2] = get('/api/v1/employees', $tDrop);
ok($s1 === 200 && $s2 === 403 && ($j2['error']['code'] ?? '') === 'insufficient_scope', 'narrowing the client scopes takes effect on a token already issued');
$db->query("UPDATE integration_clients SET client_scopes = 'directory:read' WHERE client_id = " . (int) $cDrop['client_id']);
[$s1] = get('/api/v1/employees', $tDrop);
IntegrationAuth::revokeClient($db, (int) $cDrop['client_id']);
[$s2] = get('/api/v1/employees', $tDrop);
ok($s1 === 200 && $s2 === 401, 'a revoked client is 401 at the next request');
[$s, $h, $j] = get('/api/v1/employees', $tAll, ['X-Correlation-Id' => 'corr-dir-1']);
ok($h['x-correlation-id'] === 'corr-dir-1' && ($h['cache-control'] ?? '') === 'no-store' && str_starts_with($h['content-type'] ?? '', 'application/json'), 'X-Correlation-Id echoed; Cache-Control no-store; JSON');

// ================================================================= B. shapes, minimum fields, tombstones
section('B. employee and department shapes');
$all = get('/api/v1/employees?limit=200', $tAll);
$byId = [];
foreach ($all[2]['data'] as $e) {
    $byId[$e['id']] = $e;
}
$expectedKeys = ['active', 'department_id', 'employment_status', 'id', 'job_title', 'manager_id', 'name', 'odoo_department_id', 'odoo_employee_id', 'updated_at', 'work_email'];
$keysOk = true;
foreach ($all[2]['data'] as $e) {
    $k = array_keys($e);
    sort($k);
    $keysOk = $keysOk && $k === $expectedKeys;
}
ok($keysOk, 'every employee has exactly the minimum fields');
$rawAll = $all[3];
ok(!str_contains($rawAll, 'PINSECRET') && !str_contains($rawAll, 'NOTESECRET') && !str_contains($rawAll, '555-01'), 'no PIN, phone or notes anywhere in the employee payload');
$a1 = $byId[(string) $eA1] ?? [];
ok(($a1['name'] ?? '') === 'Al Assembler' && ($a1['work_email'] ?? '') === 'al@example.test' && ($a1['job_title'] ?? '') === 'Technician', 'name, work email and job title');
ok(($a1['odoo_employee_id'] ?? '') === '1001' && ($a1['department_id'] ?? '') === (string) $dA && ($a1['odoo_department_id'] ?? '') === '901', 'Odoo employee id (the lowest of several links), department id and Odoo department id are strings');
ok(($a1['manager_id'] ?? '') === (string) $eA2 && ($a1['employment_status'] ?? '') === 'active' && ($a1['active'] ?? null) === true, 'manager id, employment status, active');
ok(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $a1['updated_at'] ?? '') === 1, 'updated_at is ISO 8601 UTC with milliseconds');
ok($byId[(string) $eA3]['active'] === false && $byId[(string) $eA3]['employment_status'] === 'terminated', 'a terminated employee is in the feed as a tombstone (active false)');
ok(isset($byId[(string) $eA4]) && $byId[(string) $eA4]['active'] === false, 'an archived employee is in the feed as a tombstone (active false)');
ok(($byId[(string) $eA5]['active'] ?? null) === true && $byId[(string) $eA5]['employment_status'] === 'pre-hire', 'a pre-hire employee is active with its employment_status');
ok(!isset($byId[(string) $eV1]) && !isset($byId[(string) $eV2]), 'vendor contacts are not employees');
$isNull = fn(array $e, string $k) => array_key_exists($k, $e) && $e[$k] === null;
ok($isNull($byId[(string) $eN1], 'department_id') && $isNull($byId[(string) $eN1], 'odoo_department_id'), 'a person with no department: department ids null');
ok($isNull($byId[(string) $eC1], 'work_email') && $isNull($byId[(string) $eC1], 'job_title') && $isNull($byId[(string) $eA5], 'odoo_employee_id'), 'missing email, title and Odoo link are null, not empty strings');
$sorted = ids($all);
$asc = $sorted;
usort($asc, fn($x, $y) => (int) $x <=> (int) $y);
ok($sorted === $asc && count($sorted) === 9, 'ascending id order, 9 employees (vendors excluded)');

$deps = get('/api/v1/departments?limit=200', $tAll);
$dById = [];
foreach ($deps[2]['data'] as $d) {
    $dById[$d['id']] = $d;
}
$dKeys = ['active', 'head_employee_id', 'id', 'name', 'odoo_department_id', 'parent_id', 'status', 'updated_at'];
$dKeysOk = true;
foreach ($deps[2]['data'] as $d) {
    $k = array_keys($d);
    sort($k);
    $dKeysOk = $dKeysOk && $k === $dKeys;
}
ok($dKeysOk, 'every department has exactly the minimum fields');
ok(!isset($dById[(string) $dLead]), 'a CRM lead is not a department');
ok(($dById[(string) $dA]['name'] ?? '') === 'Assembly' && ($dById[(string) $dA]['odoo_department_id'] ?? '') === '901' && ($dById[(string) $dA]['head_employee_id'] ?? '') === (string) $eA2
    && ($dById[(string) $dA]['status'] ?? '') === 'active' && ($dById[(string) $dA]['active'] ?? null) === true, 'department fields');
ok(($dById[(string) $dChild]['parent_id'] ?? '') === (string) $dA, 'parent id');
ok(isset($dById[(string) $dArch]) && $dById[(string) $dArch]['status'] === 'archived' && $dById[(string) $dArch]['active'] === false, 'an archived department is a tombstone');
ok(($dById[(string) $dInactive]['status'] ?? '') === 'inactive' && $dById[(string) $dInactive]['active'] === false, 'an inactive department is active false');
ok(array_keys($dById) === array_values(array_unique(array_keys($dById))) && count($dById) === 7, '7 departments (the lead is excluded)');

[$s, , $j] = get('/api/v1/employees/' . $eA1, $tAll);
ok($s === 200 && $j['data'] === $a1, 'GET /employees/{id} returns the same object as the list');
[$s, , $j] = get('/api/v1/employees/' . $eA3, $tAll);
ok($s === 200 && $j['data']['active'] === false, 'a tombstone can be fetched by id');
[$s, , $j] = get('/api/v1/departments/' . $dA, $tAll);
ok($s === 200 && $j['data'] === $dById[(string) $dA], 'GET /departments/{id} returns the same object as the list');
[$s] = get('/api/v1/employees/' . $eV1, $tAll);
ok($s === 404, 'a vendor contact by id: 404');
[$s] = get('/api/v1/departments/' . $dLead, $tAll);
ok($s === 404, 'a CRM lead by id: 404');
$e404 = [];
foreach (['/employees/999999', '/employees/abc', '/employees/0', '/employees/01', '/employees/1.5', '/employees/-1', '/employees/1%20OR%201=1', '/employees/' . $eA1 . '/extra', '/employees/99999999999',
          '/departments/999999', '/departments/abc', '/departments/0', "/departments/1'", '/departments/' . $dA . '/extra'] as $p) {
    [$s, , $j] = get('/api/v1' . $p, $tAll);
    if ($s !== 404 || ($j['error']['code'] ?? '') !== 'not_found') {
        $e404[] = "$p -> $s";
    }
}
[$s] = get('/api/v1/nothing', $tAll);
ok($s === 403, 'an unknown route with a directory token is 403 (integration tokens only reach the three routes)');
ok($e404 === [], 'unknown, malformed and injected ids are all 404 not_found', implode('; ', $e404));

// ================================================================= C. department scope
section('C. department scope');
$ra = get('/api/v1/employees?limit=200', $tA);
ok(ids($ra) === array_map('strval', [$eA2, $eA1, $eA3, $eA4, $eA5]) || (function () use ($ra, $eA1, $eA2, $eA3, $eA4, $eA5) {
    $x = ids($ra);
    sort($x);
    $y = array_map('strval', [$eA1, $eA2, $eA3, $eA4, $eA5]);
    sort($y);
    return $x === $y;
})(), 'a client limited to Assembly sees exactly its employees (tombstones included)');
ok(!str_contains($ra[3], 'Bea Lab') && !str_contains($ra[3], 'Cy Shipper') && !str_contains($ra[3], 'Nina') && !str_contains($ra[3], 'bea@example.test'), 'no other department\'s person in the payload');
$rda = get('/api/v1/departments?limit=200', $tA);
$idsA = ids($rda);
sort($idsA);
$want = array_map('strval', [$dA, $dChild]);
sort($want);
ok(count($idsA) === 1 && $idsA === [(string) $dA], 'a client limited to Assembly sees only that department (not its child)', implode(',', $idsA));
ok(!str_contains($rda[3], 'Quality Lab') && !str_contains($rda[3], 'Shipping'), 'no other department name in the payload');
[$sOut, , $jOut] = get('/api/v1/employees/' . $eB1, $tA);
[$sUnk, , $jUnk] = get('/api/v1/employees/987654', $tA);
ok($sOut === 404 && errShape(get('/api/v1/employees/' . $eB1, $tA)) === errShape(get('/api/v1/employees/987654', $tA)), 'an out-of-scope employee is 404 with the same body as an unknown one (not 403)');
ok(errShape(get('/api/v1/departments/' . $dB, $tA)) === errShape(get('/api/v1/departments/987654', $tA)) && get('/api/v1/departments/' . $dB, $tA)[0] === 404, 'an out-of-scope department is 404 with the same body as an unknown one (not 403)');
[$s] = get('/api/v1/employees/' . $eN1, $tA);
ok($s === 404, 'a person with no department is invisible to a department-limited client');
[$s, , $j] = get('/api/v1/employees/' . $eN1, $tAll);
ok($s === 200, '... and visible to an all-departments client');
$uOut = get('/api/v1/employees?department_id=' . $dB, $tA);
ok($uOut[0] === 200 && $uOut[2]['data'] === [] && $uOut[2]['next_cursor'] === null, 'filtering by an out-of-scope department gives an empty list, not an error');
$uIn = get('/api/v1/employees?department_id=' . $dA . '&limit=200', $tAll);
ok(count($uIn[2]['data']) === 5, 'department_id filter (all-departments client): the 5 employees of Assembly');
$oo = get('/api/v1/employees?odoo_employee_id=2001', $tA);
$oo2 = get('/api/v1/employees?odoo_employee_id=1001', $tA);
ok($oo[2]['data'] === [] && count($oo2[2]['data']) === 1 && $oo2[2]['data'][0]['id'] === (string) $eA1, 'odoo_employee_id filter obeys the scope');
$oo3 = get('/api/v1/employees?odoo_employee_id=9001', $tA);
ok(count($oo3[2]['data']) === 1, 'odoo_employee_id matches any of the person\'s links');
// reference fields never point out of scope
$rab = get('/api/v1/employees?limit=200', $tAB);
$m = [];
foreach ($rab[2]['data'] as $e) {
    $m[$e['id']] = $e;
}
ok(count($m) === 7 && ($m[(string) $eB1]['manager_id'] ?? 'x') === (string) $eA2, 'two departments: 7 employees; a manager inside the scope is returned');
ok(($byId[(string) $eB1]['manager_id'] ?? '') === (string) $eA2, 'an all-departments client sees the cross-department manager');
$rb = get('/api/v1/employees?limit=200', $mk('B only', ['directory:read'], false, [$dB]) ? tokenFor($mk('Dir B', ['directory:read'], false, [$dB])) : '');
$bm = [];
foreach ($rb[2]['data'] as $e) {
    $bm[$e['id']] = $e;
}
ok(array_key_exists((string) $eB1, $bm) && $bm[(string) $eB1]['manager_id'] === null && $bm[(string) $eB2]['manager_id'] === (string) $eB1, 'a manager outside the scope is null, never disclosed; one inside is returned');
$tB = tokenFor($mk('Dir B2', ['directory:read'], false, [$dB]));
$rdb = get('/api/v1/departments/' . $dB, $tB);
ok($rdb[2]['data']['head_employee_id'] === null && $rdb[2]['data']['parent_id'] === null, 'a department head outside the scope is null');
$rdc = get('/api/v1/departments/' . $dChildB, tokenFor($mk('Dir Annex', ['directory:read'], false, [$dChildB])));
ok($rdc[2]['data']['parent_id'] === null, 'a parent department outside the scope is null');
$rdc2 = get('/api/v1/departments/' . $dChildB, $tAll);
ok($rdc2[2]['data']['parent_id'] === (string) $dB, '... and visible to an all-departments client');
$rdab = get('/api/v1/departments/' . $dChild, tokenFor($mk('Dir A+child', ['directory:read'], false, [$dA, $dChild])));
ok($rdab[2]['data']['parent_id'] === (string) $dA, 'a parent inside the scope is returned');

// fail closed
$rn = get('/api/v1/employees', $tNone);
$rnd = get('/api/v1/departments', $tNone);
ok($rn[0] === 200 && $rn[2]['data'] === [] && $rnd[2]['data'] === [], 'a restricted client with no departments sees nothing');
ok(get('/api/v1/employees/' . $eA1, $tNone)[0] === 404 && get('/api/v1/departments/' . $dA, $tNone)[0] === 404, '... and every id is 404');
$cWeird = $mk('Weird flag', ['directory:read'], false, [$dA]);
$tWeird = tokenFor($cWeird);
$db->query("UPDATE integration_clients SET client_all_departments = 2 WHERE client_id = " . (int) $cWeird['client_id']);
$rw = get('/api/v1/employees', $tWeird);
ok($rw[0] === 200 && count($rw[2]['data']) === 5, 'an unexpected all-departments value (2) is treated as restricted, not as all');
$db->query("UPDATE integration_clients SET client_all_departments = 0 WHERE client_id = " . (int) $cWeird['client_id']);
$db->query("DELETE FROM integration_client_departments WHERE client_id = " . (int) $cWeird['client_id']);
$db->query("INSERT INTO integration_client_departments SET client_id = " . (int) $cWeird['client_id'] . ", department_id = 99999");
ok(get('/api/v1/employees', $tWeird)[2]['data'] === [] && get('/api/v1/departments', $tWeird)[2]['data'] === [], 'a scope row naming a department that does not exist grants nothing');
$db->query("DELETE FROM integration_client_departments WHERE client_id = " . (int) $cWeird['client_id']);
ok(get('/api/v1/employees', $tWeird)[2]['data'] === [], 'removing the scope rows empties the feed at the next request');
$db->query("INSERT INTO integration_client_departments SET client_id = " . (int) $cWeird['client_id'] . ", department_id = $dC");
$rc = get('/api/v1/employees', $tWeird);
ok(ids($rc) === [(string) $eC1], 'changing the scope applies to a token already issued');
// SQL-shaped input
foreach (['department_id=1%20OR%201%3D1', 'department_id=1;DROP', 'department_id=0', 'department_id[]=1', 'odoo_employee_id=1%27', 'odoo_employee_id=abc', 'odoo_employee_id[]=1'] as $qs) {
    [$s, , $j] = get('/api/v1/employees?' . $qs, $tAll);
    if (!($s === 400 && in_array($j['error']['code'] ?? '', ['invalid_department_id', 'invalid_odoo_employee_id'], true))) {
        ok(false, "bad filter $qs is refused", "got $s");
    }
}
ok(true, 'malformed department_id and odoo_employee_id filters are 400');
ok(count(q($db, 'SELECT 1 FROM contacts')) === 11, 'the injected input did not touch the data');

// ================================================================= D. paging
section('D. cursor paging and ordering');
$seen = [];
$cursor = null;
$pages = 0;
do {
    [$s, , $j] = get('/api/v1/employees?limit=2' . ($cursor ? '&cursor=' . urlencode($cursor) : ''), $tAll);
    $seen = array_merge($seen, array_column($j['data'], 'id'));
    $cursor = $j['next_cursor'];
    $pages++;
} while ($cursor && $pages < 20);
ok($pages === 5 && $seen === $sorted, 'walking limit=2 pages returns every employee once, in the same order', "pages $pages");
[$s, , $j] = get('/api/v1/employees?limit=9', $tAll);
ok(count($j['data']) === 9 && $j['next_cursor'] === null, 'the last page has next_cursor null');
[$s, , $j] = get('/api/v1/employees?limit=8', $tAll);
ok(count($j['data']) === 8 && $j['next_cursor'] !== null, 'a full page with more behind it has a next_cursor');
$seen = [];
$cursor = null;
do {
    [$s, , $j] = get('/api/v1/departments?limit=3' . ($cursor ? '&cursor=' . urlencode($cursor) : ''), $tAll);
    $seen = array_merge($seen, array_column($j['data'], 'id'));
    $cursor = $j['next_cursor'];
} while ($cursor);
ok($seen === ids($deps) && count($seen) === 7, 'departments page the same way');
$seenA = [];
$cursor = null;
do {
    [$s, , $j] = get('/api/v1/employees?limit=2' . ($cursor ? '&cursor=' . urlencode($cursor) : ''), $tA);
    $seenA = array_merge($seenA, array_column($j['data'], 'id'));
    $cursor = $j['next_cursor'];
} while ($cursor);
ok(count($seenA) === 5 && count(array_unique($seenA)) === 5, 'paging a department-limited client never leaks or repeats');
// a cursor from one client is just an id position: it cannot widen another client's scope
[, , $jAll2] = get('/api/v1/employees?limit=1', $tAll);
[$s, , $j] = get('/api/v1/employees?limit=200&cursor=' . urlencode($jAll2['next_cursor']), $tA);
ok($s === 200 && count(array_diff(ids([0, 0, $j]), array_map('strval', [$eA1, $eA2, $eA3, $eA4, $eA5]))) === 0, 'a cursor taken from an all-departments client does not widen another client');
foreach (['limit=0', 'limit=201x', 'limit=-1', 'limit=abc', 'limit[]=1', 'cursor=!!!', 'cursor=' . rtrim(strtr(base64_encode('{"after":"x"}'), '+/', '-_'), '='), 'cursor=' . rtrim(strtr(base64_encode('{"after":-1}'), '+/', '-_'), '='), 'cursor[]=1'] as $qs) {
    [$s, , $j] = get('/api/v1/employees?' . $qs, $tAll);
    $c = $j['error']['code'] ?? '';
    if (!($s === 400 && in_array($c, ['invalid_limit', 'invalid_cursor'], true))) {
        ok(false, "bad paging input $qs", "got $s $c");
    }
}
ok(true, 'bad limit and cursor values are 400 invalid_limit / invalid_cursor');
[$s, , $j] = get('/api/v1/employees?limit=1000', $tAll);
ok($s === 200 && count($j['data']) === 9, 'a limit above the maximum is clamped to 200, like the LMS endpoints');

// ================================================================= E. updated_since and tombstones over time
section('E. updated_since');
$db->query("UPDATE contacts SET contact_updated_at = '2026-01-01 08:00:00', contact_created_at = '2026-01-01 08:00:00'");
$db->query("UPDATE clients SET client_updated_at = '2026-01-01 08:00:00', client_created_at = '2026-01-01 08:00:00'");
$db->query("UPDATE contact_odoo_links SET updated_at = '2026-01-01 08:00:00'");
$db->query("UPDATE client_odoo_links SET updated_at = '2026-01-01 08:00:00'");
$db->query("UPDATE contacts SET contact_archived_at = '2026-01-02 08:00:00', contact_updated_at = '2026-01-01 08:00:00' WHERE contact_id = $eA4");
$db->query("UPDATE clients SET client_archived_at = '2026-01-02 08:00:00', client_updated_at = '2026-01-01 08:00:00' WHERE client_id = $dArch");
$db->query("UPDATE contacts SET contact_updated_at = '2026-10-10 12:00:00' WHERE contact_id = $eA1");   // Chicago CDT: 17:00:00Z
$db->query("UPDATE clients SET client_updated_at = '2026-10-11 12:00:00' WHERE client_id = $dB");
$u = fn(string $path, string $since, ?string $tok = null) => get($path . (str_contains($path, '?') ? '&' : '?') . 'updated_since=' . urlencode($since), $tok ?? $tAll);
[$s, , $j] = get('/api/v1/employees/' . $eA1, $tAll);
ok($j['data']['updated_at'] === '2026-10-10T17:00:00.000Z', 'updated_at converts the company-local time to UTC (Chicago CDT)', $j['data']['updated_at']);
ok(ids($u('/api/v1/employees', '2026-10-01T00:00:00Z')) === [(string) $eA1], 'updated_since returns only what changed');
ok(ids($u('/api/v1/employees', '2026-10-10T17:00:00Z')) === [(string) $eA1], 'updated_since is inclusive (>=)');
ok(ids($u('/api/v1/employees', '2026-10-10T17:00:01Z')) === [], 'one second later: nothing');
ok(ids($u('/api/v1/employees', '2026-10-10T12:00:00-05:00')) === [(string) $eA1], 'an offset timestamp is honoured');
ok(ids($u('/api/v1/employees', '2026-10-10T17:00:00.000Z')) === [(string) $eA1], 'RFC 3339 with fractional seconds is accepted');
ok(count(ids($u('/api/v1/employees', '2025-01-01T00:00:00Z'))) === 9, 'a very old timestamp returns everyone');
$arch = ids($u('/api/v1/employees', '2026-01-02T00:00:00Z'));
ok(in_array((string) $eA4, $arch, true) && count($arch) === 2, 'an archive time counts as an update: the tombstone appears in the incremental feed', implode(',', $arch));
$db->query("UPDATE contact_odoo_links SET updated_at = '2026-10-12 09:00:00' WHERE contact_id = $eC1");
ok(ids($u('/api/v1/employees', '2026-10-12T00:00:00Z')) === [(string) $eC1], 'a change to the Odoo link counts as an update');
$dp = ids($u('/api/v1/departments', '2026-10-01T00:00:00Z'));
ok($dp === [(string) $dB], 'departments: updated_since returns only what changed');
$dp2 = ids($u('/api/v1/departments', '2026-01-02T00:00:00Z'));
ok(in_array((string) $dArch, $dp2, true), 'an archived department is in the incremental feed');
$limited = ids($u('/api/v1/employees', '2026-01-02T00:00:00Z', $tA));
ok(sort($limited) !== null && $limited === array_values(array_intersect([(string) $eA1, (string) $eA4], $limited)) && count($limited) === 2, 'updated_since obeys the department scope');
ok(ids($u('/api/v1/departments', '2025-01-01T00:00:00Z', $tA)) === [(string) $dA], 'department updated_since obeys the department scope');
$pg = get('/api/v1/employees?limit=1&updated_since=' . urlencode('2025-01-01T00:00:00Z'), $tAll);
ok(count($pg[2]['data']) === 1 && $pg[2]['next_cursor'] !== null, 'updated_since combines with paging');
foreach (['yesterday', '2026-10-01', '2026-10-01 00:00:00', '2026-13-45T00:00:00Z', '1', '', 'null', '2026-10-01T00:00:00', '2026-02-31T00:00:00Z'] as $bad) {
    [$s, , $j] = get('/api/v1/employees?updated_since=' . urlencode($bad), $tAll);
    if (!($s === 400 && ($j['error']['code'] ?? '') === 'invalid_updated_since')) {
        ok(false, "updated_since=$bad refused", "got $s");
    }
}
ok(true, 'an invalid updated_since is 400 invalid_updated_since');
[$s] = get('/api/v1/employees?updated_since[]=2026-01-01T00:00:00Z', $tAll);
ok($s === 400, 'updated_since as an array is 400');

// live transitions
$db->query("UPDATE contacts SET contact_employment_status = 'terminated', contact_updated_at = '2026-10-20 10:00:00' WHERE contact_id = $eB2");
[$s, , $j] = get('/api/v1/employees/' . $eB2, $tAB);
ok($j['data']['active'] === false && $j['data']['employment_status'] === 'terminated', 'terminating a person flips active to false for the next read');
ok(ids($u('/api/v1/employees', '2026-10-20T00:00:00Z', $tAB)) === [(string) $eB2], '... and the person is in the incremental feed');
$db->query("UPDATE contacts SET contact_client_id = $dC, contact_updated_at = '2026-10-21 10:00:00' WHERE contact_id = $eB2");
[$s] = get('/api/v1/employees/' . $eB2, $tAB);
ok($s === 404, 'a person moved out of the scope disappears (404) for that client');
[$s] = get('/api/v1/employees/' . $eB2, $tAll);
ok($s === 200, '... and is still present for an all-departments client');

// ================================================================= F. rate limit and audit
section('F. rate limit and audit');
$aud = q($db, "SELECT COUNT(*) n FROM logs WHERE log_type = 'Integration Token' AND log_action = 'Rejected'")[0]['n'];
$forged = IntegrationAuth::sign(['iss' => IntegrationAuth::issuer(), 'aud' => IntegrationAuth::audience(), 'sub' => $cAll['client_uid'], 'scope' => 'directory:read', 'jti' => bin2hex(random_bytes(16)), 'exp' => time() + 300], 'not-the-signing-key');
get('/api/v1/employees', $forged);
$aud2 = q($db, "SELECT COUNT(*) n FROM logs WHERE log_type = 'Integration Token' AND log_action = 'Rejected'")[0]['n'];
ok((int) $aud2 === (int) $aud + 1, 'a rejected directory token is audited like a rejected LMS token');
[$s, , $j] = get('/api/v1/employees', $forged);
ok($s === 401 && ($j['error']['code'] ?? '') === 'invalid_token', 'a forged JWT-shaped token on the directory: 401 invalid_token');
if ($RATE) {
    $got429 = null;
    $tRate = tokenFor($mk('Dir rate', ['directory:read'], true));     // a fresh client: its own bucket starts empty
    for ($i = 0; $i < 640; $i++) {
        [$s, $h] = get('/api/v1/departments/' . $dA, $tRate);
        if ($s === 429) {
            $got429 = [$i, $h];
            break;
        }
    }
    ok($got429 !== null && $got429[0] >= 590 && $got429[0] <= 605 && isset($got429[1]['retry-after']), 'the 600 per minute per-client limit applies to the directory (429 + Retry-After)', $got429 ? "after {$got429[0]}" : 'never');
    [$s] = get('/api/v1/departments/' . $dA, $tA);
    ok($s === 200, 'the limit is per client: another client is unaffected');
} else {
    echo "  skip rate-limit check (run with DIRTEST_RATELIMIT=1; Redis guards are off in this mode)\n";
}

echo "\n" . $PASS . " passed, " . count($FAIL) . " failed\n";
if ($FAIL) {
    echo "FAILED:\n - " . implode("\n - ", $FAIL) . "\n";
    exit(1);
}
