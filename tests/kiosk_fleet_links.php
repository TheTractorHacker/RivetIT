<?php
/*
 * Training kiosk fleet links (DB 2.6.151, issue #43): token lifecycle, expiry, max uses, revocation / rotation, approval vs auto-enroll,
 * serial matching (within the link's department only), input validation, "no data before approval", scope, the Web Clip profile and the
 * per-device URL export, plus the kiosk entry redirect over real HTTP.
 *
 * SCRATCH DATABASE ONLY (same refusal rules as tests/endpoint_agent_lib.php):
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/kiosk_fleet_links.php
 * config.php must point at the same scratch database. Never run against a live database.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "set RIVETIT_TEST_DB=1\n"); exit(2); }
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
$root = dirname(__DIR__);
$cfgText = @file_get_contents("$root/config.php");
if (!$cfgText || !preg_match('/\$database\s*=\s*[\'"]' . preg_quote(getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfgText)) {
    fwrite(STDERR, "Refusing: config.php must point at the same scratch database\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('UTC');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'fleet-test';
require_once "$root/config.php";
require_once "$root/functions.php";
require_once "$root/vendor/autoload.php";
$db = $mysqli;
if (!preg_match('/scratch/i', (string) ($db->query('SELECT DATABASE()')->fetch_row()[0] ?? ''))) { fwrite(STDERR, "Refusing: connected database is not a scratch one\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
$GLOBALS['mysqli'] = $db;
$session_user_id = 0; $session_ip = '127.0.0.1'; $session_user_agent = 'test';

require_once "$root/includes/database_version.php";

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskKeys;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Device\FleetEnrollment;
use ITFlow\Training\Kiosk\Device\FleetLinks;

$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
register_shutdown_function(function () use (&$fails, &$passes) { echo "\n$passes passed, $fails failed\n"; });
/** The ApiException a closure throws, or null. */
$thrown = function (callable $fn): ?ApiException { try { $fn(); } catch (ApiException $e) { return $e; } return null; };

$base = 'https://scratch.local';
$admin = new Ctx($db, 1, true, 3, $base, TrainingSettings::fromDb($db), 'test');
$scoped = new Ctx($db, 7, false, 1, $base, TrainingSettings::fromDb($db), 'test');   // level 1: sees only user_client_permissions departments
$ks = KioskSettings::fromDb($db);
$keys = KioskKeys::fromSecret(str_repeat('k', 48));
$kctx = fn(?array $device = null) => new KioskCtx(new Ctx($db, 0, false, 0, $base, TrainingSettings::fromDb($db), 'fleet-test-ua'), $ks, $keys, $device, null, 'en', (int) hrtime(true));
$redeem = fn($token, $sn = null, ?array $device = null) => FleetEnrollment::redeem($kctx($device), $token, $sn);

