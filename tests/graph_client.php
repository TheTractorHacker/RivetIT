<?php
/*
 * Microsoft Graph client + Intune sync against a LOCAL mock (tests/mock/graph_mock.php). Never contacts Microsoft.
 * Needs a schema-only scratch database (db.sql imported):
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/graph_client.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';

use ITFlow\Integrations\Microsoft\GraphClient;
use ITFlow\Integrations\Microsoft\GraphException;
use ITFlow\Integrations\Microsoft\InMemoryGraphTokenCache;
use ITFlow\Integrations\Microsoft\IntuneSyncService;
use ITFlow\Integrations\Microsoft\MysqliGraphTokenCache;

function encryptSetting(string $p): string { return $p === '' ? '' : 'ENC2:' . strrev($p); }
function decryptSetting(string $c): string { return str_starts_with($c, 'ENC2:') ? strrev(substr($c, 5)) : $c; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$db->query("SET SESSION sql_mode=''");
$fail = 0;
$ok = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fail++; };

// ---- start the mock on a random localhost port
$port = random_int(20000, 40000);
$state = sys_get_temp_dir() . '/graph_mock_' . getmypid() . '.json';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/mock/graph_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MOCK_STATE' => $state] + $_ENV + ['PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($proc, $state) { proc_terminate($proc); foreach ([$state, "$state.log", "$state.lock"] as $f) @unlink($f); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
$base = "http://127.0.0.1:$port";
$mock = function (array $s) use ($base) { $c = stream_context_create(['http' => ['method' => 'POST', 'content' => json_encode($s), 'header' => 'Content-Type: application/json']]); return json_decode(file_get_contents("$base/__state", false, $c), true); };
$log = fn () => json_decode(file_get_contents("$base/__log"), true);
$reset = fn () => $mock(['__reset' => 1]);
$sleeps = [];
$mk = function (string $tenant = 'ok-tenant', array $opts = [], $cache = null, ?string $graph = null) use ($base, &$sleeps) {
    return new GraphClient($tenant, 'client-1', 'secret-1', $cache, $graph ?? "$base/v1.0", $base, $opts + ['sleep' => function (float $s) use (&$sleeps) { $sleeps[] = $s; }]);
};
$expect = function (callable $fn, string $code) {
    try { $fn(); } catch (GraphException $e) { return $e->errorCode === $code ? $e : null; }
    return null;
};

// ---- token reuse
$reset();
$c = $mk();
$c->getUsers(); $c->getUsers();
$ok($c->stats()['token_fetches'] === 1, 'token reuse: two full user listings fetched ONE token');
$ok($c->stats()['pages'] === 6, 'pagination: 250 users = 3 pages per listing');

// ---- shared cache across client instances (DB, encrypted, bound to tenant+client)
$reset();
$db->query("DELETE FROM microsoft_integrations");
$db->query("INSERT INTO microsoft_integrations SET tenant_id='ok-tenant', client_id='client-1', client_secret_enc='" . encryptSetting('secret-1') . "', enabled=1, intune_sync_enabled=1");
$iid = $db->insert_id;
$cacheA = new MysqliGraphTokenCache($db, $iid, 'ok-tenant', 'client-1');
$a = $mk('ok-tenant', [], $cacheA); $a->getUsers();
$row = $db->query("SELECT token_cache_enc, token_expires_at FROM microsoft_integrations WHERE microsoft_integration_id=$iid")->fetch_assoc();
$ok(str_starts_with((string) $row['token_cache_enc'], 'ENC2:') && !empty($row['token_expires_at']), 'token persisted encrypted with an expiry');
$b = $mk('ok-tenant', [], new MysqliGraphTokenCache($db, $iid, 'ok-tenant', 'client-1')); $b->getUsers();
$ok($b->stats()['token_fetches'] === 0 && $b->stats()['token_cache_hits'] === 1, 'a second client (like the next cron run) reused the persisted token');
$cc = $mk('ok-tenant', [], new MysqliGraphTokenCache($db, $iid, 'ok-tenant', 'other-client')); $cc->getUsers();
$ok($cc->stats()['token_fetches'] === 1, 'a different client id never reuses the cached token');

// ---- refresh 5 minutes before expiry
$reset();
$clock = 1000000;
$d = $mk('ok-tenant', ['now' => function () use (&$clock) { return $clock; }]);
$d->getUsers();
$clock += 3600 - 400;                     // 400 s left: still outside the 300 s margin
$d->getUsers();
$ok($d->stats()['token_fetches'] === 1, 'token still reused with 400 s left');
$clock += 150;                            // 250 s left: inside the margin
$d->getUsers();
$ok($d->stats()['token_fetches'] === 2, 'token refreshed once fewer than 300 s remain');

// ---- 401 mid-run refreshes the token once
$reset(); $mock(['unauthorized_once' => true]);
$e = $mk(); $users = $e->getUsers();
$ok(count($users) === 250 && $e->stats()['token_fetches'] === 2, '401 -> token dropped, refreshed once, request succeeded');

// ---- 429 / 503 retry
$reset(); $sleeps = []; $mock(['throttle' => 2, 'retry_after' => 3]);
$f = $mk(); $users = $f->getUsers();
$ok(count($users) === 250 && $sleeps === [3.0, 3.0] && $f->stats()['retries'] === 2, '429 twice: honoured Retry-After (3 s, 3 s), then succeeded');
$reset(); $sleeps = []; $mock(['throttle' => 1, 'retry_after' => 999]);
$mk()->getUsers();
$ok($sleeps === [60.0], 'Retry-After is capped (999 -> 60 s)');
$reset(); $sleeps = []; $mock(['throttle' => 3, 'throttle_code' => 503, 'retry_after' => null]);
$mk()->getUsers();
$ok($sleeps === [1.0, 2.0, 4.0], '503 without Retry-After: exponential backoff 1, 2, 4 s');
$reset(); $sleeps = []; $mock(['throttle' => 50, 'retry_after' => 1]);
$ex = $expect(fn () => $mk('ok-tenant', ['max_retries' => 3])->getUsers(), GraphException::THROTTLED);
$ok($ex !== null && count($sleeps) === 3 && $ex->httpStatus === 429, 'retries are capped: after 3 retries -> THROTTLED (HTTP 429)');
$reset(); $sleeps = []; $mock(['throttle' => 5, 'retry_after' => 30]);
$ex = $expect(fn () => $mk('ok-tenant', ['deadline' => time() + 10])->getUsers(), GraphException::THROTTLED);
$ok($ex !== null && $sleeps === [], 'a Retry-After beyond the sync time limit is not waited for');
$reset(); $sleeps = [];
$ok($expect(fn () => $mk('throttletoken', ['max_retries' => 2])->getUsers(), GraphException::THROTTLED) !== null && $sleeps === [1.0, 1.0], 'throttled token endpoint is retried then classified THROTTLED');

// ---- pagination safety
$reset(); $mock(['users' => 250, 'devices' => 150]);
$g = $mk(); $devs = $g->listAllManagedDevices();
$ok(count($devs) === 150 && count(array_unique(array_column($devs, 'id'))) === 150, 'pagination: 150 devices over 2 pages, no duplicates');
$reset(); $mock(['loop' => true]);
$ex = $expect(fn () => $mk()->getUsers(), GraphException::BAD_RESPONSE);
$ok($ex !== null && str_contains($ex->getMessage(), 'loop'), 'a repeated nextLink is detected as a loop');
$reset(); $mock(['foreign_link' => true]);
$ex = $expect(fn () => $mk()->getUsers(), GraphException::BAD_RESPONSE);
$ok($ex !== null && str_contains($ex->getMessage(), 'different host'), 'a nextLink to another host is refused (token never sent there)');
$reset(); $mock(['endless' => true, 'users' => 100000]);
$ex = $expect(fn () => $mk('ok-tenant', ['max_pages' => 5])->getUsers(), GraphException::BAD_RESPONSE);
$ok($ex !== null && str_contains($ex->getMessage(), 'page cap'), 'endless pagination stops at the page cap');

// ---- error classification
$reset();
$ex = $expect(fn () => $mk('badsecret')->getUsers(), GraphException::AUTH_FAILED);
$ok($ex !== null && str_contains($ex->getMessage(), 'Invalid client secret') && !str_contains($ex->getMessage(), 'Trace ID'), 'bad secret -> AUTH_FAILED, readable, no trace ids');
$mock(['forbidden_devices' => true]);
$ex = $expect(fn () => $mk()->listAllManagedDevices(), GraphException::CONSENT_MISSING);
$ok($ex !== null && str_contains($ex->getMessage(), 'Admin consent missing for DeviceManagementManagedDevices.Read.All'), '403 on devices -> CONSENT_MISSING naming DeviceManagementManagedDevices.Read.All');
$mock(['forbidden_users' => true]);
$ex = $expect(fn () => $mk()->getUsers(), GraphException::CONSENT_MISSING);
$ok($ex !== null && str_contains($ex->getMessage(), 'User.Read.All'), '403 on users -> CONSENT_MISSING naming User.Read.All');
$reset(); $mock(['not_licensed' => true]);
$ok($expect(fn () => $mk()->listAllManagedDevices(), GraphException::NOT_APPLICABLE) !== null, 'tenant without Intune -> NOT_APPLICABLE');
$reset(); $mock(['html' => true]);
$ok($expect(fn () => $mk()->listAllManagedDevices(), GraphException::BAD_RESPONSE) !== null, 'non-JSON 200 -> BAD_RESPONSE');
$reset();
$ok($expect(fn () => (new GraphClient('t', 'c', 's', null, 'http://127.0.0.1:1/v1.0', 'http://127.0.0.1:1', ['connect_timeout' => 1]))->getUsers(), GraphException::UNREACHABLE) !== null, 'nothing listening -> UNREACHABLE');
$ok($expect(fn () => $mk('ok-tenant', ['deadline' => time() - 1])->getUsers(), GraphException::TIME_LIMIT) !== null, 'expired deadline -> TIME_LIMIT before any request');
$ok($mk()->getUser('nope') === null && $mk()->getUser('user-0001')?->email === 'one@example.test', 'getUser: 404 -> null, found -> mapped');

// ---- testConnection
$reset();
$r = $mk()->testConnection(['users', 'devices']);
$ok($r->success && $r->details['organization'] === 'Mock Tenant Inc' && in_array('DeviceManagementManagedDevices.Read.All', $r->details['roles'], true) && $r->details['probes'] === ['users' => 'ok', 'devices' => 'ok'], 'testConnection ok: org name, granted roles from the token, both probes ok');
$mock(['forbidden_devices' => true]);
$r = $mk()->testConnection(['users', 'devices']);
$ok(!$r->success && str_contains((string) $r->error, 'DeviceManagementManagedDevices.Read.All') && $r->details['error_code'] === 'consent_missing', 'testConnection names the permission that has no consent');
$r = $mk('badsecret')->testConnection(['users']);
$ok(!$r->success && $r->details['error_code'] === 'auth_failed', 'testConnection: bad secret -> auth_failed');

// ---- IntuneSyncService / IntuneAssetMapper idempotence
$reset();
foreach (['asset_intune_links', 'assets', 'intune_sync_log'] as $t) $db->query("DELETE FROM `$t`");
$rowFor = fn () => $db->query("SELECT * FROM microsoft_integrations WHERE microsoft_integration_id=$iid")->fetch_assoc();
$opts = ['graph_base' => "$base/v1.0", 'authority' => $base, 'sleep' => function () {}];
$r1 = IntuneSyncService::run($db, $rowFor(), 1, 60, $opts);
$assets1 = (int) $db->query("SELECT COUNT(*) c FROM assets")->fetch_assoc()['c'];
$links1 = (int) $db->query("SELECT COUNT(*) c FROM asset_intune_links")->fetch_assoc()['c'];
$ok($r1['ok'] && $r1['stats']['created'] === 150 && $assets1 === 150 && $links1 === 150, 'first sync created 150 assets and 150 links');
$r2 = IntuneSyncService::run($db, $rowFor(), 1, 60, $opts);
$assets2 = (int) $db->query("SELECT COUNT(*) c FROM assets")->fetch_assoc()['c'];
$links2 = (int) $db->query("SELECT COUNT(*) c FROM asset_intune_links")->fetch_assoc()['c'];
$ok($r2['ok'] && $r2['stats']['created'] === 0 && $r2['stats']['updated'] === 150 && $assets2 === 150 && $links2 === 150, 'second sync is idempotent: 0 created, 150 updated, no duplicates');
$ok((int) $db->query("SELECT COUNT(*) c FROM intune_sync_log WHERE status='success'")->fetch_assoc()['c'] === 2, 'both runs logged as success');
$ok($r2['stats']['skipped'] === 0, 'no device skipped');
// failure logging with classification
$mock(['forbidden_devices' => true]);
$r3 = IntuneSyncService::run($db, $rowFor(), 1, 60, $opts);
$lg = $db->query("SELECT status, errors, error_code FROM intune_sync_log ORDER BY id DESC LIMIT 1")->fetch_assoc();
$ok(!$r3['ok'] && $r3['error_code'] === 'consent_missing' && $lg['status'] === 'failed' && $lg['error_code'] === 'consent_missing' && str_contains($lg['errors'], 'DeviceManagementManagedDevices.Read.All'), 'failed sync is logged with error_code consent_missing and the permission name');
$ok((int) $db->query("SELECT COUNT(*) c FROM assets")->fetch_assoc()['c'] === 150, 'a failed sync leaves existing assets untouched');
// lock
$db->query("INSERT INTO intune_sync_log SET microsoft_integration_id=$iid, status='running'");
$r4 = IntuneSyncService::run($db, $rowFor(), 1, 60, $opts);
$ok($r4['locked'] && !$r4['ok'], 'a run already in progress blocks a second one');
// secret never in logs
$ok(!str_contains(json_encode($db->query("SELECT * FROM intune_sync_log")->fetch_all(MYSQLI_ASSOC)), 'secret-1'), 'client secret never appears in the sync log');

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
