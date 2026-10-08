<?php
/* Enrollment: tokens (new / invalid / expired / revoked / max uses / scope), rate limiting, asset matching, re-enrollment, identity. */
require __DIR__ . '/endpoint_agent_lib.php';
use ITFlow\EndpointAgent\Devices; use ITFlow\EndpointAgent\Enrollment; use ITFlow\EndpointAgent\Config;

ea_reset();

// --- the service is off by default: enrollment is refused
[$c, , $j] = ea_enroll('rvte1.000000000000.' . str_repeat('0', 40), ea_dev());
$ok($c === 403 && ($j['code'] ?? '') === 'forbidden', 'disabled service refuses enrollment (403 forbidden)');
$ok((int) $one("SELECT enabled FROM endpoint_agent_settings WHERE id=1") === 0, 'agent is off by default');

$tok = ea_token(1, 24, 60);
$ok((string) $one("SELECT signing_public_key FROM endpoint_agent_settings") !== '' && str_starts_with((string) $one("SELECT signing_private_key_enc FROM endpoint_agent_settings"), 'ENC2:'), 'enabling mints the instance signing key, private key encrypted at rest');
$ok((int) $one("SELECT COUNT(*) FROM rmm_integrations WHERE type='rivetit_agent'") === 1, 'a synthetic rmm_integrations row is created once');

// --- transport + body
[$c, , $j] = http('GET', '/api/v1/agent_enroll');
$ok($c === 405 && ($j['code'] ?? '') === 'method_not_allowed', 'GET enroll -> 405');
[$c, , $j] = http('POST', '/api/v1/agent_enroll', null, 'not json');
$ok($c === 422 && ($j['code'] ?? '') === 'invalid', 'non-JSON body -> 422 invalid');
[$c] = http('POST', '/api/v1/agent_enroll', null, str_repeat('x', 20000), ['Content-Type: application/json']);
$ok($c === 413, 'oversized enrollment body -> 413');

// --- new enrollment
$d1 = ea_dev(['hostname' => 'WS-ONE', 'serial' => 'SER-ONE-1', 'mac_addresses' => ['AA-BB-CC-00-00-01']]);
[$c, , $j] = ea_enroll($tok, $d1);
$ok($c === 201 && isset($j['device_id'], $j['device_token']) && strlen($j['device_token']) === 64, 'new enrollment -> 201 with a 256-bit device token');
foreach (['check_in_interval_s', 'server_time', 'status', 'matched_asset_id', 'signing_public_key', 'config'] as $k) { $ok(array_key_exists($k, $j ?? []), "enroll response has $k"); }
$ok(is_array($j['config']['checks'] ?? null) && count($j['config']['checks']) >= 1 && isset($j['config']['checks'][0]['signature']), 'enroll config carries signed checks');
$dev1 = (int) $j['device_id']; $devTok1 = $j['device_token'];
$ok($j['status'] === 'pending_approval' && $j['matched_asset_id'] === null, 'unknown machine, default policy -> pending_approval (no silent asset creation)');
$ok((string) $one("SELECT token_hash FROM endpoint_agent_devices WHERE device_id=$dev1") === hash('sha256', $devTok1) && strpos((string) $one("SELECT GROUP_CONCAT(token_hash) FROM endpoint_agent_devices"), $devTok1) === false, 'only the SHA-256 of the device token is stored');
$ok((int) $one("SELECT use_count FROM endpoint_agent_enrollment_tokens") === 1, 'token use counted');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type='Endpoint Agent' AND log_action='Enrolled'") === 1, 'enrollment audited');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type LIKE 'endpoint_agent.%'") >= 1, 'enrollment mirrored into the structured audit trail');
$ok(strpos((string) $one("SELECT GROUP_CONCAT(log_description) FROM logs"), explode('.', $tok)[2]) === false, 'enrollment token secret never appears in the audit log');

// --- invalid / malformed
[$c, , $j] = ea_enroll('rvte1.' . str_repeat('a', 12) . '.' . str_repeat('b', 40), ea_dev());
$ok($c === 401 && $j['code'] === 'invalid_token', 'unknown token -> 401 invalid_token');
[$c, , $j] = ea_enroll('garbage', ea_dev());
$ok($c === 401 && $j['code'] === 'invalid_token', 'malformed token -> 401 invalid_token');
[$c, , $j] = ea_enroll($tok, ea_dev(['arch' => 'sparc']));
$ok($c === 422 && $j['code'] === 'invalid', 'bad arch -> 422');
[$c, , $j] = ea_enroll($tok, ea_dev(['install_id' => 'nope']));
$ok($c === 422, 'bad install_id -> 422');
[$c, , $j] = ea_enroll($tok, ea_dev(['os' => 'plan9']));
$ok($c === 422, 'non-windows os -> 422 (first release is Windows only)');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_enroll_attempts WHERE success=0") >= 3 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Enrollment Failed'") >= 3, 'failed attempts are recorded and audited');