function reset_all(): void
{
    global $q;
    foreach (['training_kiosk_sessions', 'training_kiosks', 'training_fleet_links', 'training_rate_buckets', 'assets', 'user_client_permissions', 'clients', 'audit_events'] as $t) { $q("DELETE FROM $t"); }
    $q("UPDATE settings SET config_training_enroll_pause_until_utc = NULL WHERE company_id = 1");
    $q("INSERT INTO clients SET client_id=1, client_name='Dept A'");
    $q("INSERT INTO clients SET client_id=2, client_name='Dept B'");
    $q("INSERT INTO user_client_permissions SET user_id=7, client_id=1");
    $_COOKIE = [];
}
function add_asset(int $client, string $name, ?string $serial, string $type = 'Tablet', bool $archived = false): int
{
    global $db;
    $serialSql = $serial === null ? 'NULL' : "'" . $db->real_escape_string($serial) . "'";
    $db->query("INSERT INTO assets SET asset_type='" . $db->real_escape_string($type) . "', asset_name='" . $db->real_escape_string($name) . "', asset_make='Apple', asset_serial=$serialSql,
        asset_client_id=$client" . ($archived ? ", asset_archived_at=NOW()" : ''));
    return (int) $db->insert_id;
}
function in_days(int $d): string { return KTime::plus($d * 86400); }

$fleet = new FleetLinks($admin);

// ---------------------------------------------------------------- schema + migration gate
reset_all();
$ok($one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='training_fleet_links'") == 1, 'training_fleet_links exists');
$ok(strpos((string) $one("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='training_kiosks' AND column_name='kiosk_enroll_method'"), "'fleet'") !== false, "kiosk_enroll_method allows 'fleet'");
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.151', '>=') && version_compare($one("SELECT config_current_database_version FROM settings WHERE company_id=1"), '2.6.151', '>='), 'database version is at least 2.6.151');

// ---------------------------------------------------------------- token lifecycle: create
$r = $fleet->create('Fab iPads', 1, 'require', in_days(30), 5);
$tok = $r['token'];
$ok(preg_match(FleetLinks::TOKEN_RE, $tok) === 1, 'token has the rvkf1_ + 43 char form');
$ok($r['url'] === $base . '/kiosk/?e=' . $tok, 'url is /kiosk/?e=<token>');
$dump = (string) $one("SELECT GROUP_CONCAT(CONCAT_WS('|', fleet_token_hash, fleet_label, fleet_revoke_reason)) FROM training_fleet_links");
$ok(strpos($dump, $tok) === false && strpos($dump, hash('sha256', $tok)) !== false, 'only sha256(token) is stored');
$ok(strpos((string) $one("SELECT GROUP_CONCAT(CONCAT_WS('|', summary, metadata_json)) FROM audit_events WHERE event_type LIKE 'training.kiosk_fleet%'"), $tok) === false
    && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='training.kiosk_fleet_created'") === 1, 'creation audited, token not in the audit trail');
$list = $fleet->listLinks();
$ok(count($list) === 1 && $list[0]['state'] === 'active' && !array_key_exists('token', $list[0]) && !array_key_exists('token_hash', $list[0]), 'list shows state, never a token or hash');

// ---------------------------------------------------------------- create validation
$bad = [
    'empty name' => fn() => $fleet->create('  ', 1, 'require', in_days(5), 5),
    'name too long' => fn() => $fleet->create(str_repeat('x', 101), 1, 'require', in_days(5), 5),
    'bad approval' => fn() => $fleet->create('x', 1, 'always', in_days(5), 5),
    'zero uses' => fn() => $fleet->create('x', 1, 'require', in_days(5), 0),
    'too many uses' => fn() => $fleet->create('x', 1, 'require', in_days(5), FleetLinks::MAX_USES_CAP + 1),
    'expiry in the past' => fn() => $fleet->create('x', 1, 'require', KTime::plus(-60), 5),
    'expiry beyond 90 days' => fn() => $fleet->create('x', 1, 'require', in_days(120), 5),
    'unknown department' => fn() => $fleet->create('x', 99, 'require', in_days(5), 5),
];
foreach ($bad as $label => $fn) { $e = $thrown($fn); $ok($e !== null && $e->http === 422, "create refused: $label"); }
$ok((int) $one('SELECT COUNT(*) FROM training_fleet_links') === 1, 'refused creates wrote nothing');

// ---------------------------------------------------------------- scope
$sf = new FleetLinks($scoped);
$e = $thrown(fn() => $sf->create('x', 2, 'require', in_days(5), 5));
$ok($e !== null && $e->http === 403, 'a user scoped to Dept A cannot create a link for Dept B');
$linkB = $fleet->create('B link', 2, 'require', in_days(5), 5);
$ok(count($sf->listLinks()) === 1 && $sf->listLinks()[0]['department'] === 'Dept A', 'a scoped user lists only their departments');
$e = $thrown(fn() => $sf->revoke($linkB['fleet_id'], 'nope nope'));
$ok($e !== null && $e->http === 404, 'a scoped user cannot revoke another department\'s link');
$fleet->revoke($linkB['fleet_id'], 'cleanup');

// ---------------------------------------------------------------- redeem: unknown / malformed tokens, rate limit
$e = $thrown(fn() => $redeem('garbage'));
$ok($e !== null && $e->http === 403 && $e->errCode === 'fleet_invalid', 'malformed token -> 403 fleet_invalid');
$e = $thrown(fn() => $redeem(FleetLinks::TOKEN_PREFIX . str_repeat('a', 43)));
$ok($e !== null && $e->http === 403 && $e->errCode === 'fleet_invalid', 'unknown token -> 403 fleet_invalid');
$ok((int) $one("SELECT COUNT(*) FROM training_events WHERE tevent_type='kiosk.enroll_failed'") >= 2, 'misses are recorded in the ledger');
for ($i = 0; $i < 25; $i++) { $e = $thrown(fn() => $redeem('x' . $i)); }
$ok($e !== null && $e->http === 429, 'more than 20 misses a minute -> 429');
$e = $thrown(fn() => $redeem($tok, null));
$ok($e === null || $e->http !== 429, 'a VALID token is not throttled by the miss bucket');
$q('DELETE FROM training_kiosks'); $q('UPDATE training_fleet_links SET fleet_use_count = 0'); $q('DELETE FROM training_rate_buckets');

// ---------------------------------------------------------------- redeem: serial validation (no use consumed)
foreach (['%SerialNumber%' => 'unexpanded macro', '{{serialnumber}}' => 'braces', 'ab' => 'too short', str_repeat('A', 65) => 'too long', 'AB CD1234' => 'space', "AB;DROP" => 'semicolon', '../../etc' => 'path'] as $sn => $why) {
    $e = $thrown(fn() => $redeem($tok, (string) $sn));
    $ok($e !== null && $e->http === 422 && $e->errCode === 'fleet_serial_invalid', "serial refused: $why");
}
$e = $thrown(fn() => $redeem($tok, ['a']));
$ok($e !== null && $e->errCode === 'fleet_serial_invalid', 'serial refused: array');
$ok((int) $one('SELECT fleet_use_count FROM training_fleet_links WHERE fleet_id=' . $r['fleet_id']) === 0 && (int) $one('SELECT COUNT(*) FROM training_kiosks') === 0, 'a bad serial consumes no use and creates no device');

// ---------------------------------------------------------------- redeem: require approval, no match / plain link -> pending, no data
$assetA = add_asset(1, 'Fab iPad 1', 'F9FXK1ABCDEF');
$res = $redeem($tok, 'UNKNOWN12345');
$ok($res['state'] === 'pending' && $res['next'] === '/kiosk/', 'unmatched serial -> pending');
$row = $db->query("SELECT * FROM training_kiosks WHERE kiosk_fleet_serial='UNKNOWN12345'")->fetch_assoc();
$ok($row['kiosk_status'] === 'pending' && $row['kiosk_enroll_method'] === 'fleet' && $row['kiosk_asset_id'] === null && (int) $row['kiosk_default_client_id'] === 1 && $row['kiosk_fleet_note'] === 'no_match', 'pending row: fleet method, department set, unlisted, note no_match');
$ok($row['kiosk_enrolled_at_utc'] === null && $row['kiosk_enrolled_by'] === null, 'pending row has no enrolled_at / enrolled_by');
$pendingToken = null;   // the plain device token only ever leaves in a cookie; recover it by planting a known hash
$pTok = KioskAuth::newToken();
$q("UPDATE training_kiosks SET kiosk_token_hash='" . KioskAuth::tokenHash($pTok) . "' WHERE kiosk_id=" . (int) $row['kiosk_id']);
$reason = null;
$dev = KioskAuth::deviceByTokenHash($db, $ks, KioskAuth::tokenHash($pTok), $reason);
$ok($dev === null && $reason === 'pending', 'NO DATA BEFORE APPROVAL: a pending fleet device is not a usable device (reason pending)');
$_COOKIE[KioskAuth::DEV_COOKIE] = $pTok;
$dev = KioskAuth::device($db, $ks, $reason);
$ok($dev === null && $reason === 'pending' && KioskAuth::awaitingApproval(), 'cookie of a pending device: no device, awaitingApproval() true (cookie is kept)');
$again = $redeem($tok, 'UNKNOWN12345');
$ok($again['state'] === 'pending' && !empty($again['already']) && (int) $one('SELECT COUNT(*) FROM training_kiosks') === 1 && (int) $one('SELECT fleet_use_count FROM training_fleet_links WHERE fleet_id=' . $r['fleet_id']) === 1,
    're-opening the link on a pending tablet creates nothing and uses nothing');
$_COOKIE = [];
$e = $thrown(fn() => $redeem($tok, 'UNKNOWN12345'));
$ok($e !== null && $e->http === 409 && $e->errCode === 'fleet_already_enrolled', 'the same serial without the cookie is refused (no takeover)');
$res = $redeem($tok, null);
$ok($res['state'] === 'pending' && $one("SELECT kiosk_fleet_note FROM training_kiosks WHERE kiosk_fleet_serial IS NULL") === 'no_serial', 'plain link (no serial) -> unlisted pending, note no_serial');
$ok((int) $one("SELECT COUNT(*) FROM training_events WHERE tevent_type='kiosk.fleet_pending' AND tevent_kiosk_id IN (SELECT kiosk_id FROM training_kiosks)") === 2
    && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='training.kiosk_fleet_enrolled' AND entity_id IN (SELECT kiosk_id FROM training_kiosks)") === 2, 'one ledger event and one audit entry per enrollment');
$ok((int) $one('SELECT fleet_use_count FROM training_fleet_links WHERE fleet_id=' . $r['fleet_id']) === 2, 'use count counts each new device');

// ---------------------------------------------------------------- serial matches an asset, require approval -> pending with the asset bound
$res = $redeem($tok, 'f9fxk1abcdef');   // case-insensitive
$m = $db->query("SELECT * FROM training_kiosks WHERE kiosk_fleet_serial='f9fxk1abcdef'")->fetch_assoc();
$ok($res['state'] === 'pending' && (int) $m['kiosk_asset_id'] === $assetA && $m['kiosk_label'] === 'Fab iPad 1' && $m['kiosk_fleet_note'] === 'matched' && $m['kiosk_status'] === 'pending',
    'require-approval + matching serial: asset bound and named, but still pending');
$ok($m['kiosk_personal_contact_id'] === null, 'no personal-owner snapshot until approval');
$mTok = KioskAuth::newToken();
$q("UPDATE training_kiosks SET kiosk_token_hash='" . KioskAuth::tokenHash($mTok) . "' WHERE kiosk_id=" . (int) $m['kiosk_id']);
// approve (asset taken from the match)
$ap = $fleet->approve((int) $m['kiosk_id'], null, '');
$ok($ap['label'] === 'Fab iPad 1' && !$ap['unlisted'], 'approve binds the matched asset and names the device after it');
$dev = KioskAuth::deviceByTokenHash($db, $ks, KioskAuth::tokenHash($mTok), $reason);
$ok($dev !== null && $reason === null && (int) $dev['kiosk_asset_id'] === $assetA && (int) $dev['kiosk_default_client_id'] === 1, 'after approval the SAME token is a working device bound to the asset and department');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='training.kiosk_fleet_approved'") === 1
    && $one("SELECT kiosk_enrolled_by FROM training_kiosks WHERE kiosk_id=" . (int) $m['kiosk_id']) == 1, 'approval audited and records who approved');
$e = $thrown(fn() => $fleet->approve((int) $m['kiosk_id'], null, ''));
$ok($e !== null && $e->http === 409, 'approving a device twice is refused');

// ---------------------------------------------------------------- approve with an asset: department rule (cross-department refused)
$assetB = add_asset(2, 'Dept B iPad', 'BBBB22223333');
$assetA2 = add_asset(1, 'Fab iPad 2', 'F9FXK2ZZZZ');
$res = $redeem($tok, 'NEWSERIAL001');
$pid = (int) $one("SELECT kiosk_id FROM training_kiosks WHERE kiosk_fleet_serial='NEWSERIAL001'");
$e = $thrown(fn() => $fleet->approve($pid, $assetB, 'x'));
$ok($e !== null && $e->http === 404, 'CROSS-DEPARTMENT: approving onto an asset of another department is refused (404)');
$ok($one("SELECT kiosk_status FROM training_kiosks WHERE kiosk_id=$pid") === 'pending' && $one("SELECT kiosk_asset_id FROM training_kiosks WHERE kiosk_id=$pid") === null, '... and the device is untouched');
// another device already active on assetB must NOT be revoked by the refused call
$q("INSERT INTO training_kiosks SET kiosk_asset_id=$assetB, kiosk_label='B tablet', kiosk_default_client_id=2, kiosk_status='active', kiosk_enroll_method='agent_device', kiosk_token_hash='" . str_repeat('b', 64) . "', kiosk_created_by=1");
$bKiosk = (int) $db->insert_id;
$e = $thrown(fn() => $fleet->approve($pid, $assetB, 'x'));
$ok($e !== null && $one("SELECT kiosk_status FROM training_kiosks WHERE kiosk_id=$bKiosk") === 'active', 'CROSS-DEPARTMENT: the other department\'s active device is NOT revoked by a refused approve');
$q("DELETE FROM training_kiosks WHERE kiosk_id=$bKiosk");
$e = $thrown(fn() => $fleet->approve($pid, 999999, 'x'));
$ok($e !== null && $e->http === 404, 'approve with a missing asset -> 404');
$archived = add_asset(1, 'Old iPad', 'ARCH000111', 'Tablet', true);
$e = $thrown(fn() => $fleet->approve($pid, $archived, 'x'));
$ok($e !== null && $e->http === 404, 'approve with an archived asset -> 404');
$printer = add_asset(1, 'Office printer', 'PRN0001234', 'Printer');
$e = $thrown(fn() => $fleet->approve($pid, $printer, 'x'));
$ok($e !== null && $e->http === 422, 'approve with a non-device asset type -> 422');
$ap = $fleet->approve($pid, $assetA2, '');
$ok($ap['label'] === 'Fab iPad 2' && $one("SELECT kiosk_asset_id FROM training_kiosks WHERE kiosk_id=$pid") == $assetA2, 'approve with a same-department asset binds it');
// unlisted approve with a name
$redeem($tok, 'NEWSERIAL002');
$pid2 = (int) $one("SELECT kiosk_id FROM training_kiosks WHERE kiosk_fleet_serial='NEWSERIAL002'");
$ap = $fleet->approve($pid2, null, "  Loaner   iPad ");
$ok($ap['unlisted'] && $ap['label'] === 'Loaner iPad' && $one("SELECT kiosk_status FROM training_kiosks WHERE kiosk_id=$pid2") === 'active', 'approve without an asset -> active unlisted device with the given (whitespace-normalised) name');
// scoped user cannot approve / reject a Dept B device
$linkB2 = $fleet->create('B link 2', 2, 'require', in_days(5), 5);
$redeem($linkB2['token'], 'BSERIAL0001');
$bpid = (int) $one("SELECT kiosk_id FROM training_kiosks WHERE kiosk_fleet_serial='BSERIAL0001'");
$e = $thrown(fn() => $sf->approve($bpid, null, 'x'));
$ok($e !== null && $e->http === 404, 'a scoped user cannot approve another department\'s pending device');
$e = $thrown(fn() => $sf->reject($bpid));
$ok($e !== null && $e->http === 404, 'a scoped user cannot reject another department\'s pending device');
$ok(count(array_filter($sf->pending(), fn($p) => $p['department'] === 'Dept B')) === 0 && count($sf->pending()) >= 1
    && count(array_filter($fleet->pending(), fn($p) => $p['department'] === 'Dept B')) >= 1, 'pending list is scoped');
// reject
$fleet->reject($bpid);
$ok($one("SELECT kiosk_status FROM training_kiosks WHERE kiosk_id=$bpid") === 'revoked' && $one("SELECT kiosk_token_hash FROM training_kiosks WHERE kiosk_id=$bpid") === null
    && $one("SELECT kiosk_revoke_reason FROM training_kiosks WHERE kiosk_id=$bpid") === 'Fleet enrollment rejected', 'reject revokes the row and clears its token');
$e = $thrown(fn() => $fleet->reject($bpid));
$ok($e !== null && $e->http === 409, 'rejecting twice is refused');
$e = $thrown(fn() => $fleet->approve(999999, null, ''));
$ok($e !== null && $e->http === 404, 'approve of a missing device -> 404');
$agent = $db->query("INSERT INTO training_kiosks SET kiosk_label='per-device', kiosk_default_client_id=1, kiosk_status='pending', kiosk_enroll_method='setup_code', kiosk_created_by=1");
$e = $thrown(fn() => $fleet->approve((int) $db->insert_id, null, ''));
$ok($e !== null && $e->http === 404, 'approve only works on fleet devices (a setup-code row is not one)');
$q("DELETE FROM training_kiosks WHERE kiosk_enroll_method='setup_code'");

// ---------------------------------------------------------------- auto-enroll
$auto = $fleet->create('Auto link', 1, 'auto_match', in_days(10), 10);
$assetA3 = add_asset(1, 'Auto iPad', 'AUTO000001');
$res = $redeem($auto['token'], 'AUTO000001');
$a = $db->query("SELECT * FROM training_kiosks WHERE kiosk_fleet_serial='AUTO000001'")->fetch_assoc();
$ok($res['state'] === 'active' && $a['kiosk_status'] === 'active' && (int) $a['kiosk_asset_id'] === $assetA3 && $a['kiosk_label'] === 'Auto iPad' && $a['kiosk_enrolled_at_utc'] !== null && $a['kiosk_fleet_note'] === 'auto',
    'auto-enroll + serial matches an active Asset -> active at once, bound, named');
$ok((int) $one("SELECT COUNT(*) FROM training_events WHERE tevent_type='kiosk.enrolled' AND tevent_kiosk_id=" . (int) $a['kiosk_id']) === 1, 'auto-enroll writes kiosk.enrolled');
$aTok = KioskAuth::newToken();
$q("UPDATE training_kiosks SET kiosk_token_hash='" . KioskAuth::tokenHash($aTok) . "' WHERE kiosk_id=" . (int) $a['kiosk_id']);
$dev = KioskAuth::deviceByTokenHash($db, $ks, KioskAuth::tokenHash($aTok), $reason);
$ok($dev !== null && $reason === null, 'an auto-enrolled device is usable immediately');
$_COOKIE[KioskAuth::DEV_COOKIE] = $aTok;
$res = $redeem($auto['token'], 'AUTO000001', $dev);
$ok($res['state'] === 'enrolled' && !empty($res['already']) && (int) $one("SELECT fleet_use_count FROM training_fleet_links WHERE fleet_id=" . $auto['fleet_id']) === 1, 're-opening the link on an enrolled tablet changes nothing');
$_COOKIE = [];
// auto-enroll, no match -> still pending
$res = $redeem($auto['token'], 'NOPE0000001');
$ok($res['state'] === 'pending', 'auto-enroll but no matching asset -> pending');
// auto-enroll, plain link -> pending
$res = $redeem($auto['token'], null);
$ok($res['state'] === 'pending', 'auto-enroll plain link (no serial) -> pending');
// auto-enroll, asset already has an active device -> pending, note asset_in_use, existing device untouched
$q("INSERT INTO training_kiosks SET kiosk_asset_id=" . add_asset(1, 'Busy iPad', 'BUSY000001') . ", kiosk_label='Busy', kiosk_default_client_id=1, kiosk_status='active', kiosk_enroll_method='agent_device', kiosk_token_hash='" . str_repeat('c', 64) . "', kiosk_created_by=1");
$busyKiosk = (int) $db->insert_id;
$res = $redeem($auto['token'], 'BUSY000001');
$ok($res['state'] === 'pending' && $one("SELECT kiosk_fleet_note FROM training_kiosks WHERE kiosk_fleet_serial='BUSY000001'") === 'asset_in_use' && $one("SELECT kiosk_status FROM training_kiosks WHERE kiosk_id=$busyKiosk") === 'active',
    'auto-enroll never takes an asset over from an active device: pending + asset_in_use');
// auto-enroll: cross-department serial must not match
$res = $redeem($auto['token'], 'BBBB22223333');
$x = $db->query("SELECT * FROM training_kiosks WHERE kiosk_fleet_serial='BBBB22223333'")->fetch_assoc();
$ok($res['state'] === 'pending' && $x['kiosk_asset_id'] === null && $x['kiosk_fleet_note'] === 'other_department' && (int) $x['kiosk_default_client_id'] === 1,
    'CROSS-DEPARTMENT: a serial that belongs to another department\'s asset is NOT matched on the public path (pending, unbound)');
// archived / non-device / duplicate serials
add_asset(1, 'Retired', 'ARCH555555', 'Tablet', true);
$res = $redeem($auto['token'], 'ARCH555555');
$ok($res['state'] === 'pending' && $one("SELECT kiosk_fleet_note FROM training_kiosks WHERE kiosk_fleet_serial='ARCH555555'") === 'no_match', 'an archived asset is not matched');
add_asset(1, 'Label printer', 'PRN5555555', 'Printer');
$res = $redeem($auto['token'], 'PRN5555555');
$ok($res['state'] === 'pending' && $one("SELECT kiosk_fleet_note FROM training_kiosks WHERE kiosk_fleet_serial='PRN5555555'") === 'no_match', 'a non-device asset type is not matched');
add_asset(1, 'Dup 1', 'DUPDUP0001'); add_asset(1, 'Dup 2', 'DUPDUP0001');
$res = $redeem($auto['token'], 'DUPDUP0001');
$ok($res['state'] === 'pending' && $one("SELECT kiosk_fleet_note FROM training_kiosks WHERE kiosk_fleet_serial='DUPDUP0001'") === 'ambiguous', 'two assets with the same serial -> ambiguous, never auto-enrolled');

// ---------------------------------------------------------------- max uses, expiry, revoked, rotate
$cap = $fleet->create('Two only', 1, 'require', in_days(5), 2);
$redeem($cap['token'], 'CAP00000001'); $redeem($cap['token'], 'CAP00000002');
$e = $thrown(fn() => $redeem($cap['token'], 'CAP00000003'));
$ok($e !== null && $e->errCode === 'fleet_invalid' && $one("SELECT COUNT(*) FROM training_kiosks WHERE kiosk_fleet_serial='CAP00000003'") == 0, 'max uses reached -> 403 fleet_invalid, nothing created');
$ok($fleet->listLinks()[0]['state'] === 'used_up' || array_values(array_filter($fleet->listLinks(), fn($l) => $l['id'] === $cap['fleet_id']))[0]['state'] === 'used_up', 'link state used_up');
$q("DELETE FROM training_rate_buckets");
$ex = $fleet->create('Expiring', 1, 'require', in_days(5), 5);
$q("UPDATE training_fleet_links SET fleet_expires_at_utc='" . KTime::plus(-60) . "' WHERE fleet_id=" . $ex['fleet_id']);
$e = $thrown(fn() => $redeem($ex['token'], 'EXP00000001'));
$ok($e !== null && $e->errCode === 'fleet_invalid', 'expired link -> 403 fleet_invalid');
$ok(array_values(array_filter($fleet->listLinks(), fn($l) => $l['id'] === $ex['fleet_id']))[0]['state'] === 'expired', 'link state expired');
$e = $thrown(fn() => $fleet->mobileconfig($ex['fleet_id'], $ex['token'], '%SerialNumber%', 'Training'));
$ok($e === null, 'an expired (not revoked) link can still be exported - the admin already holds the token');
$rv = $fleet->create('To revoke', 1, 'require', in_days(5), 5);
$redeem($rv['token'], 'REV00000001');
$fleet->revoke($rv['fleet_id'], 'testing revoke');
$e = $thrown(fn() => $redeem($rv['token'], 'REV00000002'));
$ok($e !== null && $e->errCode === 'fleet_invalid', 'revoked link -> 403 fleet_invalid');
$ok($one("SELECT kiosk_status FROM training_kiosks WHERE kiosk_fleet_serial='REV00000001'") === 'pending', 'revoking a link leaves devices already enrolled alone');
$e = $thrown(fn() => $fleet->revoke($rv['fleet_id'], 'again again'));
$ok($e !== null && $e->http === 409, 'revoking twice is refused');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='training.kiosk_fleet_refused'") >= 3, 'use of a dead link is audited');
$e = $thrown(fn() => $fleet->mobileconfig($rv['fleet_id'], $rv['token'], '%SerialNumber%', 'Training'));
$ok($e !== null && $e->http === 409, 'no Web Clip for a revoked link');
$q("DELETE FROM training_rate_buckets");
$rot = $fleet->rotate($auto['fleet_id']);
$e = $thrown(fn() => $redeem($auto['token'], 'ROT00000001'));
$ok($e !== null && $e->errCode === 'fleet_invalid', 'rotate: the old token stops working');
$ok($redeem($rot['token'], 'ROT00000001')['state'] === 'pending', 'rotate: the new token works');
$nl = $db->query('SELECT * FROM training_fleet_links WHERE fleet_id=' . $rot['fleet_id'])->fetch_assoc();
$ok($nl['fleet_label'] === 'Auto link' && $nl['fleet_approval'] === 'auto_match' && (int) $nl['fleet_max_uses'] === 10 && (int) $nl['fleet_client_id'] === 1 && (int) $nl['fleet_use_count'] === 1, 'rotate keeps name, department, approval and cap; fresh use count');
$ok($one('SELECT fleet_revoke_reason FROM training_fleet_links WHERE fleet_id=' . $auto['fleet_id']) === 'Rotated', 'rotate revokes the old link as "Rotated"');
// pause switch refuses everything
$q("UPDATE settings SET config_training_enroll_pause_until_utc='" . KTime::plus(600) . "' WHERE company_id=1");
$e = $thrown(fn() => $redeem($rot['token'], 'PAUSE000001'));
$ok($e !== null && $e->http === 429 && $e->errCode === 'enroll_paused', 'the global enrollment pause also stops fleet enrollment');
$q("UPDATE settings SET config_training_enroll_pause_until_utc=NULL WHERE company_id=1");

// ---------------------------------------------------------------- Apple Web Clip profile
$wc = $fleet->create('Web clip link', 1, 'require', in_days(5), 5);
$icon = file_get_contents($root . '/kiosk/icons/apple-touch-icon.png');
$p1 = $fleet->mobileconfig($wc['fleet_id'], $wc['token'], '%SerialNumber%', 'Training');
$p2 = $fleet->mobileconfig($wc['fleet_id'], $wc['token'], '$SERIALNUMBER', 'Training');
$xml = $p1['content'];
$dom = new DOMDocument();
$ok(@$dom->loadXML($xml) && $dom->documentElement->nodeName === 'plist', 'profile is well-formed plist XML');
function plist_val(DOMElement $dict, string $key): ?DOMElement { foreach ($dict->childNodes as $n) { if ($n instanceof DOMElement && $n->nodeName === 'key' && $n->textContent === $key) { $v = $n->nextSibling; while ($v && !($v instanceof DOMElement)) { $v = $v->nextSibling; } return $v; } } return null; }
$top = $dom->documentElement->getElementsByTagName('dict')->item(0);
$clip = plist_val($top, 'PayloadContent')->getElementsByTagName('dict')->item(0);
$ok(plist_val($clip, 'PayloadType')->textContent === 'com.apple.webClip.managed' && plist_val($top, 'PayloadType')->textContent === 'Configuration', 'web clip payload type and Configuration wrapper');
$ok(plist_val($clip, 'FullScreen')->nodeName === 'true' && plist_val($clip, 'IsRemovable')->nodeName === 'false', 'Full Screen, not removable');
$ok(plist_val($clip, 'URL')->textContent === $base . '/kiosk/?e=' . $wc['token'] . '&sn=%SerialNumber%', 'URL carries the token and the serial placeholder text (and the & survives XML escaping)');
$ok(strpos(plist_val($clip, 'URL')->textContent, '%SerialNumber%') !== false && strpos((string) plist_val($clip, 'URL')->textContent, '&amp;') === false, 'placeholder is literal text, not expanded or double-escaped');
$ok(base64_decode(preg_replace('/\s+/', '', plist_val($clip, 'Icon')->textContent), true) === $icon, 'icon is the kiosk apple-touch-icon.png, embedded as base64');
$uuidRe = '/^[0-9A-F]{8}-[0-9A-F]{4}-5[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/D';
$u1 = plist_val($clip, 'PayloadUUID')->textContent; $u2 = plist_val($top, 'PayloadUUID')->textContent;
$ok(preg_match($uuidRe, $u1) === 1 && preg_match($uuidRe, $u2) === 1 && $u1 !== $u2, 'PayloadUUIDs are valid RFC 4122 UUIDs and the two payloads differ');
$d2 = new DOMDocument(); $d2->loadXML($p2['content']);
$top2 = $d2->documentElement->getElementsByTagName('dict')->item(0);
$ok(plist_val($top2, 'PayloadUUID')->textContent === $u2, 'UUIDs are stable for a link (re-pushing updates the profile instead of adding a second)');
$ok(strpos($p2['content'], 'sn=$SERIALNUMBER') !== false, 'another MDM macro is used verbatim');
$ok(plist_val($top, 'PayloadIdentifier')->textContent === 'com.rivetit.kiosk.fleet.' . $wc['fleet_id'] && $p1['filename'] === 'training-kiosk-web-clip-link.mobileconfig', 'identifier and filename');
$other = $fleet->create('Other', 1, 'require', in_days(5), 5);
$d3 = new DOMDocument(); $d3->loadXML($fleet->mobileconfig($other['fleet_id'], $other['token'], '%SerialNumber%', 'Training')['content']);
$ok(plist_val($d3->documentElement->getElementsByTagName('dict')->item(0), 'PayloadUUID')->textContent !== $u2, 'a different link gets different UUIDs');
foreach (['wrong token' => [$wc['fleet_id'], FleetLinks::TOKEN_PREFIX . str_repeat('z', 43), '%SerialNumber%', 'Training', 404],
          'token of another link' => [$wc['fleet_id'], $other['token'], '%SerialNumber%', 'Training', 404],
          'no token' => [$wc['fleet_id'], null, '%SerialNumber%', 'Training', 404],
          'bad macro' => [$wc['fleet_id'], $wc['token'], 'a b<script>', 'Training', 422],
          'empty name' => [$wc['fleet_id'], $wc['token'], '%SerialNumber%', ' ', 422]] as $why => [$id, $t, $ph, $nm, $code]) {
    $e = $thrown(fn() => $fleet->mobileconfig($id, $t, $ph, $nm));
    $ok($e !== null && $e->http === $code, "profile refused: $why");
}
$x = $fleet->mobileconfig($wc['fleet_id'], $wc['token'], '%SerialNumber%', 'Fab <b>"iPads"</b> & co')['content'];
$d4 = new DOMDocument();
$ok(@$d4->loadXML($x) && strpos($x, '<b>') === false, 'a hostile icon name is XML-escaped (profile still well-formed)');
$e = $thrown(fn() => $sf->mobileconfig($linkB2['fleet_id'], $linkB2['token'], '%SerialNumber%', 'Training'));
$ok($e !== null && $e->http === 404, 'a scoped user cannot build a profile for another department\'s link');

// ---------------------------------------------------------------- per-device URL export
add_asset(1, 'Exp =cmd', 'EXPORT00001'); add_asset(1, 'Exp 2', 'EXPORT00002', 'Phone'); add_asset(1, 'Bad serial', 'has space 1'); add_asset(1, 'No serial', null);
add_asset(1, 'Archived', 'EXPORT00003', 'Tablet', true); add_asset(2, 'Other dept', 'EXPORT00004'); add_asset(1, 'A printer', 'EXPORT00005', 'Printer');
$rows = $fleet->exportRows($wc['fleet_id'], $wc['token']);
$serials = array_column($rows, 'serial');
$ok(in_array('EXPORT00001', $serials, true) && in_array('EXPORT00002', $serials, true), 'export lists the department\'s tablets and phones');
$ok(!in_array('EXPORT00003', $serials, true) && !in_array('EXPORT00004', $serials, true) && !in_array('EXPORT00005', $serials, true) && !in_array('has space 1', $serials, true), 'export leaves out archived, other-department, non-device and unusable-serial assets');
$ok($rows[array_search('EXPORT00001', $serials, true)]['url'] === $base . '/kiosk/?e=' . $wc['token'] . '&sn=EXPORT00001', 'each row is the fleet URL with THAT serial');
$e = $thrown(fn() => $fleet->exportRows($wc['fleet_id'], 'nope'));
$ok($e !== null && $e->http === 404, 'export needs the once-shown token');
// the exported URL redeems
$ok($redeem($wc['token'], 'EXPORT00001')['state'] === 'pending', 'an exported per-device URL redeems');

// ---------------------------------------------------------------- redeem as an active device never creates anything
$before = (int) $one('SELECT COUNT(*) FROM training_kiosks');
$res = $redeem($wc['token'], 'SOMETHING01', ['kiosk_label' => 'x', 'kiosk_id' => 1]);
$ok($res['state'] === 'enrolled' && (int) $one('SELECT COUNT(*) FROM training_kiosks') === $before, 'a browser that already has a working device is left alone');

// ---------------------------------------------------------------- the kiosk entry over real HTTP: /kiosk/?e=... -> 302 to the fragment form
$q("UPDATE settings SET config_module_enable_training = 1 WHERE company_id = 1");
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) { $p = random_int(20000, 60000); $s = @stream_socket_server("tcp://127.0.0.1:$p"); if ($s) { fclose($s); $port = $p; } }
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) { usleep(100000); $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); $up = true; } }
$head = function (string $path) use ($port): array {
    $c = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Host: scratch.local']]);
    $out = (string) curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$code, $out];
};
if ($up) {
    [$code, $out] = $head('/kiosk/?e=' . $wc['token'] . '&sn=ABC12345678');
    $ok($code === 302 && stripos($out, 'Location: /kiosk/#e=' . $wc['token'] . '&sn=ABC12345678') !== false, '/kiosk/?e=<token>&sn=<serial> answers a bare 302 to /kiosk/#e=...&sn=... (token in the fragment, not the page)');
    $ok(stripos($out, '<html') === false, '... with no page body');
    [$code, $out] = $head('/kiosk/?e=' . $wc['token']);
    $ok($code === 302 && stripos($out, 'Location: /kiosk/#e=' . $wc['token']) !== false && stripos($out, '&sn=') === false, 'plain link redirects without a serial');
    [$code, $out] = $head('/kiosk/?e=garbage&sn=1');
    $ok($code === 302 && stripos($out, 'Location: /kiosk/' . "\r") !== false, 'a malformed ?e= goes home without echoing it');
    [$code, $out] = $head('/kiosk/?e=' . $wc['token'] . '&sn=' . rawurlencode('<script>alert(1)</script>'));
    $ok($code === 302 && stripos($out, '<script>') === false && stripos($out, '%3Cscript%3E') !== false, 'a hostile sn is percent-encoded in the redirect');
} else {
    $ok(false, 'could not start php -S for the redirect checks');
}
if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }

// ---------------------------------------------------------------- repo templates carry the same hop for nginx
foreach (['deploy/templates/nginx-vhost.conf.template', 'docker/nginx.conf'] as $f) {
    $t = (string) file_get_contents("$root/$f");
    $ok(strpos($t, 'rvkf1_[A-Za-z0-9_-]{43}') !== false && strpos($t, 'return 302 "/kiosk/#e=$1&sn=$2";') !== false, "$f redirects /kiosk/?e= to the fragment without logging");
}

reset_all();
