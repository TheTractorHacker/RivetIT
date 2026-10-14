<?php
/*
 * ManageEngine MDM client (src/Integrations/ManageEngine/MdmClient.php) against the LOCAL mock: data-center allowlist (SSRF), Zoho token refresh
 * and reuse, the Authorization header, paging (limit/offset, a server that ignores offset, a foreign paging.next that must never be followed),
 * rate limit / 5xx / 401 / 403 handling, time limit, read-only (only GETs), secrets never in messages, and the encrypted token store.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/manageengine_mdm_client.php
 */
require __DIR__ . '/manageengine_mdm_lib.php';

use ITFlow\Integrations\ManageEngine\DataCenters;
use ITFlow\Integrations\ManageEngine\MdmClient;
use ITFlow\Integrations\ManageEngine\MdmException;
use ITFlow\Integrations\ManageEngine\MysqliMdmTokenStore;

// ---- data centers: a fixed allowlist, never free text
$ok(array_keys(DataCenters::options()) === ['us', 'eu', 'in', 'au', 'jp', 'ca', 'sa', 'uk'], 'data centers: the documented eight (no China: its accounts host is unconfirmed)');
$ok(DataCenters::mdmBase('us') === 'https://mdm.manageengine.com' && DataCenters::mdmBase('eu') === 'https://mdm.manageengine.eu' && DataCenters::mdmBase('au') === 'https://mdm.manageengine.com.au', 'MDM hosts match the documented base URIs');
$ok(DataCenters::accountsBase('in') === 'https://accounts.zoho.in' && DataCenters::accountsBase('ca') === 'https://accounts.zohocloud.ca' && DataCenters::accountsBase('us') === 'https://accounts.zoho.com', 'accounts hosts follow the Zoho multi-DC list');
$allowedMdm = array_map(fn ($k) => DataCenters::mdmBase($k), array_keys(DataCenters::options()));
foreach (['evil.com', 'http://127.0.0.1', '169.254.169.254', 'us@evil.com', '../x', '', 'US ', "us\n", 'cn', "eu.evil.com", 'us/../../x'] as $bad) {
    $ok(in_array(DataCenters::mdmBase($bad), $allowedMdm, true) && in_array(DataCenters::accountsBase($bad), array_map(fn ($k) => DataCenters::accountsBase($k), array_keys(DataCenters::options())), true), 'free text ' . json_encode($bad) . ' can only ever resolve to an allowlisted host (' . DataCenters::mdmBase($bad) . ')');
}
$ok(!DataCenters::isValid('evil.com') && !DataCenters::isValid('cn') && DataCenters::isValid('uk'), 'isValid is an exact allowlist match');
foreach (array_keys(DataCenters::options()) as $k) {
    $ok(str_starts_with(DataCenters::mdmBase($k), 'https://') && str_starts_with(DataCenters::accountsBase($k), 'https://'), "data center $k uses https for both hosts");
}

// ---- token refresh, header, reuse
$reset();
mdm_seed_mock($mock, 3);
$c = $mk();
$rows3 = $c->listAllDevices();
$ok(count($rows3) === 3, 'lists the three devices');
$entries = $log();
$tok = array_values(array_filter($entries, fn ($e) => $e['path'] === '/oauth/v2/token'));
$ok(count($tok) === 1 && $tok[0]['method'] === 'POST' && $tok[0]['grant_type'] === 'refresh_token' && $tok[0]['form_keys'] === ['refresh_token', 'client_id', 'client_secret', 'grant_type'], 'one POST to /oauth/v2/token with refresh_token, client_id, client_secret, grant_type=refresh_token');
$api = array_values(array_filter($entries, fn ($e) => str_starts_with($e['path'], '/api/')));
$ok($api && $api[0]['auth'] === 'Zoho-oauthtoken at-1', 'API calls carry "Authorization: Zoho-oauthtoken <token>"');
$ok(strpos($api[0]['query'], 'access_token') === false && strpos($api[0]['query'], 'at-1') === false, 'the token is never sent as a query parameter');
$c->listAllDevices();
$ok($c->stats()['token_fetches'] === 1, 'a second listing reuses the token (one fetch in total)');

// ---- refresh 5 minutes before expiry
$reset(); mdm_seed_mock($mock, 2);
$clock = 2000000;
$d = $mk(['now' => function () use (&$clock) { return $clock; }]);
$d->listAllDevices();
$clock += 3600 - 400;
$d->listAllDevices();
$ok($d->stats()['token_fetches'] === 1, 'token still reused with 400 s left');
$clock += 150;
$d->listAllDevices();
$ok($d->stats()['token_fetches'] === 2, 'token refreshed once fewer than 300 s remain');

