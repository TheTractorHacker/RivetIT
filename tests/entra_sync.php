<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Entra / Intune sync, issue #35: delta (incremental) directory sync with fallback, dry run, groups, licenses, Intune compliance + apps,
 * client-secret expiry (lookup, state, alerts), stalled-sync watch, health checks, scheduler. Everything runs against the LOCAL mock
 * tests/mock/graph_mock.php. It never contacts Microsoft and never sends mail, Slack, Teams or Odoo traffic (alerts go to stub functions).
 * Needs a schema-only scratch database (db.sql imported, current version):
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/entra_sync.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';

use ITFlow\Integrations\Microsoft\EntraDirectorySyncService;
use ITFlow\Integrations\Microsoft\EntraExtrasSync;
use ITFlow\Integrations\Microsoft\EntraHealth;
use ITFlow\Integrations\Microsoft\EntraScheduler;
use ITFlow\Integrations\Microsoft\EntraStatus;
use ITFlow\Integrations\Microsoft\GraphClient;
use ITFlow\Integrations\Microsoft\GraphException;
use ITFlow\Integrations\Microsoft\IntuneSyncService;
use ITFlow\Recovery\SyncWatch;

function encryptSetting(string $p): string { return $p === '' ? '' : 'ENC2:' . strrev($p); }
function decryptSetting(string $c): string { return str_starts_with($c, 'ENC2:') ? strrev(substr($c, 5)) : $c; }
// Alert stubs: RecoveryAlerts calls these when they exist. Nothing is mailed.
$GLOBALS['alerts'] = [];
function appNotify($type, $msg, $link = '') { $GLOBALS['alerts'][] = $msg; }
function logApp($cat, $lvl, $msg) { }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$db->set_charset('utf8mb4');
$db->query("SET SESSION sql_mode=''");
$pass = 0; $fail = 0;
$ok = function (bool $c, string $l, string $d = '') use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l" . ($d !== '' ? " -- $d" : '') . "\n"; } };
$sec = function (string $s) { echo "\n== $s\n"; };
$one = fn (string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$cnt = fn (string $sql) => (int) $one($sql);

// ---- mock server
$port = random_int(20000, 40000);
$state = sys_get_temp_dir() . '/entra_sync_mock_' . getmypid() . '.json';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/mock/graph_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MOCK_STATE' => $state] + $_ENV + ['PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($proc, $state) { proc_terminate($proc); foreach ([$state, "$state.log", "$state.lock"] as $f) @unlink($f); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
$base = "http://127.0.0.1:$port";
$mock = function (array $s) use ($base) { $c = stream_context_create(['http' => ['method' => 'POST', 'content' => json_encode($s), 'header' => 'Content-Type: application/json']]); return json_decode(file_get_contents("$base/__state", false, $c), true); };
$reqlog = fn () => json_decode(file_get_contents("$base/__log"), true);
$reset = fn () => $mock(['__reset' => 1]);
$copts = ['graph_base' => "$base/v1.0", 'authority' => $base, 'sleep' => function (float $s) {}, 'allow_writes' => false];

// ---- clean slate + one integration row
foreach (['entra_group_members', 'entra_groups', 'entra_licenses', 'entra_user_licenses', 'intune_device_compliance', 'intune_device_apps', 'entra_sync_log', 'microsoft_directory_users', 'microsoft_directory_sync_log', 'intune_sync_log', 'asset_intune_links', 'recovery_alerts', 'microsoft_integrations'] as $t) { $db->query("DELETE FROM `$t`"); }
$db->query("DELETE FROM contacts WHERE contact_email LIKE '%@example.test'");
$db->query("DELETE FROM assets WHERE asset_name LIKE 'MOCK-PC-%'");
$db->query("DELETE FROM clients WHERE client_name LIKE 'Dept %'");
$db->query("INSERT INTO microsoft_integrations SET tenant_id='ok-tenant', client_id='client-1', client_secret_enc='" . encryptSetting('secret-1') . "', enabled=1, directory_sync_enabled=1, intune_sync_enabled=1");
$iid = $db->insert_id;
$row = fn () => $db->query("SELECT * FROM microsoft_integrations WHERE microsoft_integration_id=$iid")->fetch_assoc();
$upd = function (string $set) use ($db, $iid) { $db->query("UPDATE microsoft_integrations SET $set WHERE microsoft_integration_id=$iid"); };
$contacts = fn () => $cnt("SELECT COUNT(*) FROM contacts WHERE contact_email LIKE '%@example.test'");
$clients = fn () => $cnt("SELECT COUNT(*) FROM clients WHERE client_name LIKE 'Dept %'");
$dirRun = fn (array $o = []) => EntraDirectorySyncService::run($db, $row(), 7, 60, $o + ['client_options' => $copts]);

// ================================================================ 1. directory: full mode
$sec('Directory sync, full mode (incremental off)');
$reset(); $mock(['users' => 30]);
$r = $dirRun();
$ok($r['ok'] && $r['mode'] === 'full' && $r['stats']['users_seen'] === 30, 'full run reads 30 users', $r['message']);
$ok($contacts() === 30 && $clients() === 3, '30 contacts and 3 departments created');
$ok($cnt("SELECT COUNT(*) FROM microsoft_directory_users WHERE microsoft_integration_id=$iid") === 30, 'a link row per user (Entra id -> contact)');
$lg = $db->query("SELECT * FROM microsoft_directory_sync_log ORDER BY id DESC LIMIT 1")->fetch_assoc();
$ok($lg['status'] === 'success' && $lg['sync_mode'] === 'full' && (int) $lg['dry_run'] === 0 && (int) $lg['users_seen'] === 30 && (int) $lg['employees_created'] === 30 && (int) $lg['triggered_by'] === 7, 'log row: success, mode full, counts, triggered_by');
$ok($row()['last_directory_sync_at'] !== null && $row()['directory_delta_link'] === null, 'last sync time set; no delta token saved with incremental off');
$log = $reqlog();
$ok(count(array_filter($log, fn ($l) => str_contains($l['path'], '/users/delta'))) === 0, 'incremental off: the delta endpoint is never called');
// a second full run is idempotent
$r = $dirRun();
$ok($r['ok'] && $contacts() === 30 && $r['stats']['employees']['created'] === 0 && $r['stats']['employees']['matched'] === 30, 'second full run: nothing new, 30 matched');

// ================================================================ 2. dry run
$sec('Directory sync, dry run');
$mock(['users' => 35]);
$beforeSync = $row()['last_directory_sync_at'];
$b = [$contacts(), $clients(), $cnt("SELECT COUNT(*) FROM microsoft_directory_users")];
$r = $dirRun(['dry_run' => true]);
$ok($r['ok'] && $r['dry_run'] && $r['stats']['employees']['created'] === 5, 'preview reports the 5 new users it would create', $r['message']);
$ok($b === [$contacts(), $clients(), $cnt("SELECT COUNT(*) FROM microsoft_directory_users")], 'preview changed nothing (contacts, departments, links identical)');
$lg = $db->query("SELECT * FROM microsoft_directory_sync_log ORDER BY id DESC LIMIT 1")->fetch_assoc();
$ok((int) $lg['dry_run'] === 1 && $lg['status'] === 'success' && (int) $lg['employees_created'] === 5, 'preview logged as dry_run with the real counts');
$ok($row()['last_directory_sync_at'] === $beforeSync, 'preview does not move the last-sync time');
$ok(str_starts_with($r['message'], 'Preview'), 'preview message says nothing was changed');
// a real run then does what the preview said
$r = $dirRun();
$ok($r['stats']['employees']['created'] === 5 && $contacts() === 35, 'the real run creates exactly those 5');

// ================================================================ 3. delta mode
$sec('Directory sync, incremental (delta) mode');
$upd('directory_delta_enabled=1');
$reset(); $mock(['users' => 35, 'delta_users' => 35]);
$r = $dirRun();
$ok($r['ok'] && $r['mode'] === 'full' && $r['stats']['users_seen'] === 35, 'first delta-mode run is the baseline (full read through /users/delta)', $r['message']);
$link1 = $row()['directory_delta_link'];
$ok(is_string($link1) && str_contains($link1, 'deltatoken=') && $row()['directory_delta_at'] !== null, 'the delta link is saved');
// change: user 3 gets a new address, user 4 deleted, user 5 disabled, one brand new user
$mock(['delta_changed' => [
    ['id' => 'user-0003', 'displayName' => 'User 3', 'mail' => 'user3.renamed@example.test', 'userPrincipalName' => 'user3.renamed@example.test', 'accountEnabled' => true, 'department' => 'Dept 0'],
    ['id' => 'user-0005', 'displayName' => 'User 5', 'mail' => 'user5@example.test', 'userPrincipalName' => 'user5@example.test', 'accountEnabled' => false, 'department' => 'Dept 2'],
    ['id' => 'user-9001', 'displayName' => 'New Hire', 'mail' => 'newhire@example.test', 'userPrincipalName' => 'newhire@example.test', 'accountEnabled' => true, 'department' => 'Dept 1'],
], 'delta_removed' => ['user-0004', 'user-NOT-LINKED']]);
$before = $contacts();
$r = $dirRun();
$ok($r['ok'] && $r['mode'] === 'delta' && !$r['fell_back'] && $r['stats']['users_seen'] === 3, 'second run is incremental: only the 3 changed users were read', $r['message']);
$ok($contacts() === $before + 1, 'only the new hire made a contact; the renamed mailbox did NOT make a second contact');
$ok($cnt("SELECT COUNT(*) FROM contacts WHERE contact_email='user3.renamed@example.test'") === 1 && $cnt("SELECT COUNT(*) FROM contacts WHERE contact_email='user3@example.test'") === 0, 'the linked contact got the new address (matched by Entra id, not by email)');
$ok($r['stats']['employees']['updated'] === 1 && $r['stats']['employees']['created'] === 1, 'counts: 1 updated (renamed), 1 created');
$ok($cnt("SELECT COUNT(*) FROM contacts WHERE contact_email='user4@example.test' AND contact_employment_status='terminated' AND contact_archived_at IS NOT NULL") === 1 && $r['stats']['users_removed'] === 1, 'a user deleted in Entra: contact archived and marked terminated (never deleted); unknown ids ignored');
$ok($cnt("SELECT COUNT(*) FROM contacts WHERE contact_email='user5@example.test' AND contact_archived_at IS NOT NULL") === 1, 'a disabled user is archived (existing behaviour kept)');
$ok($cnt("SELECT COUNT(*) FROM microsoft_directory_users WHERE entra_id='user-0004' AND removed_at IS NOT NULL") === 1, 'the link row of the deleted user is marked removed');
$link2 = $row()['directory_delta_link'];
$ok($link2 !== $link1 && str_contains($link2, 'deltatoken='), 'a new delta link replaced the old one');
$lg = $db->query("SELECT * FROM microsoft_directory_sync_log ORDER BY id DESC LIMIT 1")->fetch_assoc();
$ok($lg['sync_mode'] === 'delta' && (int) $lg['users_seen'] === 3 && (int) $lg['users_removed'] === 1, 'log row records mode delta, users seen, users removed');
$deltaCalls = array_values(array_filter($reqlog(), fn ($l) => str_contains($l['path'], '/users/delta') && str_contains($l['query'], 'deltatoken')));
$ok(count($deltaCalls) >= 1 && str_starts_with($deltaCalls[count($deltaCalls) - 1]['auth'], 'Bearer '), 'the delta request carried the saved token and a bearer token');

// delta preview does not take or move the token
$linkBefore = $row()['directory_delta_link'];
$mock(['delta_changed' => [['id' => 'user-9002', 'displayName' => 'Preview Hire', 'mail' => 'previewhire@example.test', 'userPrincipalName' => 'previewhire@example.test', 'accountEnabled' => true, 'department' => 'Dept 1']], 'delta_removed' => []]);
$c0 = $contacts();
$r = $dirRun(['dry_run' => true]);
$ok($r['ok'] && $r['mode'] === 'delta' && $r['stats']['employees']['created'] === 1 && $contacts() === $c0 && $row()['directory_delta_link'] === $linkBefore, 'delta preview: counts shown, nothing stored, token not moved');
$r = $dirRun();
$ok($r['stats']['employees']['created'] === 1, 'the next real run still sees that change (token was not consumed by the preview)');

// ---- invalid token -> full fallback
$sec('Delta token invalid -> full fallback');
$mock(['delta_invalid' => true, 'delta_users' => 35]);
$old = $row()['directory_delta_link'];
$r = $dirRun();
$ok($r['ok'] && $r['fell_back'] && $r['mode'] === 'full' && $r['stats']['users_seen'] === 35, 'Graph 410 syncStateNotFound: the run started over with a full read', $r['message']);
$ok(str_contains($r['message'], 'no longer valid'), 'the message says the saved token was reset');
$mock(['delta_invalid' => false]);
$ok($row()['directory_delta_link'] !== $old && str_contains($row()['directory_delta_link'], 'deltatoken='), 'a fresh delta link was taken');
// force full
$mock(['delta_users' => 35]);
$r = $dirRun(['force_full' => true]);
$ok($r['ok'] && $r['mode'] === 'full' && $r['stats']['users_seen'] === 35 && !$r['fell_back'], 'Full resync ignores the saved token');
// forgetDelta
EntraDirectorySyncService::forgetDelta($db, $iid);
$ok($row()['directory_delta_link'] === null, 'forgetDelta clears the token');

// ---- errors and the lock
$sec('Directory sync, errors, lock');
$keepLink = "$base/v1.0/users/delta?\$deltatoken=keep";
$upd("directory_delta_link='" . $db->real_escape_string($keepLink) . "'");
$bad = $row(); $bad['tenant_id'] = 'badsecret';
$r = EntraDirectorySyncService::run($db, $bad, 0, 60, ['client_options' => $copts]);
$ok(!$r['ok'] && $r['error_code'] === GraphException::AUTH_FAILED, 'bad secret: classified auth_failed');
$lg = $db->query("SELECT * FROM microsoft_directory_sync_log ORDER BY id DESC LIMIT 1")->fetch_assoc();
$ok($lg['status'] === 'failed' && $lg['error_code'] === 'auth_failed' && $lg['errors'] !== '' && $lg['finished_at'] !== null, 'failed log row has status, error code, message');
$ok($row()['directory_delta_link'] === $keepLink, 'a failed run keeps the saved delta token');
$mock(['forbidden_users' => true]);
$r = $dirRun();
$ok(!$r['ok'] && $r['error_code'] === GraphException::CONSENT_MISSING && str_contains($r['message'], 'User.Read.All'), 'delta mode 403: admin consent missing, naming User.Read.All');
$upd('directory_delta_enabled=0'); $r = $dirRun();
$ok(!$r['ok'] && $r['error_code'] === GraphException::CONSENT_MISSING, 'full mode 403: same classification');
$mock(['forbidden_users' => false]);
$db->query("INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id=$iid, status='running'");
$lockId = $db->insert_id;
$r = $dirRun();
$ok(!$r['ok'] && $r['locked'], 'a recent running row blocks an overlapping run');
$db->query("DELETE FROM microsoft_directory_sync_log WHERE id=$lockId");
$noSecret = $row(); $noSecret['client_secret_enc'] = '';
$ok(!EntraDirectorySyncService::run($db, $noSecret, 0, 60)['ok'], 'no secret saved: refused before any request');
$ok(EntraDirectorySyncService::isDue(null, 60, 1000) && !EntraDirectorySyncService::isDue(1000, 60, 1000 + 3599) && EntraDirectorySyncService::isDue(1000, 60, 1000 + 3600) && !EntraDirectorySyncService::isDue(1000, 5, 1000 + 14 * 60) && EntraDirectorySyncService::isDue(1000, 5, 1000 + 15 * 60), 'isDue: interval respected, never less than 15 minutes');

// ================================================================ 4. groups
$sec('Entra groups');
$reset(); $mock(['users' => 20]);
$r = EntraExtrasSync::run($db, 'groups', $row(), 3, 60, ['client_options' => $copts]);
$ok($r['ok'] && $r['stats']['seen'] === 3 && $r['stats']['created'] === 3, 'groups run: 3 groups created', $r['message']);
$ok($cnt("SELECT member_count FROM entra_groups WHERE entra_id='grp-1'") === 3 && $cnt("SELECT member_count FROM entra_groups WHERE entra_id='grp-2'") === 1 && $cnt("SELECT COUNT(*) FROM entra_group_members") === 4, 'members stored (3 + 1 + 0)');
$ok($one("SELECT group_kind FROM entra_groups WHERE entra_id='grp-1'") === 'security' && $one("SELECT group_kind FROM entra_groups WHERE entra_id='grp-2'") === 'microsoft365' && $one("SELECT group_kind FROM entra_groups WHERE entra_id='grp-3'") === 'distribution', 'group kinds: security, microsoft365, distribution');
$ok(EntraExtrasSync::groupKind(['securityEnabled' => true, 'mailEnabled' => true, 'groupTypes' => []]) === 'mail_security', 'mail-enabled security group kind');
$lg = $db->query("SELECT * FROM entra_sync_log ORDER BY id DESC LIMIT 1")->fetch_assoc();
$ok($lg['kind'] === 'groups' && $lg['status'] === 'success' && (int) $lg['items_seen'] === 3 && (int) $lg['triggered_by'] === 3 && (int) $lg['dry_run'] === 0, 'entra_sync_log row');
$mock(['group_defs' => [['id' => 'grp-1', 'displayName' => 'All Staff renamed', 'mailEnabled' => false, 'securityEnabled' => true, 'groupTypes' => []]], 'groups' => ['grp-1' => ['user-0000']]]);
$r = EntraExtrasSync::run($db, 'groups', $row(), 0, 60, ['client_options' => $copts]);
$ok($r['ok'] && $r['stats']['removed'] === 2 && $cnt("SELECT COUNT(*) FROM entra_groups") === 1 && $cnt("SELECT COUNT(*) FROM entra_group_members") === 1, 'groups deleted in Entra disappear, with their members');
$ok($one("SELECT display_name FROM entra_groups WHERE entra_id='grp-1'") === 'All Staff renamed' && $cnt("SELECT member_count FROM entra_groups") === 1, 'rename and member change picked up');
// dry run
$mock(['group_defs' => [['id' => 'grp-9', 'displayName' => 'New one', 'mailEnabled' => false, 'securityEnabled' => true, 'groupTypes' => []]]]);
$r = EntraExtrasSync::run($db, 'groups', $row(), 0, 60, ['client_options' => $copts, 'dry_run' => true]);
$ok($r['ok'] && $r['dry_run'] && $r['stats']['created'] === 1 && $r['stats']['removed'] === 1, 'groups preview: shows 1 new, 1 removed');
$ok($cnt("SELECT COUNT(*) FROM entra_groups WHERE entra_id='grp-9'") === 0 && $cnt("SELECT COUNT(*) FROM entra_groups WHERE entra_id='grp-1'") === 1, 'groups preview stored nothing and removed nothing');
$ok((int) $one("SELECT dry_run FROM entra_sync_log ORDER BY id DESC LIMIT 1") === 1, 'preview logged as dry_run');
// forbidden
$mock(['forbidden_groups' => true]);
$r = EntraExtrasSync::run($db, 'groups', $row(), 0, 60, ['client_options' => $copts]);
$ok(!$r['ok'] && $r['error_code'] === 'consent_missing' && str_contains($r['message'], 'Group.Read.All') && $cnt("SELECT COUNT(*) FROM entra_groups WHERE entra_id='grp-1'") === 1, '403 on groups: consent missing naming Group.Read.All; stored data untouched');
$ok($one("SELECT status FROM entra_sync_log ORDER BY id DESC LIMIT 1") === 'failed' && $one("SELECT error_code FROM entra_sync_log ORDER BY id DESC LIMIT 1") === 'consent_missing', 'failed groups run logged with its error code');
$mock(['forbidden_groups' => false]);
// cap: more groups than member reads per run
$many = []; for ($i = 1; $i <= EntraExtrasSync::MAX_GROUP_MEMBER_READS + 20; $i++) { $many[] = ['id' => "g-$i", 'displayName' => "Group $i", 'mailEnabled' => false, 'securityEnabled' => true, 'groupTypes' => []]; }
$mock(['group_defs' => $many, 'groups' => []]);
$r = EntraExtrasSync::run($db, 'groups', $row(), 0, 120, ['client_options' => $copts]);
$ok($r['ok'] && $r['stats']['skipped'] === 20 && $cnt("SELECT COUNT(*) FROM entra_groups WHERE members_synced_at IS NULL") === 20, 'member reads are capped per run (300); 20 groups wait for the next run', json_encode($r['stats']));
$r = EntraExtrasSync::run($db, 'groups', $row(), 0, 120, ['client_options' => $copts]);
$ok($r['ok'] && $cnt("SELECT COUNT(*) FROM entra_groups WHERE members_synced_at IS NULL") === 0, 'the next run reads the waiting groups first');
// lock
$db->query("INSERT INTO entra_sync_log SET microsoft_integration_id=$iid, kind='groups', status='running'");
$lockId = $db->insert_id;
$r = EntraExtrasSync::run($db, 'groups', $row(), 0, 60, ['client_options' => $copts]);
$ok(!$r['ok'] && $r['locked'], 'groups: overlapping run refused');
$r2 = EntraExtrasSync::run($db, 'licenses', $row(), 0, 60, ['client_options' => $copts]);
$ok(!$r2['locked'], 'the lock is per kind');
$db->query("DELETE FROM entra_sync_log WHERE id=$lockId");
$ok(!EntraExtrasSync::run($db, 'nonsense', $row())['ok'], 'unknown kind refused');

// ================================================================ 5. licenses
$sec('License SKUs');
$reset(); $mock(['users' => 20]);
$db->query("DELETE FROM entra_licenses"); $db->query("DELETE FROM entra_user_licenses");
$r = EntraExtrasSync::run($db, 'licenses', $row(), 0, 60, ['client_options' => $copts]);
$ok($r['ok'] && $r['stats']['seen'] === 2 && $r['stats']['created'] === 2, 'licenses run: 2 SKUs', $r['message']);
$ok($one("SELECT friendly_name FROM entra_licenses WHERE sku_id='sku-0001'") === 'Microsoft 365 Business Premium' && $one("SELECT friendly_name FROM entra_licenses WHERE sku_id='sku-0002'") === 'SOME_UNKNOWN_SKU', 'known SKU in words, unknown SKU by its part number');
$ok($cnt("SELECT units_consumed FROM entra_licenses WHERE sku_id='sku-0001'") === 5 && $cnt("SELECT units_enabled FROM entra_licenses WHERE sku_id='sku-0001'") === 10, 'purchased and consumed counts stored');
$ok($cnt("SELECT COUNT(*) FROM entra_user_licenses WHERE sku_id='sku-0001'") === 10, '10 of 20 users hold the SKU (assignedLicenses)');
$mock(['skus' => [['skuId' => 'sku-0001', 'skuPartNumber' => 'SPB', 'capabilityStatus' => 'Enabled', 'consumedUnits' => 9, 'prepaidUnits' => ['enabled' => 10, 'suspended' => 0, 'warning' => 0]]]]);
$r = EntraExtrasSync::run($db, 'licenses', $row(), 0, 60, ['client_options' => $copts]);
$ok($r['ok'] && $r['stats']['removed'] === 1 && $r['stats']['updated'] === 1 && $cnt("SELECT units_consumed FROM entra_licenses WHERE sku_id='sku-0001'") === 9 && $cnt("SELECT COUNT(*) FROM entra_licenses") === 1, 'a SKU that disappeared is removed; counts updated');
$mock(['skus' => [], 'users' => 4]);
$r = EntraExtrasSync::run($db, 'licenses', $row(), 0, 60, ['client_options' => $copts, 'dry_run' => true]);
$ok($r['ok'] && $r['dry_run'] && $cnt("SELECT COUNT(*) FROM entra_licenses") === 1 && $cnt("SELECT COUNT(*) FROM entra_user_licenses") === 10, 'licenses preview: stored data identical afterwards');
$mock(['forbidden_licenses' => true, 'skus' => null, 'users' => 20]);
$r = EntraExtrasSync::run($db, 'licenses', $row(), 0, 60, ['client_options' => $copts]);
$ok(!$r['ok'] && $r['error_code'] === 'consent_missing' && str_contains($r['message'], 'Organization.Read.All') && $cnt("SELECT COUNT(*) FROM entra_user_licenses") === 10, '403 on subscribedSkus: consent missing naming Organization.Read.All; data untouched');
$ok(EntraExtrasSync::friendlySku('ENTERPRISEPACK') === 'Office 365 E3' && EntraExtrasSync::friendlySku('XYZ') === 'XYZ', 'friendlySku');

// ================================================================ 6. Intune: dry run, details
$sec('Intune device sync preview and device details');
$reset(); $mock(['devices' => 5]);
$db->query("DELETE FROM asset_intune_links"); $db->query("DELETE FROM assets WHERE asset_name LIKE 'MOCK-PC-%'");
$a0 = $cnt("SELECT COUNT(*) FROM assets");
$r = IntuneSyncService::run($db, $row(), 0, 60, $copts, true);
$ok($r['ok'] && $r['dry_run'] && $r['stats']['created'] === 5 && str_starts_with($r['message'], 'Preview'), 'Intune preview: reports 5 new devices', $r['message']);
$ok($cnt("SELECT COUNT(*) FROM assets") === $a0 && $cnt("SELECT COUNT(*) FROM asset_intune_links") === 0, 'Intune preview created no assets and no links');
$ok((int) $one("SELECT dry_run FROM intune_sync_log ORDER BY id DESC LIMIT 1") === 1 && $one("SELECT status FROM intune_sync_log ORDER BY id DESC LIMIT 1") === 'success', 'preview logged as dry_run');
$ok($row()['last_sync_at'] === null, 'Intune preview does not set the last-sync time');
$r = IntuneSyncService::run($db, $row(), 0, 60, $copts);
$ok($r['ok'] && !$r['dry_run'] && $cnt("SELECT COUNT(*) FROM asset_intune_links") === 5 && (int) $one("SELECT dry_run FROM intune_sync_log ORDER BY id DESC LIMIT 1") === 0 && $row()['last_sync_at'] !== null, 'the real Intune run still works (5 links, last sync set)');
$r = EntraExtrasSync::run($db, 'intune_details', $row(), 0, 60, ['client_options' => $copts, 'dry_run' => true]);
$ok($r['ok'] && $r['stats']['created'] === 5 && $cnt("SELECT COUNT(*) FROM intune_device_compliance") === 0 && $cnt("SELECT COUNT(*) FROM asset_intune_links WHERE details_synced_at IS NOT NULL") === 0, 'details preview: 5 devices counted, nothing stored, devices not marked read');
$r = EntraExtrasSync::run($db, 'intune_details', $row(), 0, 60, ['client_options' => $copts]);
$ok($r['ok'] && $r['stats']['created'] === 5 && $r['stats']['skipped'] === 0, 'details run: 5 devices read', $r['message']);
$ok($cnt("SELECT COUNT(*) FROM intune_device_compliance") === 10 && $cnt("SELECT COUNT(*) FROM intune_device_apps") === 15, '2 policy states and 3 apps per device stored');
$ok($cnt("SELECT COUNT(*) FROM intune_device_compliance WHERE policy_state='nonCompliant'") === 1 && $one("SELECT policy_name FROM intune_device_compliance WHERE policy_state='nonCompliant'") === 'BitLocker required', 'the non-compliant policy state is kept by name');
$ok($cnt("SELECT setting_count FROM intune_device_compliance WHERE policy_name='Windows baseline' LIMIT 1") === 12 && $cnt("SELECT COUNT(*) FROM intune_device_apps WHERE app_name='Google Chrome' AND app_version='130.0.1'") === 5, 'setting count and app versions stored');
$ok($cnt("SELECT COUNT(*) FROM intune_device_compliance c JOIN asset_intune_links l ON l.asset_id=c.asset_id AND l.intune_device_id=c.intune_device_id") === 10, 'rows are tied to the asset');
$r = EntraExtrasSync::run($db, 'intune_details', $row(), 0, 60, ['client_options' => $copts]);
$ok($r['ok'] && $r['stats']['updated'] === 5 && $cnt("SELECT COUNT(*) FROM intune_device_compliance") === 10 && $cnt("SELECT COUNT(*) FROM intune_device_apps") === 15, 'rerun replaces rows, no duplicates');
$mock(['device_gone' => ['dev-0002']]);
$r = EntraExtrasSync::run($db, 'intune_details', $row(), 0, 60, ['client_options' => $copts]);
$ok($r['ok'] && $r['stats']['seen'] === 5, 'a device that 404s (left Intune) does not fail the run');
$mock(['forbidden_details' => true, 'device_gone' => []]);
$r = EntraExtrasSync::run($db, 'intune_details', $row(), 0, 60, ['client_options' => $copts]);
$ok(!$r['ok'] && $r['error_code'] === 'consent_missing' && str_contains($r['message'], 'DeviceManagementManagedDevices.Read.All'), '403 on device details: consent missing naming the permission');
$mock(['forbidden_details' => false]);
// cap
$db->query("DELETE FROM intune_device_compliance"); $db->query("DELETE FROM intune_device_apps"); $db->query("DELETE FROM asset_intune_links");
for ($i = 0; $i < EntraExtrasSync::MAX_DEVICE_DETAIL_READS + 5; $i++) { $db->query("INSERT INTO asset_intune_links SET asset_id=" . (900000 + $i) . ", microsoft_integration_id=$iid, intune_device_id='dev-" . sprintf('%04d', 100 + $i) . "', hostname='X$i'"); }
$r = EntraExtrasSync::run($db, 'intune_details', $row(), 0, 120, ['client_options' => $copts]);
$ok($r['ok'] && $r['stats']['seen'] === EntraExtrasSync::MAX_DEVICE_DETAIL_READS && $r['stats']['skipped'] === 5 && $cnt("SELECT COUNT(*) FROM asset_intune_links WHERE details_synced_at IS NULL") === 5, 'device reads are capped per run; 5 devices wait', json_encode($r['stats']));
$r = EntraExtrasSync::run($db, 'intune_details', $row(), 0, 120, ['client_options' => $copts]);
$ok($cnt("SELECT COUNT(*) FROM asset_intune_links WHERE details_synced_at IS NULL") === 0 && $cnt("SELECT COUNT(*) FROM intune_device_compliance WHERE intune_device_id='dev-" . sprintf('%04d', 100 + EntraExtrasSync::MAX_DEVICE_DETAIL_READS) . "'") === 2, 'the next run reads the never-read devices first');
$db->query("DELETE FROM asset_intune_links WHERE asset_id >= 900000"); $db->query("DELETE FROM intune_device_compliance"); $db->query("DELETE FROM intune_device_apps");

// ================================================================ 7. client secret expiry
$sec('Client secret expiry');
$c = new GraphClient('ok-tenant', 'client-1', 'secret-1', null, "$base/v1.0", $base, $copts);
$e = $c->clientSecretExpiry();
$ok($e !== null && $e['matched'] && $e['expires_at'] === '2027-01-15T00:00:00Z', 'lookup matches the secret by its 3-character hint');
$mock(['app_secrets' => [['displayName' => 'a', 'endDateTime' => '2027-06-01T00:00:00Z', 'hint' => 'xyz'], ['displayName' => 'b', 'endDateTime' => '2026-12-01T00:00:00Z', 'hint' => 'abc']]]);
$e = $c->clientSecretExpiry();
$ok($e !== null && !$e['matched'] && $e['expires_at'] === '2026-12-01T00:00:00Z', 'no hint matches: the earliest expiry is reported, flagged as not matched');
$mock(['no_app' => true]);
$ok($c->clientSecretExpiry() === null, 'app registration not found -> null');
$mock(['no_app' => false, 'app_secrets' => [['displayName' => 'cron', 'endDateTime' => '2027-01-15T00:00:00Z', 'hint' => 'sec']]]);
$res = EntraScheduler::refreshSecretExpiry($db, $row(), $copts);
$ok($res['ok'] && $row()['secret_expires_at'] === '2027-01-15' && $row()['secret_expiry_source'] === 'graph' && $row()['secret_expiry_checked_at'] !== null, 'refreshSecretExpiry stores the date with source graph');
$mock(['forbidden_apps' => true]);
$upd("secret_expires_at='2026-12-31', secret_expiry_source='manual'");
$res = EntraScheduler::refreshSecretExpiry($db, $row(), $copts);
$ok(!$res['ok'] && str_contains($res['message'], 'Application.Read.All') && $row()['secret_expires_at'] === '2026-12-31' && $row()['secret_expiry_source'] === 'manual', 'consent missing: clear message, and the date typed in by hand is NOT overwritten');
$mock(['forbidden_apps' => false]);
// state table (now = 2026-11-01 12:00 UTC)
$now = strtotime('2026-11-01 12:00:00 UTC');
$st = fn (?string $d) => EntraHealth::secretState($d, $now);
$ok($st(null)['state'] === 'none' && $st('')['state'] === 'none' && $st('garbage')['state'] === 'none', 'secretState: no/unparseable date -> none');
$ok($st('2026-10-31')['state'] === 'expired' && $st('2026-11-01')['state'] === 'crit' && $st('2026-11-08')['state'] === 'crit' && $st('2026-11-09')['state'] === 'warn' && $st('2026-12-01')['state'] === 'warn' && $st('2026-12-02')['state'] === 'ok', 'secretState: expired / 7-day / 30-day boundaries', json_encode([$st('2026-11-08'), $st('2026-11-09'), $st('2026-12-01'), $st('2026-12-02')]));
$ok($st('2026-11-01')['days'] === 0 && $st('2026-11-11')['days'] === 10, 'secretState: whole days left; the expiry day itself still counts');

// alerts
$db->query("DELETE FROM recovery_alerts"); $GLOBALS['alerts'] = [];
$upd("secret_expires_at='" . gmdate('Y-m-d', time() + 20 * 86400) . "'");
$ok(EntraHealth::watch($db) === 'warn' && count($GLOBALS['alerts']) === 1 && str_contains($GLOBALS['alerts'][0], 'expires in 20 days'), '20 days left: one alert');
EntraHealth::watch($db);
$ok(count($GLOBALS['alerts']) === 1, 'the same alert is not repeated right away (de-duplicated)');
$upd("secret_expires_at='" . gmdate('Y-m-d', time() + 5 * 86400) . "'");
$ok(EntraHealth::watch($db) === 'crit' && count($GLOBALS['alerts']) === 2, '5 days left: a new, more urgent alert');
$upd("secret_expires_at='" . gmdate('Y-m-d', time() - 2 * 86400) . "'");
$ok(EntraHealth::watch($db) === 'expired' && count($GLOBALS['alerts']) === 3 && str_contains($GLOBALS['alerts'][2], 'has expired'), 'expired: a third alert');
$upd("secret_expires_at='" . gmdate('Y-m-d', time() + 200 * 86400) . "'");
$ok(EntraHealth::watch($db) === 'ok' && $cnt("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key LIKE 'entra.secret.%'") === 0, 'secret renewed: alert state cleared');
$upd("secret_expires_at='" . gmdate('Y-m-d', time() + 20 * 86400) . "'");
EntraHealth::watch($db);
$ok(count($GLOBALS['alerts']) === 4, 'after renewal the next expiry alerts immediately again');
$upd('secret_expires_at=NULL');
$ok(EntraHealth::watch($db) === 'none', 'no date recorded: no alert, no error');

// ================================================================ 8. sync watch + health checks
$sec('Stalled / failing sync watch and health checks');
foreach (['microsoft_directory_sync_log', 'intune_sync_log', 'entra_sync_log'] as $t) { $db->query("DELETE FROM `$t`"); }
$watched = fn () => array_values(array_filter(SyncWatch::inspect($db, false), fn ($w) => in_array($w['source'], ['entra', 'entrax', 'intune'], true)));
$bySrc = function () use ($watched) { $o = []; foreach ($watched() as $w) { $o[$w['source']] = $w; } return $o; };
$upd('directory_cron_enabled=0, groups_sync_enabled=0, licenses_sync_enabled=0, intune_details_enabled=0, directory_cron_interval_min=60, extras_interval_min=360, intune_sync_enabled=1');
$w = $bySrc();
$ok(!isset($w['entra']) && !isset($w['entrax']) && isset($w['intune']), 'a directory sync that only runs by hand is not watched; Intune is');
$upd('directory_cron_enabled=1, groups_sync_enabled=1');
$w = $bySrc();
$ok(isset($w['entra']) && isset($w['entrax']), 'scheduled directory sync and extras are watched once switched on');
$ok($w['entra']['interval_min'] === 60 && $w['entra']['interval_source'] === 'setting' && $w['entrax']['interval_min'] === 360, 'expected interval is the configured one');
$ts = fn (int $ago) => date('Y-m-d H:i:s', time() - $ago);
// directory ran 3 days ago (interval 1 h) -> stale; a fresh PREVIEW must not hide that
$db->query("INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id=$iid, status='success', started_at='" . $ts(3 * 86400) . "', finished_at='" . $ts(3 * 86400) . "', triggered_by=0");
$db->query("INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id=$iid, status='success', started_at='" . $ts(60) . "', finished_at='" . $ts(60) . "', triggered_by=5, dry_run=1");
$w = $bySrc();
$ok($w['entra']['state'] === 'stale', 'stalled directory sync is stale; a newer preview does not make it look alive', $w['entra']['state'] . ' ' . $w['entra']['detail']);
$db->query("INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id=$iid, status='success', started_at='" . $ts(600) . "', finished_at='" . $ts(590) . "', triggered_by=0");
$ok($bySrc()['entra']['state'] === 'ok', 'a real recent run is healthy');
for ($i = 0; $i < 3; $i++) { $db->query("INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id=$iid, status='failed', errors='Microsoft sign-in failed', error_code='auth_failed', started_at='" . $ts(300 - $i * 60) . "', finished_at='" . $ts(290 - $i * 60) . "', triggered_by=0"); }
$ok($bySrc()['entra']['state'] === 'failing', 'three failed runs in a row: failing');
// Intune preview rows ignored too
$db->query("INSERT INTO intune_sync_log SET microsoft_integration_id=$iid, status='success', started_at='" . $ts(2 * 86400) . "', finished_at='" . $ts(2 * 86400) . "', triggered_by=0");
$db->query("INSERT INTO intune_sync_log SET microsoft_integration_id=$iid, status='success', started_at='" . $ts(30) . "', finished_at='" . $ts(30) . "', triggered_by=1, dry_run=1");
$ok($bySrc()['intune']['state'] === 'stale', 'Intune: a fresh preview does not hide a stalled sync');
// extras
$db->query("INSERT INTO entra_sync_log SET microsoft_integration_id=$iid, kind='groups', status='success', started_at='" . $ts(10 * 3600) . "', finished_at='" . $ts(10 * 3600) . "', triggered_by=0");
$ok($bySrc()['entrax']['state'] === 'ok', 'extras run 10 h ago with a 6 h interval: still healthy (the limit is 3 x the interval = 18 h)');
$db->query("DELETE FROM entra_sync_log"); $db->query("INSERT INTO entra_sync_log SET microsoft_integration_id=$iid, kind='groups', status='success', started_at='" . $ts(20 * 3600) . "', finished_at='" . $ts(20 * 3600) . "', triggered_by=0");
$ok($bySrc()['entrax']['state'] === 'stale', 'extras last ran 20 h ago (limit 18 h): stale');
// alerts through SyncWatch::run
$db->query("DELETE FROM recovery_alerts"); $GLOBALS['alerts'] = [];
$problems = SyncWatch::run($db, false);
$srcs = array_column($problems, 'source');
$ok(in_array('entra', $srcs, true) && in_array('entrax', $srcs, true) && in_array('intune', $srcs, true) && count($GLOBALS['alerts']) >= 3, 'SyncWatch::run raises notifications for the failing/stale Entra, extras and Intune syncs', json_encode($srcs));
$ok(ITFlow\Recovery\WatchOverrides::SOURCES === ['rmm', 'intune', 'unifi', 'mdm', 'entra', 'entrax'], 'overrides (mute / interval / forget) accept the new sources');
// health checks
$upd("secret_expires_at='" . gmdate('Y-m-d', time() + 3 * 86400) . "', secret_expiry_source='manual'");
$chk = []; foreach (EntraHealth::checks($db) as $c) { $chk[$c['id']] = $c; }
$ok(isset($chk['entra_secret']) && $chk['entra_secret']['status'] === 'fail' && str_contains($chk['entra_secret']['detail'], 'days left'), 'health check: secret 3 days from expiry is a problem');
$ok(isset($chk['entra_sync']) && $chk['entra_sync']['status'] === 'fail' && str_contains($chk['entra_sync']['detail'], 'failing'), 'health check: failing sync is a problem and names it');
$upd("secret_expires_at='" . gmdate('Y-m-d', time() + 20 * 86400) . "'");
$ok(EntraHealth::checks($db)[0]['status'] === 'warn', 'health check: 20 days is a warning');
$upd("secret_expires_at='" . gmdate('Y-m-d', time() + 200 * 86400) . "'");
$ok(EntraHealth::checks($db)[0]['status'] === 'ok', 'health check: far from expiry is OK');
$upd('secret_expires_at=NULL');
$ok(EntraHealth::checks($db)[0]['status'] === 'info', 'health check: no date recorded is a note');
$upd('enabled=0');
$ok(EntraHealth::checks($db) === [] && EntraHealth::watch($db) === 'off', 'integration disabled: no checks, no alerts');
$upd('enabled=1');
require_once __DIR__ . '/../includes/update_checks.php';
$uc = updateChecks(dirname(__DIR__), '2.6.226', '2.6.226', $db);
$ids = array_column($uc, 'id');
$ok(in_array('entra_secret', $ids, true) || in_array('exec', $ids, true) || in_array('git', $ids, true), 'Update > Checks includes the Entra lines (or git/exec are unavailable here)', implode(',', $ids));
$ok(!in_array('entra_secret', array_column(updateChecks(dirname(__DIR__), '2.6.226', '2.6.226'), 'id'), true), 'without a database handle the Update checks are unchanged');
// Server status
$ss = new ITFlow\Ops\ServerStatus($db, dirname(__DIR__));
$labels = [];
foreach ($ss->run() as $g) { foreach ($g['checks'] as $c) { $labels[] = $c['label']; } }
$ok(in_array('Entra client secret expiry', $labels, true) && in_array('Entra / Intune syncs are running', $labels, true), 'Server status lists the Entra secret and sync lines');

// ================================================================ 9. scheduler
$sec('Scheduler (cron)');
foreach (['microsoft_directory_sync_log', 'intune_sync_log', 'entra_sync_log', 'recovery_alerts'] as $t) { $db->query("DELETE FROM `$t`"); }
$reset(); $mock(['users' => 12]);
$upd('directory_cron_enabled=0, directory_delta_enabled=0, groups_sync_enabled=0, licenses_sync_enabled=0, intune_details_enabled=0, secret_expiry_source=NULL, secret_expires_at=NULL');
$lines = EntraScheduler::run($db, $row(), time(), $copts);
$ok($lines === [] && count($reqlog()) === 0, 'everything off (the default): the scheduler does nothing and makes no request');
$upd('directory_cron_enabled=1, directory_cron_interval_min=60');
$lines = EntraScheduler::run($db, $row(), time(), $copts);
$ok(count($lines) === 1 && $lines[0]['level'] === 'info' && $cnt("SELECT COUNT(*) FROM microsoft_directory_sync_log WHERE dry_run=0") === 1 && $contacts() >= 12, 'directory cron on: one run', json_encode($lines));
$lines = EntraScheduler::run($db, $row(), time() + 600, $copts);
$ok($lines === [] && $cnt("SELECT COUNT(*) FROM microsoft_directory_sync_log") === 1, '10 minutes later: not due, no second run');
$lines = EntraScheduler::run($db, $row(), time() + 3700, $copts);
$ok(count($lines) === 1 && $cnt("SELECT COUNT(*) FROM microsoft_directory_sync_log") === 2, 'an hour later: due again');
$noDir = $row(); $noDir['directory_sync_enabled'] = 0;
$db->query("DELETE FROM microsoft_directory_sync_log");
$ok(EntraScheduler::run($db, $noDir, time(), $copts) === [], 'directory cron on but "Sync users from Entra ID" off: nothing runs');
// preview rows do not count as "last run" for due-ness
$db->query("INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id=$iid, status='success', started_at=NOW(), finished_at=NOW(), dry_run=1");
$lines = EntraScheduler::run($db, $row(), time(), $copts);
$ok(count($lines) === 1, 'a preview does not delay the scheduled run');
// extras
$upd('groups_sync_enabled=1, licenses_sync_enabled=1, intune_details_enabled=1, extras_interval_min=1440, directory_cron_enabled=0');
$lines = EntraScheduler::run($db, $row(), time(), $copts);
$kindsRun = array_column($db->query("SELECT kind FROM entra_sync_log ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'kind');
$ok($kindsRun === ['groups', 'licenses', 'intune_details'], 'groups, licenses and Intune details each ran once', json_encode($kindsRun));
$lines = EntraScheduler::run($db, $row(), time() + 3600, $copts);
$ok($lines === [], 'extras are daily: not due an hour later');
$lines = EntraScheduler::run($db, $row(), time() + 90000, $copts);
$ok(count($lines) === 3, 'extras due after a day');
$upd('intune_sync_enabled=0');
$db->query("DELETE FROM entra_sync_log");
EntraScheduler::run($db, $row(), time(), $copts);
$ok(!in_array('intune_details', array_column($db->query("SELECT kind FROM entra_sync_log")->fetch_all(MYSQLI_ASSOC), 'kind'), true), 'Intune details need the Intune sync switched on too');
$upd('intune_sync_enabled=1, groups_sync_enabled=0, licenses_sync_enabled=0, intune_details_enabled=0');
// secret refresh only when source = graph
$upd("secret_expiry_source='graph', secret_expires_at='2026-01-01', secret_expiry_checked_at=NULL");
$lines = EntraScheduler::run($db, $row(), time(), $copts);
$ok(count($lines) === 1 && str_contains($lines[0]['text'], 'secret expiry') && $row()['secret_expires_at'] === '2027-01-15', 'a looked-up expiry is refreshed daily');
$lines = EntraScheduler::run($db, $row(), time() + 600, $copts);
$ok($lines === [], 'but only once a day');
$upd("secret_expiry_source='manual', secret_expires_at='2026-01-01', secret_expiry_checked_at=NULL");
$ok(EntraScheduler::run($db, $row(), time(), $copts) === [] && $row()['secret_expires_at'] === '2026-01-01', 'a hand-typed expiry is never looked up or overwritten by cron');
$disabled = $row(); $disabled['enabled'] = 0;
$ok(EntraScheduler::run($db, $disabled, time(), $copts) === [], 'integration disabled: nothing');

// ================================================================ 10. status data
$sec('Status page data');
$upd('directory_cron_enabled=1, directory_delta_enabled=1, groups_sync_enabled=1, licenses_sync_enabled=0, intune_details_enabled=0, secret_expiry_source=NULL');
foreach (['microsoft_directory_sync_log', 'intune_sync_log', 'entra_sync_log'] as $t) { $db->query("DELETE FROM `$t`"); }
$sy = fn () => array_column(EntraStatus::syncs($db, $row(), time()), null, 'key');
$s = $sy();
$ok(array_keys($s) === ['directory', 'intune', 'groups', 'licenses', 'intune_details'] && $s['directory']['last'] === null && $s['directory']['next'] === 'due on the next cron run', 'never run: no last run, next run due');
$ok($s['licenses']['on'] === false && $s['licenses']['next'] === 'switched off' && str_contains($s['directory']['schedule'], 'incremental') && $s['directory']['schedule'] !== $s['groups']['schedule'], 'switches and schedules are reported');
$db->query("INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id=$iid, status='success', started_at='" . $ts(1800) . "', finished_at='" . $ts(1790) . "', sync_mode='delta', users_seen=4, employees_created=1, employees_updated=2, employees_matched=1, triggered_by=0");
$db->query("INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id=$iid, status='failed', started_at='" . $ts(600) . "', finished_at='" . $ts(590) . "', error_code='consent_missing', errors='Admin consent missing for User.Read.All', triggered_by=3");
$db->query("INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id=$iid, status='success', started_at='" . $ts(100) . "', finished_at='" . $ts(90) . "', dry_run=1, employees_created=9, triggered_by=3");
$s = $sy()['directory'];
$ok($s['last']['status'] === 'failed' && $s['last']['error_label'] === 'Admin consent missing' && $s['last']['trigger'] === 'manual' && str_contains($s['last']['error'], 'User.Read.All'), 'last real run: failed, classified, manual');
$ok($s['lastOk'] !== null && $s['lastOk']['mode'] === 'delta' && str_contains($s['lastOk']['counts'], '4 read'), 'last success is shown separately');
$ok($s['preview'] !== null && str_contains($s['preview']['counts'], '9 new') && $s['preview']['started'] > $s['last']['started'], 'the preview is listed separately from the real runs');
$ok(str_starts_with($s['next'], 'in '), 'next run: when the schedule says (last real run + interval)', $s['next']);
$inv = EntraStatus::inventory($db, $iid);
$ok(isset($inv['groups'], $inv['licenses'], $inv['details']) && $inv['details']['devices'] === 0 && count($inv['groups']) > 0, 'inventory summary builds (groups from the earlier run, no Intune links left)');

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES: $fail") . " ($pass passed, $fail failed)\n";
exit($fail === 0 ? 0 : 1);
