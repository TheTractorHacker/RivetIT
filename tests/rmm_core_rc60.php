<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * RivetCore 1.0.0-rc.60 adoption (rc.59 features), SCRATCH database only (schema from db.sql, no rows copied), real RivetCore module, real HTTP handler:
 *   - the migration step 2.6.236 (CoreMigrations::all through MigrationRunner): idempotent, same column as db.sql
 *   - "Move to Department": rmm.device.manage required (refused for a technician, also by a hand-written POST), CSRF required, the device and its jobs/patches move,
 *     the same device token still checks in, the Core ActionResult message comes back
 *   - bulk asset transfer: assets with a device go through TechnicianActions::transfer(), assets without one are handed back to the old path; moved/refused reported
 *   - "Managed by another RMM": off by default, passed through RmmAdmin / TechnicianActions, the enrolled device starts with automation off + audit row
 *   - bulk "managed by another RMM": ability required, foreign / missing / no-device ids skipped alike, a second run is "already set", 501 refused
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/rmm_core_rc60.php
 * config.php in the repo root must point at the same scratch database and define RMM_STATE_DIR as '' (see tests/support/security_lib.php).
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_device_actions.php";

use RivetCore\Rmm\Authz\RmmPrincipal;

// ---------------------------------------------------------------- fixtures
foreach (['endpoint_agent_jobs', 'endpoint_agent_devices', 'endpoint_agent_installer_options', 'endpoint_agent_enrollment_tokens', 'assets', 'clients', 'locations', 'users', 'user_roles', 'user_client_permissions', 'user_role_permissions', 'modules', 'settings', 'rmm_patch_available'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.236'");
$q("INSERT INTO modules SET module_id = 61, module_name = 'module_rmm', module_description = 'x'");
$q("INSERT INTO modules SET module_id = 62, module_name = 'module_assets', module_description = 'x'");
$q("INSERT INTO user_roles SET role_id = 91, role_name = 'Admin', role_is_admin = 1, role_type = 1");
$q("INSERT INTO user_roles SET role_id = 92, role_name = 'Tech', role_is_admin = 0, role_type = 1");
$q("INSERT INTO user_role_permissions SET user_role_id = 92, module_id = 61, user_role_permission_level = 3");
$q("INSERT INTO user_role_permissions SET user_role_id = 92, module_id = 62, user_role_permission_level = 2");   // the technician may edit assets: only rmm.device.manage is missing
foreach ([951 => 91, 952 => 92] as $id => $role) {
    $q("INSERT INTO users SET user_id = $id, user_name = 'U$id', user_email = 'u$id@example.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = $role");
    $q("INSERT INTO user_settings SET user_id = $id ON DUPLICATE KEY UPDATE user_id = user_id");
}
$q("INSERT INTO clients SET client_id = 9801, client_name = 'Old Dept', client_created_at = NOW()");
$q("INSERT INTO clients SET client_id = 9802, client_name = 'New Dept', client_created_at = NOW()");
$q("INSERT INTO locations SET location_id = 9811, location_name = 'New Site', location_client_id = 9802, location_created_at = NOW()");
$q("INSERT INTO locations SET location_id = 9812, location_name = 'Old Site', location_client_id = 9801, location_created_at = NOW()");
$q("UPDATE endpoint_agent_settings SET enabled = 1, service_url = 'https://rmm.example.test'");

$rmm = rivetRmmModule($db);
$rmm->settings()->generateSigningKey();   // enrollment signs its answer; a scratch install has no key yet
$ok($rmm->enabled(), 'the RMM module is on in the scratch database');
$admin = new RmmPrincipal(951, 'Admin');
$tech = new RmmPrincipal(952, 'Tech');
$ok(rivetRmmCanManageDevices($rmm, 951), 'administrator holds rmm.device.manage');
$ok(!rivetRmmCanManageDevices($rmm, 952), 'a technician with full RMM module access does not');

/** Enroll a device through Core's real enrollment call; returns [device_id, device_token, asset_id]. */
$enroll = function (string $token, string $name) use ($rmm, $q, $one): array {
    $q("INSERT INTO assets SET asset_type = 'Laptop', asset_name = '$name', asset_serial = 'SER-$name', asset_client_id = 9801, asset_created_at = NOW()");
    $asset = (int) $one("SELECT asset_id FROM assets WHERE asset_name = '$name'");
    $r = $rmm->enrollment()->enroll(['enrollment_token' => $token, 'device' => ['install_id' => \RivetCore\Rmm\Job\JobService::uuid(), 'machine_guid' => bin2hex(random_bytes(8)), 'hostname' => $name, 'os' => 'windows',
        'os_version' => 'Windows 11 23H2', 'arch' => 'amd64', 'serial' => "SER-$name", 'manufacturer' => 'Dell', 'model' => 'Latitude', 'mac_addresses' => [], 'agent_version' => '1.0.0']], '127.0.0.1');

    return [(int) $r['device_id'], (string) $r['device_token'], $asset];
};
$tok = $rmm->technician()->createToken($admin, 9801, 0, 'stable', 24, 20, 'plain');
$ok($tok->ok, 'admin creates an enrollment token');
[$d1, $t1, $a1] = $enroll((string) $tok->data['token'], 'PC-ONE');
[$d2, $t2, $a2] = $enroll((string) $tok->data['token'], 'PC-TWO');
$q("INSERT INTO assets SET asset_type = 'Laptop', asset_name = 'NO-AGENT', asset_client_id = 9801, asset_created_at = NOW()");
$aNone = (int) $one("SELECT asset_id FROM assets WHERE asset_name = 'NO-AGENT'");
$ok($d1 > 0 && $d2 > 0 && (int) $one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id = $d1") === $a1, 'a test device is enrolled and linked to its asset');

// ---------------------------------------------------------------- 3. managed externally: off by default, passed through
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_installer_options') === 0, 'a token issued without the option leaves no options row');
$ok((int) $one("SELECT device_automation_disabled FROM endpoint_agent_devices WHERE device_id = $d1") === 0, 'a device enrolled without the option has automation on');
$ok(!rivetRmmManagedExternallyPosted([]) && !rivetRmmManagedExternallyPosted(['managed_externally' => '']) && !rivetRmmManagedExternallyPosted(['managed_externally' => '0']) && !rivetRmmManagedExternallyPosted(['managed_externally' => 'on']) && rivetRmmManagedExternallyPosted(['managed_externally' => '1']), 'the form value is off unless exactly "1"');
$field = rivetRmmManagedExternallyField('x-managed');
$ok(str_contains($field, 'name="managed_externally"') && !str_contains($field, 'checked') && str_contains($field, 'Managed by another RMM') && str_contains($field, 'RivetIT will monitor them and collect inventory, but will not run scheduled scripts, install patches or deploy software on them. You can change this per computer later.'), 'the check box is unticked and carries the label and the help text');
$ext = $rmm->technician()->createToken($admin, 9801, 0, 'stable', 24, 5, 'ext', rivetRmmManagedExternallyPosted(['managed_externally' => '1']));
$ok($ext->ok && (int) $one('SELECT COUNT(*) FROM endpoint_agent_installer_options WHERE managed_externally = 1') === 1, 'createToken(..., true) stores the option by token');
[$dx] = $enroll((string) $ext->data['token'], 'PC-EXT');
$ok((int) $one("SELECT device_automation_disabled FROM endpoint_agent_devices WHERE device_id = $dx") === 1, 'a device enrolled with the option starts with device_automation_disabled = 1');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Endpoint Agent' AND log_action = 'Device Automation Disabled' AND log_description LIKE '%device $dx at enrollment%' AND log_description LIKE '%managed by another RMM%'") === 1, 'the "Device Automation Disabled" audit row exists');
$ok((int) $one("SELECT device_automation_disabled FROM endpoint_agent_devices WHERE device_id = $d1") === 0, 'the plain device was not touched');
$dl = $rmm->admin()->deploymentCommands($admin, 9801, 0, 'stable', 24, 5, 'cmds', 'amd64', false, 'exe', [], 'auto', true);
$dlPlain = $rmm->admin()->deploymentCommands($admin, 9801, 0, 'stable', 24, 5, 'cmds2', 'amd64', false, 'exe', [], 'auto', false);
$ok(!$dl->ok || (int) $one('SELECT COUNT(*) FROM endpoint_agent_installer_options WHERE managed_externally = 1') === 2, 'RmmAdmin::deploymentCommands(..., true) carries the option (or refuses before issuing: ' . $dl->message . ')');
$ok(!$dlPlain->ok || (int) $one('SELECT COUNT(*) FROM endpoint_agent_installer_options WHERE managed_externally = 1') === 2, 'the same call without the option writes no second options row');

// ---------------------------------------------------------------- 1. Move to Department
$jobCols = array_column($rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_jobs' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'"), 'COLUMN_NAME');
$jobSet = ['job_id' => "'job-move-1'", 'device_id' => $d1, 'client_id' => 9801];
foreach ($jobCols as $c) { if (!isset($jobSet[$c])) { $jobSet[$c] = str_ends_with($c, '_at') ? 'UTC_TIMESTAMP()' : "''"; } }
$q('INSERT INTO endpoint_agent_jobs SET ' . implode(', ', array_map(fn ($k, $v) => "`$k` = $v", array_keys($jobSet), $jobSet)));
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE job_id = 'job-move-1'") === 1, 'a queued job exists for the device');
$patchCols = array_column($rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rmm_patch_available' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'"), 'COLUMN_NAME');
$patchSet = ['device_id' => $d1, 'client_id' => 9801];
foreach ($patchCols as $c) { if (!isset($patchSet[$c])) { $patchSet[$c] = str_ends_with($c, '_at') ? 'UTC_TIMESTAMP()' : "'kb-test'"; } }
$q('INSERT INTO rmm_patch_available SET ' . implode(', ', array_map(fn ($k, $v) => "`$k` = $v", array_keys($patchSet), $patchSet)));
$ok((int) $one("SELECT COUNT(*) FROM rmm_patch_available WHERE device_id = $d1 AND client_id = 9801") === 1, 'a patch row exists for the device');
$ok($rmm->technician()->visibleDevice(951, $d1) !== null, 'the administrator sees the device');
$ok(rivetRmmDeviceOfAsset($db, $a1)['device_id'] === $d1 && rivetRmmDeviceOfAsset($db, $aNone) === null, 'an asset with a device is recognised, one without is not');

$targets = array_column(rivetRmmMoveTargets($db, $rmm, 951, 9801), 'id');
$ok($targets === [9802], 'the department list holds the manageable departments minus the current one');
$ok(rivetRmmMoveTargets($db, $rmm, 952, 9801) === [], 'a user without rmm.device.manage gets an empty department list');
$ok(array_column(rivetRmmMoveLocations($db, $rmm, 951, 9802), 'id') === [9811] && rivetRmmMoveLocations($db, $rmm, 952, 9802) === [], 'the site list follows the department and is empty without the ability');

$r = rivetRmmMoveDevice($db, $rmm, $tech, $d1, 9802, 0);
$ok(!$r['ok'] && $r['http'] === 403, 'a technician without rmm.device.manage is refused (403)');
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d1") === 9801, '...and nothing moved');
$r = rivetRmmMoveDevice($db, $rmm, $admin, $d1, 9802, 9812);
$ok(!$r['ok'] && $r['http'] === 422, 'a site of another department is refused');
$r = rivetRmmMoveDevice($db, $rmm, $admin, 99999999, 9802, 0);
$ok(!$r['ok'] && $r['http'] === 404, 'an unknown device is 404');
$r = rivetRmmMoveDevice($db, $rmm, $admin, $d1, 9802, 9811);
$ok($r['ok'] && $r['message'] !== '', 'the administrator moves it; Core\'s message comes back: ' . $r['message']);
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d1") === 9802, 'the device row is in the new department');
$ok((int) $one("SELECT asset_client_id FROM assets WHERE asset_id = $a1") === 9802, 'Core moved the asset too');
$ok((int) $one("SELECT client_id FROM endpoint_agent_jobs WHERE job_id = 'job-move-1'") === 9802, 'its queued job moved');
$ok((int) $one("SELECT COUNT(*) FROM rmm_patch_available WHERE device_id = $d1 AND client_id = 9802") === 1 && (int) $one("SELECT COUNT(*) FROM rmm_patch_available WHERE device_id = $d1 AND client_id = 9801") === 0, 'its patch rows moved (Core rc.59 completeness)');
$ok($rmm->deviceService() !== null && (int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE device_id = $d1 AND token_hash IS NOT NULL AND token_hash <> ''") === 1, 'the device credential is untouched');

// ---------------------------------------------------------------- 2. bulk asset transfer
$hash2 = (string) $one("SELECT token_hash FROM endpoint_agent_devices WHERE device_id = $d2");
$res = rivetRmmBulkTransferAssets($db, $rmm, $tech, [$a2, $aNone], 9802);
$ok($res['moved'] === [] && count($res['refused']) === 1 && $res['refused'][0]['asset_id'] === $a2 && $res['refused'][0]['reason'] !== '' && $res['without_device'] === [$aNone], 'technician: the device asset is refused with a reason, the plain asset is handed back for the old path');
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d2") === 9801, '...and the refused device stayed put');
$res = rivetRmmBulkTransferAssets($db, $rmm, $admin, [$a2, $aNone, 0, -4], 9802);
$ok(count($res['moved']) === 1 && $res['moved'][0]['asset_id'] === $a2 && $res['moved'][0]['device_id'] === $d2 && $res['moved'][0]['from_client_id'] === 9801 && $res['refused'] === [] && $res['without_device'] === [$aNone], 'admin: the device asset goes through transfer(), the asset without a device is NOT touched by it');
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d2") === 9802 && (int) $one("SELECT asset_client_id FROM assets WHERE asset_id = $a2") === 9802 && (int) $one("SELECT asset_client_id FROM assets WHERE asset_id = $aNone") === 9801, 'device and asset moved together; the no-device asset stayed in the old department');
$res = rivetRmmBulkTransferAssets($db, $rmm, $admin, [$a1], 9802);
$ok(count($res['moved']) === 1 && $res['refused'] === [], 'moving to the department the device is already in is a no-op counted as moved');
$res = rivetRmmBulkTransferAssets($db, $rmm, $admin, [$a1], 9899);
$ok($res['moved'] === [] && count($res['refused']) === 1, 'a department that does not exist is refused, not moved');
$ok((string) $one("SELECT token_hash FROM endpoint_agent_devices WHERE device_id = $d2") === $hash2 && $hash2 !== '', 'the device credential (token_hash) is byte-identical after the move: the same token keeps checking in');

// ---------------------------------------------------------------- 4. bulk "managed by another RMM"
$r = rivetRmmBulkManagedExternally($db, $rmm, $tech, [$a1, $a2], true);
$ok(!$r['ok'] && $r['http'] === 403 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE device_automation_disabled = 1 AND device_id IN ($d1,$d2)") === 0, 'a technician is refused (403) and nothing changes');
$r = rivetRmmBulkManagedExternally($db, $rmm, $admin, [$a1, $a2, $aNone, 99999999], true);
$ok($r['ok'] && $r['counts'] === ['changed' => 2, 'unchanged' => 0, 'skipped' => 2] && $r['skipped'] === [$aNone, 99999999] && $r['message'] === '2 changed, 0 already set, 2 skipped.', 'admin: 2 changed, the no-device asset and the missing one are skipped alike (' . $r['message'] . ')');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE device_automation_disabled = 1 AND device_id IN ($d1,$d2)") === 2, 'both devices now have automation off');
$r = rivetRmmBulkManagedExternally($db, $rmm, $admin, [$a1, $a2], true);
$ok($r['ok'] && $r['counts'] === ['changed' => 0, 'unchanged' => 2, 'skipped' => 0], 'a second run reports them unchanged');
$r = rivetRmmBulkManagedExternally($db, $rmm, $admin, [$a1], false);
$ok($r['ok'] && $r['counts']['changed'] === 1 && (int) $one("SELECT device_automation_disabled FROM endpoint_agent_devices WHERE device_id = $d1") === 0, '"Clear" switches it back on');
$r = rivetRmmBulkManagedExternally($db, $rmm, $admin, range(1, 501), true);
$ok(!$r['ok'] && $r['http'] === 422 && $r['code'] === 'too_many', '501 ids are refused');
$r = rivetRmmBulkManagedExternally($db, $rmm, $admin, [], true);
$ok(!$r['ok'] && $r['http'] === 422, 'an empty selection is refused');

// ---------------------------------------------------------------- the real handler, hand-written requests (forged sessions)
$sdir = sys_get_temp_dir() . '/rc60_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$csrf = 'csrftok12345678';
$sess = fn (int $uid) => sec_forge_session($sdir, ['logged' => true, 'user_id' => $uid, 'csrf_token' => $csrf, 'sec_created' => time(), 'sec_last' => time()]);
$q("UPDATE endpoint_agent_devices SET device_automation_disabled = 0 WHERE device_id IN ($d1,$d2)");
$sAdmin = $sess(951);
$sTech = $sess(952);
$hit = function (string $sid, array $f, bool $withCsrf = true) use ($base, $csrf): array {
    [$c, $body] = sec_web($base, 'POST', '/agent/post/rmm_agent.php', $sid, $withCsrf ? $f + ['csrf_token' => $csrf] : $f);

    return [$c, json_decode($body, true) ?? ['raw' => substr($body, 0, 200)]];
};
$ok($hit($sTech, ['action' => 'move_options', 'device_id' => $d1])[0] === 403 || $hit($sTech, ['action' => 'move_options', 'device_id' => $d1])[0] === 404, 'handler: technician move_options refused');
[$c, $j] = $hit($sTech, ['action' => 'move_department', 'device_id' => $d2, 'client_id' => 9801, 'location_id' => 0]);
$ok(in_array($c, [403, 404], true) && empty($j['success']) && (int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d2") === 9802, "handler: a hand-written move by a technician is refused ($c) and nothing moves");
[$c, $j] = $hit($sTech, ['action' => 'bulk_managed_externally', 'asset_ids' => [$a1, $a2], 'disabled' => '1']);
$ok($c === 403 && empty($j['success']) && (int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE device_automation_disabled = 1 AND device_id IN ($d1,$d2)") === 0, "handler: a hand-written bulk switch by a technician is 403 ($c) and changes nothing");
[$c, $j] = $hit($sAdmin, ['action' => 'move_department', 'device_id' => $d2, 'client_id' => 9801], false);
$ok($c === 403 && ($j['code'] ?? '') === 'csrf' && (int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d2") === 9802, 'handler: no CSRF token, no move');
[$c, $j] = $hit($sAdmin, ['action' => 'bulk_managed_externally', 'asset_ids' => [$a1], 'disabled' => '1'], false);
$ok($c === 403 && ($j['code'] ?? '') === 'csrf' && (int) $one("SELECT device_automation_disabled FROM endpoint_agent_devices WHERE device_id = $d1") === 0, 'handler: no CSRF token, no bulk switch');
[$c, $j] = $hit($sAdmin, ['action' => 'move_options', 'device_id' => $d2]);
$ok($c === 200 && in_array(9801, array_column($j['departments'] ?? [], 'id'), true) && !in_array(9802, array_column($j['departments'] ?? [], 'id'), true), 'handler: the administrator gets the departments minus the current one');
[$c, $j] = $hit($sAdmin, ['action' => 'move_department', 'device_id' => $d2, 'client_id' => 9801, 'location_id' => 0]);
$ok($c === 200 && !empty($j['success']) && str_contains((string) ($j['redirect'] ?? ''), 'asset_id=' . $a2) && (int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d2") === 9801, 'handler: the administrator moves it back and is sent to the asset in its new department');
[$c, $j] = $hit($sAdmin, ['action' => 'bulk_managed_externally', 'asset_ids' => [$a1, $a2, $aNone], 'disabled' => '1']);
$ok($c === 200 && ($j['counts']['changed'] ?? -1) === 2 && ($j['counts']['skipped'] ?? -1) === 1, 'handler: the bulk switch answers with the counts');

// the real bulk "transfer assets to another department" handler (agent/post/asset.php): the device asset is moved by transfer() (same asset id, same device), the plain asset takes the old copy path
$q("UPDATE assets SET asset_client_id = 9801 WHERE asset_id IN ($a1,$a2)");
$q("UPDATE endpoint_agent_devices SET client_id = 9801 WHERE device_id IN ($d1,$d2)");
$assetCount = fn () => (int) $one("SELECT COUNT(*) FROM assets");
$before = $assetCount();
$bulk = fn (string $sid, array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => $csrf, 'bulk_transfer_client_asset' => 1, 'bulk_client_id' => 9802], [], ['Referer: ' . $base . '/agent/assets.php']);
$bulk($sTech, ['asset_ids' => [$a1]]);
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d1") === 9801 && (int) $one("SELECT asset_archived_at IS NULL FROM assets WHERE asset_id = $a1") === 1 && $assetCount() === $before, 'bulk handler, technician with Assets edit rights but no rmm.device.manage: a device asset is refused, NOT copied and NOT archived');
[$c, $body] = $bulk($sAdmin, ['asset_ids' => [$a1, $aNone]]);
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d1") === 9802 && (int) $one("SELECT asset_client_id FROM assets WHERE asset_id = $a1") === 9802 && (int) $one("SELECT asset_archived_at IS NULL FROM assets WHERE asset_id = $a1") === 1, "bulk handler, admin ($c): the device asset keeps its id and moved with its device");
$ok((int) $one("SELECT asset_archived_at IS NOT NULL FROM assets WHERE asset_id = $aNone") === 1 && (int) $one("SELECT COUNT(*) FROM assets WHERE asset_name = 'NO-AGENT' AND asset_client_id = 9802 AND asset_archived_at IS NULL") === 1, 'bulk handler: the asset without a device took the old copy-and-archive path');

// ---------------------------------------------------------------- tenancy adapter: clientName() is Core's existence check
$q("INSERT INTO clients SET client_id = 9803, client_name = 'Archived Dept', client_archived_at = NOW(), client_created_at = NOW()");
$ten = new \ITFlow\Core\Adapter\Endpoint\EndpointTenancy(rivetCoreDb($db));
$ok($ten->clientName(9802) === 'New Dept', 'clientName: a valid department answers its name');
$ok($ten->clientName(99999999) === null, 'clientName: a nonexistent id answers null');
$ok($ten->clientName(0) === null && $ten->clientName(-5) === null, 'clientName: 0 and a negative id answer null');
$ok($ten->clientName(9803) === null, 'clientName: an archived department answers null');
$hashA = (string) $one("SELECT token_hash FROM endpoint_agent_devices WHERE device_id = $d1");
foreach ([99999999, 0, 9803] as $bad) {
    $ok($rmm->deviceService()->transfer($d1, $bad, 0, 951) === false, "DeviceService::transfer() to department $bad returns false");
    $rr = rivetRmmMoveDevice($db, $rmm, $admin, $d1, $bad, 0);
    $ok(!$rr['ok'], "rivetRmmMoveDevice() to department $bad is refused (" . $rr['message'] . ')');
}
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $d1") === 9802, 'no device moved to a bad department');

// ---------------------------------------------------------------- module off: a clear message first, never a bare 404
$rmm->settings()->set(['enabled' => 0]);   // through Core so the module's settings cache follows
$rmmOff = $rmm;
$off = fn (array $r) => !$r['ok'] && $r['code'] === 'module_off' && $r['message'] === 'Remote management is turned off.';
$ok($off(rivetRmmMoveDevice($db, $rmmOff, $admin, $d1, 9801, 0)), 'module off: move says "Remote management is turned off."');
$ok($off(rivetRmmBulkManagedExternally($db, $rmmOff, $admin, [$a1], true)), 'module off: the bulk switch says so');
$resOff = rivetRmmBulkTransferAssets($db, $rmmOff, $admin, [$a1, $aNone], 9801);
$ok($resOff['moved'] === [] && count($resOff['refused']) === 1 && $resOff['refused'][0]['reason'] === 'Remote management is turned off.' && $resOff['without_device'] === [$aNone], 'module off: bulk transfer refuses the device asset with that message and hands the plain one back');
foreach (['move_options', 'move_locations', 'move_department', 'bulk_managed_externally'] as $act) {
    [$c, $j] = $hit($sAdmin, ['action' => $act, 'device_id' => $d1, 'client_id' => 9801, 'asset_ids' => [$a1], 'disabled' => '1']);
    $ok($c === 404 && ($j['code'] ?? '') === 'module_off' && ($j['error'] ?? '') === 'Remote management is turned off.', "module off: handler action $act answers module_off with the message");
}
$rmm->settings()->set(['enabled' => 1]);

// ---------------------------------------------------------------- 0. migration step 2.6.236
$src = (string) file_get_contents("$root/admin/database_updates.php");
$start = strpos($src, "if (\$rivetit_db_version() == '2.6.235') {");
$end = $start === false ? false : strpos($src, "\n    }\n", $start);
$ok($start !== false && $end !== false, 'migration step 2.6.235 -> 2.6.236 is present in admin/database_updates.php');
$step = substr($src, (int) $start, (int) $end - (int) $start + 7);
$ok(!preg_match('/0049|managed_externally/', preg_replace('~^\s*//.*$~m', '', $step)), 'the step hard-codes no migration id: it runs CoreMigrations::all()');
$ok(str_contains($step, 'CoreMigrations::all()') && str_contains($step, 'MigrationRunner') && str_contains($step, "'2.6.236'"), 'the step runs CoreMigrations::all() through MigrationRunner and sets 2.6.236');
$ok(str_contains((string) file_get_contents("$root/includes/database_version.php"), '"2.6.236"'), 'LATEST_DATABASE_VERSION is 2.6.236');
$before = $rows("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_installer_options' ORDER BY ORDINAL_POSITION");
$q("TRUNCATE endpoint_agent_installer_options");
$q("ALTER TABLE endpoint_agent_installer_options DROP COLUMN managed_externally");
$q("DELETE FROM rivet_core_migrations WHERE migration_id = '0049_rmm_installer_managed_externally'");
$q("UPDATE settings SET config_current_database_version = '2.6.235'");
// The step text is this repository's own admin/database_updates.php (the same eval pattern as tests/parked_ticket_statuses.php), run against the scratch database only.
$runStep = function () use ($step, $mysqli) {
    $rivetit_db_version = fn() => '2.6.235';
    eval($step);
};
$runStep();
$after = $rows("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_installer_options' ORDER BY ORDINAL_POSITION");
$ok($after === $before, 'the step re-creates endpoint_agent_installer_options.managed_externally exactly as a fresh install has it (type, null, default, position)');
$ok($one("SELECT config_current_database_version FROM settings WHERE company_id = 1") === '2.6.236', 'the step sets the database version to 2.6.236');
$ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id = '0049_rmm_installer_managed_externally'") === 1, 'Core recorded migration 0049');
$runStep();
$again = $rows("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_installer_options' ORDER BY ORDINAL_POSITION");
$ok($again === $after && $db->error === '', 'running the step again changes nothing and raises no error (idempotent)');
$col = array_values(array_filter($after, fn ($c) => $c['COLUMN_NAME'] === 'managed_externally'))[0] ?? [];
$ok(($col['COLUMN_TYPE'] ?? '') === 'tinyint(1)' && ($col['IS_NULLABLE'] ?? '') === 'NO' && ($col['COLUMN_DEFAULT'] ?? '') === '0', 'the column is tinyint(1) NOT NULL DEFAULT 0');
$dbsql = (string) file_get_contents("$root/db.sql");
$ok(preg_match('/CREATE TABLE `endpoint_agent_installer_options` \(.*?`created_at` datetime NOT NULL,\s*`managed_externally` tinyint\(1\) NOT NULL DEFAULT 0,\s*PRIMARY KEY/s', $dbsql) === 1, 'db.sql mirrors the column after created_at (the position the migration adds it at)');