// ---- the token store is shared (encrypted, bound to dc + client + refresh token)
$reset(); mdm_seed_mock($mock, 2);
$iid = mdm_save_row();
$storeA = new MysqliMdmTokenStore($db, $iid, 'us', '1000.TESTCLIENT', 'good-refresh');
$a = $mk([], $storeA); $a->listAllDevices();
$row = $db->query("SELECT token_cache_enc, token_expires_at FROM manageengine_mdm_integrations WHERE id=$iid")->fetch_assoc();
$ok(!empty($row['token_cache_enc']) && !empty($row['token_expires_at']) && strpos($row['token_cache_enc'], 'at-') === false, 'the persisted token is encrypted (no plaintext token in the column) and has an expiry');
$b = $mk([], new MysqliMdmTokenStore($db, $iid, 'us', '1000.TESTCLIENT', 'good-refresh')); $b->listAllDevices();
$ok($b->stats()['token_fetches'] === 0 && $b->stats()['token_cache_hits'] === 1, 'the next run (like cron) reuses the stored token: Zoho allows only 10 per 10 minutes');
$other = $mk([], new MysqliMdmTokenStore($db, $iid, 'us', '1000.TESTCLIENT', 'other-refresh'));
$ok((new MysqliMdmTokenStore($db, $iid, 'us', '1000.TESTCLIENT', 'other-refresh'))->get() === null && (new MysqliMdmTokenStore($db, $iid, 'eu', '1000.TESTCLIENT', 'good-refresh'))->get() === null && (new MysqliMdmTokenStore($db, $iid, 'us', 'other-client', 'good-refresh'))->get() === null, 'another refresh token, data center or client id never reuses the stored token');
$storeA->clear();
$ok($storeA->get() === null, 'clear() empties the stored token');

// ---- sign-in failures (Zoho answers HTTP 200 with {"error": ...})
$reset(); mdm_seed_mock($mock, 1);
$e = $expect(fn () => $mk([], null, 'good-secret', 'revoked-refresh-SECRETVALUE')->listAllDevices(), MdmException::AUTH_FAILED);
$ok($e !== null && strpos($e->getMessage(), 'invalid_code') !== false, 'a bad refresh token is AUTH_FAILED (HTTP 200 + {"error":"invalid_code"} is recognised)');
$ok($e !== null && strpos($e->getMessage(), 'revoked-refresh-SECRETVALUE') === false && strpos($e->getMessage(), 'good-secret') === false, 'the error message never contains the refresh token or secret');
$e = $expect(fn () => $mk([], null, 'wrong-secret-VALUE2')->listAllDevices(), MdmException::AUTH_FAILED);
$ok($e !== null && strpos($e->getMessage(), 'wrong-secret-VALUE2') === false, 'a bad client secret is AUTH_FAILED and not echoed');
$mock(['token_throttle_text' => true]);
$e = $expect(fn () => $mk()->listAllDevices(), MdmException::THROTTLED);
$ok($e !== null && stripos($e->getMessage(), '10 access tokens per 10 minutes') !== false, "Zoho's \"too many requests\" token answer is THROTTLED, not a retry loop");
$reset(); mdm_seed_mock($mock, 1); $sleeps = [];
$mock(['token_429' => 2]);
$c = $mk(); $c->listAllDevices();
$ok($c->stats()['retries'] === 2 && $sleeps === [1.0, 1.0], 'token endpoint 429 with Retry-After: 1 is retried twice, then succeeds');