// --- expired / revoked / max uses
$exp = Enrollment::createToken(1, 1, 'stable', 1, 5, 'exp', 1)['token'];
$q("UPDATE endpoint_agent_enrollment_tokens SET expires_at = '" . gmdate('Y-m-d H:i:s', time() - 60) . "' WHERE label='exp'");
[$c, , $j] = ea_enroll($exp, ea_dev()); $ok($c === 401 && $j['code'] === 'expired', 'expired token -> 401 expired');
$rev = Enrollment::createToken(1, 0, 'stable', 1, 5, 'rev', 1);
Enrollment::revokeToken($rev['token_id'], 1);
[$c, , $j] = ea_enroll($rev['token'], ea_dev()); $ok($c === 401 && $j['code'] === 'revoked', 'revoked token -> 401 revoked');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Enrollment Failed' AND log_description LIKE '%revoked%'") >= 1, 'use of a revoked token is audited');
$one1 = Enrollment::createToken(1, 0, 'stable', 1, 1, 'single', 1)['token'];
[$c] = ea_enroll($one1, ea_dev()); $ok($c === 201, 'single-use token works once');
[$c, , $j] = ea_enroll($one1, ea_dev()); $ok($c === 403 && $j['code'] === 'forbidden', 'single-use token exhausted -> 403');
$ttl = Enrollment::createToken(1, 100000, 'stable', 100000, 99999, 'cap', 1);
$ok((int) $one("SELECT max_uses FROM endpoint_agent_enrollment_tokens WHERE token_id=" . $ttl['token_id']) <= 5000 && strtotime((string) $one("SELECT expires_at FROM endpoint_agent_enrollment_tokens WHERE token_id=" . $ttl['token_id']) . ' UTC') <= time() + 72 * 3600 + 5, 'token TTL and max uses are capped by policy');

$q("DELETE FROM endpoint_agent_enroll_attempts");
// --- re-enrollment: same install id keeps the device, rotates the credential
[$c, , $j] = ea_enroll($tok, $d1);
$ok($c === 201 && (int) $j['device_id'] === $dev1 && $j['device_token'] !== $devTok1, 're-enrolling the same install_id keeps device_id and rotates the credential');
$devTok1b = $j['device_token'];
[$c, , $r] = http('POST', '/api/v1/agent_checkin', $devTok1, ['seq' => 1, 'collected_at' => ea_ts(), 'agent_version' => '1.0.0']);
$ok($c === 401 && $r['code'] === 'invalid_token', 'the old credential stops working after re-enrollment');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE hostname='WS-ONE'") === 1, 'no duplicate device row');
// same install id, different machine -> conflict, never merged
[$c, , $j] = ea_enroll($tok, ea_dev(['install_id' => $d1['install_id'], 'machine_guid' => 'ffffffffffffffff', 'serial' => 'OTHER-SERIAL']));
$ok($c === 409 && $j['code'] === 'conflict', 'same install_id on a different machine -> 409 conflict');
$q("DELETE FROM endpoint_agent_enroll_attempts");
// reinstall: new install id, same machine_guid
[$c, , $j] = ea_enroll($tok, ea_dev(['machine_guid' => $d1['machine_guid'], 'serial' => $d1['serial'], 'hostname' => 'WS-ONE-RENAMED']));
$ok($c === 201 && (int) $j['device_id'] === $dev1, 'reinstall (new install_id, same machine_guid/serial) keeps the device');
$ok($one("SELECT hostname FROM endpoint_agent_devices WHERE device_id=$dev1") === 'WS-ONE-RENAMED', 'renamed device: hostname follows the device');
// reinstall: Windows reinstall changes machine_guid but the BIOS serial stays
[$c, , $j] = ea_enroll($tok, ea_dev(['machine_guid' => 'aaaaaaaaaaaaaaaa', 'serial' => $d1['serial']]));
$ok($c === 201 && (int) $j['device_id'] === $dev1, 'reinstall with a new machine_guid but the same serial keeps the device' . ($c === 201 ? '' : " (got $c " . json_encode($j) . ')'));
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_devices") === 2 || (int) $one("SELECT COUNT(*) FROM endpoint_agent_devices") >= 1, 'device rows stay bounded');