// ---- paging
$reset(); mdm_seed_mock($mock, 250);
$c = $mk(['page_size' => 100]);
$all = $c->listAllDevices();
$ok(count($all) === 250 && $c->stats()['pages'] === 3, 'pagination: 250 devices = 3 pages of limit=100 (offset 0, 100, 200)');
$qs = array_values(array_map(fn ($e) => $e['query'], array_filter($log(), fn ($e) => $e['path'] === '/api/v1/mdm/devices')));
$ok($qs === ['limit=100&offset=0', 'limit=100&offset=100', 'limit=100&offset=200'], 'the requests use limit/offset on our own host: ' . implode(' | ', $qs));
$ok(count(array_unique(array_column($all, 'device_id'))) === 250, 'no duplicate devices');
$reset(); mdm_seed_mock($mock, 100); $mock(['foreign_next' => true]);
$c = $mk(['page_size' => 100]); $c->listAllDevices();
$ok($c->stats()['pages'] === 1, 'a full single page ends the listing (the foreign "next" link is not followed)');
$reset(); mdm_seed_mock($mock, 150); $mock(['foreign_next' => true]);
$c = $mk(['page_size' => 100]); $all = $c->listAllDevices();
$paths = array_unique(array_map(fn ($e) => $e['path'], $log()));
$ok(count($all) === 150 && !in_array('/evil/api/v1/mdm/devices', $paths, true) && count(array_filter($paths, fn ($p) => str_contains($p, 'evil'))) === 0, 'paging.next pointing at another host is never requested (the access token never leaves the data center host)');
$reset(); mdm_seed_mock($mock, 250); $mock(['ignore_offset' => true]);
$c = $mk(['page_size' => 100]); $all = $c->listAllDevices();
$ok(count($all) === 100 && $c->stats()['pages'] === 2, 'a server that ignores offset cannot loop forever: stops when a page brings no new device id');
$reset(); mdm_seed_mock($mock, 120); $mock(['max_limit' => 50]);
$c = $mk(['page_size' => 100]); $all = $c->listAllDevices();
$qs = array_values(array_map(fn ($e) => $e['query'], array_filter($log(), fn ($e) => $e['path'] === '/api/v1/mdm/devices')));
$ok(count($all) === 120 && $qs === ['limit=100&offset=0', 'limit=100&offset=50', 'limit=100&offset=100'], 'a server that caps limit at 50 is still read completely (offset advances by what was received): ' . implode(' | ', $qs));
$reset(); mdm_seed_mock($mock, 130); $mock(['no_paging' => true]);
$c = $mk(['page_size' => 100]); $ok(count($c->listAllDevices()) === 130, 'works without a metadata/paging object (stops on a short page)');
$reset(); mdm_seed_mock($mock, 500);
$e = $expect(fn () => $mk(['page_size' => 10, 'max_pages' => 3])->listAllDevices(), MdmException::BAD_RESPONSE);
$ok($e !== null, 'the page cap stops a runaway listing (BAD_RESPONSE)');

// ---- details and summary
$reset(); mdm_seed_mock($mock, 2);
$c = $mk();
$id1 = (string) (9007199254740000 + 1);
$det = $c->getDevice($id1); $sum = $c->getDeviceSummary($id1);
$ok(($det['battery_level'] ?? null) === 77 && ($sum['group'][0]['group_name'] ?? '') === 'Training iPads', 'device details and summary are read');
$e = $expect(fn () => $c->getDevice('1; DROP TABLE x'), MdmException::BAD_RESPONSE);
$ok($e !== null, 'a non-numeric device id never becomes a URL path');
$e = $expect(fn () => $c->getDevice('123456789'), MdmException::OTHER);
$ok($e !== null && $e->httpStatus === 404 && strpos($e->getMessage(), 'COM0008') !== false, 'an unknown device is a clean 404 error (COM0008)');