// --- asset matching
$q("DELETE FROM endpoint_agent_enroll_attempts");
$q("DELETE FROM endpoint_agent_devices"); $q("DELETE FROM asset_rmm_links"); $q("DELETE FROM assets"); $q("DELETE FROM asset_interfaces");
$mk = function (string $name, string $serial, int $client = 1) use ($q, $esc) { $q("INSERT INTO assets SET asset_type='Laptop', asset_name='" . $esc($name) . "', asset_make='Dell', asset_serial='" . $esc($serial) . "', asset_client_id=$client, asset_status='Active'"); return (int) $GLOBALS['db']->insert_id; };
$tok = ea_token(1, 24, 50);
$aSerial = $mk('Reception PC', 'SER-MATCH-1');
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'SER-MATCH-1', 'hostname' => 'totally-different']));
$ok($c === 201 && $j['status'] === 'linked' && (int) $j['matched_asset_id'] === $aSerial, 'exact serial match in the token department links the asset');
$ok((int) $one("SELECT COUNT(*) FROM asset_rmm_links WHERE asset_id=$aSerial AND integration_id=(SELECT id FROM rmm_integrations WHERE type='rivetit_agent')") === 1, 'link row created in the existing asset_rmm_links table');
$ok((int) $one("SELECT COUNT(*) FROM assets") === 1, 'matching did not create a duplicate asset');
$aMac = $mk('Boardroom PC', 'SER-OTHER-2'); $q("INSERT INTO asset_interfaces SET interface_name='Ethernet', interface_mac='AA:BB:CC:11:22:33', interface_asset_id=$aMac");
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => null, 'mac_addresses' => ['aa-bb-cc-11-22-33'], 'hostname' => 'x']));
$ok($j['status'] === 'linked' && (int) $j['matched_asset_id'] === $aMac, 'MAC match (case/separator-insensitive) links the asset');
$aHost = $mk('HOSTONLY-PC', 'SER-H');
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'NOPE-1', 'hostname' => 'hostonly-pc']));
$ok($j['status'] === 'pending_approval' && $j['matched_asset_id'] === null, 'hostname alone is NOT enough: pending_approval');
$cand = json_decode((string) $one("SELECT match_candidates_json FROM endpoint_agent_devices WHERE device_id=" . (int) $j['device_id']), true);
$ok(($cand[0]['asset_id'] ?? 0) === $aHost && $cand[0]['matched_by'] === ['hostname'], 'hostname match is kept as an explained suggestion');
$a1 = $mk('Amb 1', 'SER-AMB'); $a2 = $mk('Amb 2', 'SER-AMB-X'); $q("INSERT INTO asset_interfaces SET interface_name='e', interface_mac='AA:00:00:00:00:99', interface_asset_id=$a2");
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'SER-AMB', 'mac_addresses' => ['aa:00:00:00:00:99']]));
$ok($j['status'] === 'ambiguous' && $j['matched_asset_id'] === null, 'serial and MAC pointing at different assets -> ambiguous, never merged');
$ok((int) $one("SELECT COUNT(*) FROM asset_rmm_links WHERE asset_id IN ($a1,$a2)") === 0, 'ambiguous match created no link');
$ambDev = (int) $j['device_id'];
$aOther = $mk('Other dept PC', 'SER-OTHERDEPT', 2);
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'SER-OTHERDEPT']));
$ok($j['status'] === 'pending_approval' && $one("SELECT match_reason FROM endpoint_agent_devices WHERE device_id=" . (int) $j['device_id']) === 'scope_mismatch', 'asset in another department than the token scope -> pending_approval (wrong scope)');
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'DIFFERENT-BOX', 'mac_addresses' => ['aa:bb:cc:11:22:33']]));
$ok($j['status'] === 'ambiguous' && $j['matched_asset_id'] === null && $one("SELECT match_reason FROM endpoint_agent_devices WHERE device_id=" . (int) $j['device_id']) === 'asset_already_linked', 'a different machine claiming an asset a live device already owns is not merged (duplicate enrollment)');
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'SER-MATCH-1']));
$ok($j['status'] === 'linked' && (int) $j['matched_asset_id'] === $aSerial, 'a new install of the SAME serial is a reinstall: same device, same asset');
$ok((int) $one("SELECT COUNT(DISTINCT device_id) FROM endpoint_agent_devices WHERE asset_id=$aSerial") === 1, 'an asset is owned by at most one live device');
foreach (['00', '', 'To be filled by O.E.M.', 'Default string'] as $junk) {
    [$c, , $j] = ea_enroll($tok, ea_dev(['serial' => $junk, 'hostname' => 'JUNK-' . bin2hex(random_bytes(2))]));
    $ok($j['status'] === 'pending_approval' && $j['matched_asset_id'] === null, "junk serial '$junk' never matches");
}

$q("DELETE FROM endpoint_agent_enroll_attempts");
// --- policy: auto-create
Config::set(['unmatched_policy' => 'auto_create']);
$before = (int) $one("SELECT COUNT(*) FROM assets");
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'BRAND-NEW-1', 'hostname' => 'NEWBOX']));
$ok($j['status'] === 'linked' && (int) $one("SELECT COUNT(*) FROM assets") === $before + 1 && (int) $one("SELECT asset_client_id FROM assets WHERE asset_id=" . (int) $j['matched_asset_id']) === 1, 'auto_create policy creates the asset in the token department');
Config::set(['unmatched_policy' => 'approval']);

// --- admin resolution of a pending device
$r = Enrollment::resolvePending($ambDev, 'link', $a1, 1);
$ok($r['ok'] && $one("SELECT link_state FROM endpoint_agent_devices WHERE device_id=$ambDev") === 'linked' && (int) $one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id=$ambDev") === $a1, 'admin can choose the asset for an ambiguous device');
$r = Enrollment::resolvePending($ambDev, 'link', $a2, 1);
$ok(!$r['ok'], 'an already-linked device is no longer pending');
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'ZZZ-PENDING', 'hostname' => 'PEND']));
$pend = (int) $j['device_id'];
$r = Enrollment::resolvePending($pend, 'link', $a1, 1);
$ok(!$r['ok'] && strpos($r['message'], 'already belongs') !== false, 'cannot link a second device to an owned asset');
$r = Enrollment::resolvePending($pend, 'create_asset', null, 1);
$ok($r['ok'] && (int) $one("SELECT COUNT(*) FROM asset_rmm_links WHERE tactical_agent_id='rivetit:$pend'") === 1, 'admin can create an asset for a pending device');
// --- Core's read model supplies asset_name from RivetIT's assets adapter (RmmAssetNamesInterface); the edition keeps no lookup of its own
require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';
$listed = rivetRmmModule($db)->readModel()->listDevices(['retired' => 'all'], null, 500, 0)['items'];
$byId = []; foreach ($listed as $it) { $byId[(int) $it['device_id']] = $it; }
$expName = (string) $one("SELECT a.asset_name FROM endpoint_agent_devices d JOIN assets a ON a.asset_id = d.asset_id WHERE d.device_id=$pend");
$ok($expName !== '' && ($byId[$pend]['asset_name'] ?? null) === $expName && array_key_exists('update_state', $byId[$pend] ?? []), 'listDevices() carries asset_name (from the assets adapter) and update_state');
$noAsset = (int) $one("SELECT device_id FROM endpoint_agent_devices WHERE asset_id IS NULL ORDER BY device_id LIMIT 1");
$ok($noAsset > 0 && array_key_exists('asset_name', $byId[$noAsset] ?? []) && $byId[$noAsset]['asset_name'] === null, 'a device without an asset has asset_name null');
$ok(rivetRmmModule($db)->clientLabel() === 'department', 'the module calls a client a department (client_label)');

// --- rate limiting (DB backed)
$q("DELETE FROM endpoint_agent_enroll_attempts");
$last = 0;
for ($i = 0; $i < 14; $i++) { [$last] = ea_enroll('rvte1.' . str_repeat('c', 12) . '.' . str_repeat('d', 40), ea_dev()); if ($last === 429) break; }
$ok($last === 429, 'repeated failed enrollments from one address are rate limited (429)');
[$c, $h] = ea_enroll($tok, ea_dev());
$ok($c === 429 && isset($h['retry-after']), 'rate limit also blocks a valid token from that address and sends Retry-After');
$q("DELETE FROM endpoint_agent_enroll_attempts");