// ---- errors, retries, backoff
$reset(); mdm_seed_mock($mock, 3); $sleeps = [];
$mock(['fail_once' => [429, 429], 'retry_after' => 1]);
$c = $mk(); $r = $c->listAllDevices();
$ok(count($r) === 3 && $c->stats()['retries'] === 2 && $sleeps === [1.0, 1.0], '429 (COM0002) with Retry-After is waited out and retried');
$reset(); mdm_seed_mock($mock, 3); $sleeps = [];
$mock(['fail_status' => 429]);
$e = $expect(fn () => $mk(['max_retries' => 2])->listAllDevices(), MdmException::THROTTLED);
$ok($e !== null && $e->httpStatus === 429 && count($sleeps) === 2, 'persistent 429 gives up after the retry budget as THROTTLED');
$reset(); mdm_seed_mock($mock, 3); $sleeps = [];
$mock(['fail_status' => 429]);
$c = $mk(['max_retries' => 4, 'base_backoff' => 1.0]);
$expect(fn () => $c->listAllDevices(), MdmException::THROTTLED);
$ok($sleeps === [1.0, 2.0, 4.0, 5.0], 'without Retry-After the wait doubles (1, 2, 4) and is capped at max_retry_after: ' . implode(',', $sleeps));
$reset(); mdm_seed_mock($mock, 3); $sleeps = [];
$mock(['fail_once' => [500]]);
$c = $mk(); $ok(count($c->listAllDevices()) === 3 && $c->stats()['retries'] === 1, 'a single 5xx (COM0004 "retry later") is retried once');
$mock(['fail_status' => 500]);
$e = $expect(fn () => $mk()->listAllDevices(), MdmException::SERVER_ERROR);
$ok($e !== null && $e->httpStatus === 500, 'a persistent 5xx is SERVER_ERROR after one retry');
$mock(['fail_status' => 403]);
$e = $expect(fn () => $mk()->listAllDevices(), MdmException::SCOPE_MISSING);
$ok($e !== null && strpos($e->getMessage(), 'MDMOnDemand.MDMInventory.READ') !== false, '403 is SCOPE_MISSING and names the scopes to grant');
$reset(); mdm_seed_mock($mock, 3); $mock(['unauthorized_once' => true]);
$c = $mk(); $r = $c->listAllDevices();
$ok(count($r) === 3 && $c->stats()['token_fetches'] === 2, '401 mid-run refreshes the token once and carries on');
$mock(['revoke_tokens' => true]);
$e = $expect(fn () => $mk()->listAllDevices(), MdmException::AUTH_FAILED);
$ok($e !== null, 'a token the API keeps refusing is AUTH_FAILED (no infinite refresh loop)');
$reset(); $mock(['html' => true]);
$e = $expect(fn () => $mk()->listAllDevices(), MdmException::BAD_RESPONSE);
$ok($e !== null, 'a non-JSON 200 is BAD_RESPONSE');
$reset(); mdm_seed_mock($mock, 3);
$e = $expect(fn () => $mk(['deadline' => time() - 1])->listAllDevices(), MdmException::TIME_LIMIT);
$ok($e !== null, 'the time limit stops the run before any request');
$e = $expect(function () use ($mk) { $c = new MdmClient('us', 'x', 'y', 'z', null, ['mdm_base' => 'http://127.0.0.1:1', 'accounts_base' => 'http://127.0.0.1:1', 'connect_timeout' => 1]); $c->listAllDevices(); }, MdmException::UNREACHABLE);
$ok($e !== null, 'a refused connection is UNREACHABLE');

// ---- Test connection
$reset(); mdm_seed_mock($mock, 2);
$r = $mk()->testConnection();
$ok($r->success && $r->details['inventory'] === 'ok' && $r->details['device_management'] === 'ok' && $r->details['total'] === 2, 'testConnection: inventory and device-management scopes both proven');
$mock(['summary_forbidden' => true]);
$r = $mk()->testConnection();
$ok(!$r->success && ($r->details['error_code'] ?? '') === 'scope_missing' && strpos((string) $r->error, 'MDMOnDemand.MDMDeviceMgmt.READ') !== false, 'testConnection: a missing MDMDeviceMgmt.READ scope is reported by name');
$reset();
$r = $mk([], null, 'good-secret', 'bad')->testConnection();
$ok(!$r->success && ($r->details['error_code'] ?? '') === 'auth_failed', 'testConnection: bad credentials are reported as auth_failed');
$reset(); mdm_seed_mock($mock, 0);
$r = $mk()->testConnection();
$ok($r->success && $r->details['device_management'] === 'not_checked', 'testConnection: an empty tenant still passes (device-management scope not checked)');

// ---- read-only: nothing but GET to the API and a single POST to the token endpoint
$reset(); mdm_seed_mock($mock, 5);
$c = $mk(); $c->listAllDevices(); $c->getDevice((string) (9007199254740000 + 1)); $c->getDeviceSummary((string) (9007199254740000 + 1)); $c->testConnection();
$methods = []; foreach ($log() as $e) { $methods[str_starts_with($e['path'], '/api/') ? 'api' : 'token'][$e['method']] = true; }
$ok(array_keys($methods['api']) === ['GET'] && array_keys($methods['token']) === ['POST'], 'read-only: every API request is a GET; the only POST is the token refresh');
$src = file_get_contents("$root/src/Integrations/ManageEngine/MdmClient.php");
$ok(preg_match_all("/send\('(\w+)'/", $src, $m) && array_values(array_unique($m[1])) === ['POST', 'GET'] && count(array_filter($m[1], fn($v) => $v === 'POST')) === 2 && substr_count($src, "this->accountsBase . '/oauth/v2/token'") === 2, 'the client source has only the Zoho token POSTs (refresh, and the one-time code exchange) and the GET');
$code = php_strip_whitespace("$root/src/Integrations/ManageEngine/MdmClient.php");   // comments removed
$ok(strpos($code, 'DELETE') === false && strpos($code, 'PUT') === false && stripos($code, 'wipe') === false && stripos($code, 'lock_device') === false, 'no wipe/lock/delete code path exists in the client');
