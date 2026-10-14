<?php
/*
 * ManageEngine MDM over REAL HTTP (php -S with forged sessions, scratch DB, the LOCAL mock): the admin settings page and its POST handler
 * (admin only, CSRF, data-center allowlist, secrets encrypted / never echoed / blank keeps), Test connection and Sync now through the web
 * handlers, the agent devices page, the review-list handler (permission levels, department scope, CSRF), the asset panel, the assets-list badge,
 * the navigation entry, and the kiosk status helper.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/manageengine_mdm_web.php
 * config.php must point at the scratch database and set $config_https_only = FALSE.
 */
require __DIR__ . '/manageengine_mdm_lib.php';

use ITFlow\Integrations\ManageEngine\MdmClientFactory;
use ITFlow\Integrations\ManageEngine\MdmDeviceStatus;

if (stripos((string) file_get_contents("$root/config.php"), '$config_https_only = FALSE') === false) { fwrite(STDERR, "config.php needs \$config_https_only = FALSE\n"); exit(2); }

// ---- seed: modules, roles, users, departments (scratch DB only)
foreach (['user_role_permissions', 'user_client_permissions', 'users', 'user_roles', 'modules', 'user_settings', 'clients', 'logs', 'audit_events'] as $t) { $q("DELETE FROM $t"); }
foreach (['module_client' => 4, 'module_support' => 5, 'module_admin' => 9] as $m => $id) { $q("INSERT INTO modules SET module_id=$id, module_name='$m'"); }
foreach ([[1, 'Admin', 1], [2, 'Tech', 0], [3, 'Viewer', 0], [4, 'Nothing', 0]] as [$id, $n, $adm]) { $q("INSERT INTO user_roles SET role_id=$id, role_name='$n', role_is_admin=$adm, role_type=1"); }
foreach ([[2, 4, 3], [2, 5, 3], [3, 4, 1], [3, 5, 1]] as [$r, $m, $l]) { $q("INSERT INTO user_role_permissions SET user_role_id=$r, module_id=$m, user_role_permission_level=$l"); }
foreach ([1 => ['admin', 1], 10 => ['tech', 2], 11 => ['viewer', 3], 12 => ['nothing', 4], 13 => ['deptb', 2]] as $uid => [$name, $role]) {
    $q("INSERT INTO users SET user_id=$uid, user_name='$name', user_email='$name@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role");
    $q("INSERT INTO user_settings SET user_id=$uid");
}
$q("INSERT INTO user_client_permissions SET user_id=13, client_id=2");   // user 13 may only see Dept B
$q("INSERT INTO clients SET client_id=1, client_name='Dept A', client_type=''"); $q("INSERT INTO clients SET client_id=2, client_name='Dept B', client_type=''");

$sessdir = sys_get_temp_dir() . '/mdm_walk_sess_' . getmypid(); @mkdir($sessdir, 0700);
$prepend = sys_get_temp_dir() . '/mdm_walk_prepend_' . getmypid() . '.php';
file_put_contents($prepend, "<?php\ndefine('RIVETIT_MDM_BASE_URL', " . var_export($base, true) . ");\ndefine('RIVETIT_MDM_ACCOUNTS_URL', " . var_export($base, true) . ");\n");
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $webPort = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
$weblog = sys_get_temp_dir() . "/mdm_walk_$webPort.log";
$web = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$weblog", '-d', "session.save_path=$sessdir", '-d', "auto_prepend_file=$prepend", '-S', "127.0.0.1:$webPort", '-t', $root],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $weblog, 'a'], 2 => ['file', $weblog, 'a']], $pipes, $root, getenv());
register_shutdown_function(function () use ($web, $sessdir, $prepend, $weblog) {
    if (is_resource($web)) { proc_terminate($web); proc_close($web); }
    foreach (glob("$sessdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sessdir); @unlink($prepend); @unlink($weblog);
});
for ($i = 0; $i < 60; $i++) { if (@fsockopen('127.0.0.1', $webPort)) break; usleep(100000); }
$site = "http://127.0.0.1:$webPort";
$csrf = 'walkcsrf0123456789abcdef0123456789';
$sessions = [];
foreach (['admin' => 1, 'tech' => 10, 'viewer' => 11, 'nothing' => 12, 'deptb' => 13] as $name => $uid) {
    $sessions[$name] = "walk$name";
    file_put_contents("$sessdir/sess_walk$name", 'logged|b:1;user_id|i:' . $uid . ';csrf_token|s:' . strlen($csrf) . ':"' . $csrf . '";');
}
function w(string $who, string $method, string $path, array $post = [], ?string $referer = null): array {
    global $site, $sessions;
    $ch = curl_init($site . $path);
    $h = $referer !== null ? ['Referer: ' . $site . $referer] : [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'PHPSESSID=' . $sessions[$who], CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $h]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['code' => $code, 'body' => substr((string) $raw, $hs), 'head' => substr((string) $raw, 0, $hs)];
}
$S = '/admin/settings_manageengine_mdm.php';
$post = fn (string $who, array $f) => w($who, 'POST', '/admin/post.php', $f + ['csrf_token' => $GLOBALS['csrf']], $S);
$row = fn () => MdmClientFactory::row($db);

// ---- admin settings page
$q('DELETE FROM manageengine_mdm_integrations'); mdm_clean(); $reset();
$r = w('admin', 'GET', $S);
$ok($r['code'] === 200 && strpos($r['body'], 'ManageEngine Mobile Device Manager Plus') !== false, 'admin: the settings page loads');
$ok(substr_count($r['body'], '<option value="') >= 8 && strpos($r['body'], 'accounts.zoho') === false, 'the page offers the data-center select and shows no host');
$ok(strpos($r['body'], 'MDMOnDemand.MDMInventory.READ') !== false && strpos($r['body'], 'MDMOnDemand.MDMDeviceMgmt.READ') !== false && strpos($r['body'], 'MDMUser') === false && strpos($r['body'], '.CREATE') === false, 'only the two READ scopes are shown (nothing that can create, change or delete)');
$ok(!preg_match('/name="enabled"[^>]*checked/', $r['body']), 'off by default (the Enabled switch is not checked)');
foreach (['tech', 'viewer', 'nothing'] as $who) {
    $r = w($who, 'GET', $S);
    $ok($r['code'] !== 200 || strpos($r['body'], 'Client secret') === false, "non-admin ($who): the settings page does not render the form (HTTP {$r['code']})");
}

// ---- save: CSRF, permissions, allowlist, secrets
$f = ['save_mdm_connection' => 1, 'data_center' => 'us', 'client_id' => '1000.TESTCLIENT', 'client_secret' => 'good-secret', 'refresh_token' => 'good-refresh', 'enabled' => '1', 'sync_interval_minutes' => 30, 'stale_days' => 5, 'enrich_details' => '1'];
$r = w('admin', 'POST', '/admin/post.php', $f, $S);   // no csrf_token
$ok($row() === null, 'POST without a CSRF token saves nothing');
$r = w('admin', 'POST', '/admin/post.php', $f + ['csrf_token' => 'wrong-token'], $S);
$ok($row() === null, 'POST with a wrong CSRF token saves nothing');
foreach (['tech', 'viewer', 'nothing'] as $who) {
    $post($who, $f);
    $ok($row() === null, "non-admin ($who) POST to the admin handler saves nothing");
}
$post('admin', ['data_center' => 'evil.com'] + $f);
$ok($row() === null, 'a data center that is not on the allowlist (evil.com) is rejected');
$post('admin', ['data_center' => 'http://169.254.169.254/'] + $f);
$ok($row() === null, 'a URL in the data center field is rejected');
$post('admin', ['client_id' => "x' OR 1=1 --"] + $f);
$ok($row() === null, 'a client id with characters a Zoho client id never has is rejected');
$post('admin', ['refresh_token' => ''] + $f);
$ok($row() === null, 'switching on without a refresh token is refused');
$post('admin', $f);
$saved = $row();
$ok($saved && (int) $saved['enabled'] === 1 && $saved['data_center'] === 'us' && $saved['client_id'] === '1000.TESTCLIENT' && (int) $saved['sync_interval_minutes'] === 30 && (int) $saved['stale_days'] === 5, 'admin: a valid save stores the connection');
$ok(decryptSetting($saved['client_secret_enc']) === 'good-secret' && decryptSetting($saved['refresh_token_enc']) === 'good-refresh' && strpos($saved['client_secret_enc'] . $saved['refresh_token_enc'], 'good-') === false, 'secrets are stored encrypted');
$r = w('admin', 'GET', $S);
$ok($r['code'] === 200 && strpos($r['body'], 'good-secret') === false && strpos($r['body'], 'good-refresh') === false && strpos($r['body'], $saved['client_secret_enc']) === false && strpos($r['body'], $saved['refresh_token_enc']) === false, 'the page never echoes a secret or its ciphertext');
$ok(strpos($r['body'], 'Stored. Leave blank to keep it') !== false && preg_match('/name="enabled"[^>]*checked/', $r['body']) === 1, 'it shows "Stored" placeholders and the saved enabled state');
$post('admin', ['client_secret' => '', 'refresh_token' => '', 'data_center' => 'eu', 'sync_interval_minutes' => 9999, 'stale_days' => 0] + $f);
$s2 = $row();
$ok($s2['data_center'] === 'eu' && decryptSetting($s2['client_secret_enc']) === 'good-secret' && decryptSetting($s2['refresh_token_enc']) === 'good-refresh', 'a blank secret / refresh token keeps the stored ones');
$ok((int) $s2['sync_interval_minutes'] === 1440 && (int) $s2['stale_days'] === 1, 'interval and stale days are clamped (5..1440, 1..365)');
$q("UPDATE manageengine_mdm_integrations SET token_cache_enc='cached-token-blob', token_expires_at=DATE_ADD(NOW(), INTERVAL 1 HOUR)");
$post('admin', ['client_secret' => '', 'refresh_token' => '', 'data_center' => 'eu'] + $f);
$ok($row()['token_cache_enc'] === 'cached-token-blob', 'saving the same data center and client keeps the cached token');
$post('admin', ['refresh_token' => 'new-refresh-token'] + $f);
$ok($row()['token_cache_enc'] === null && decryptSetting($row()['refresh_token_enc']) === 'new-refresh-token', 'a new refresh token clears the cached access token');
$post('admin', $f);   // back to the good credentials
$logs = json_encode($rows("SELECT * FROM logs")) . json_encode($rows("SELECT * FROM audit_events"));
$ok(strpos($logs, 'good-secret') === false && strpos($logs, 'good-refresh') === false && strpos($logs, 'new-refresh-token') === false && strpos($logs, 'ManageEngine MDM integration') !== false, 'the audit trail records the save (and which secret was replaced) but no secret value');

// ---- Test connection and Sync now through the handlers
mdm_seed_mock($mock, 5);
foreach (['kiosk A' => 1, 'kiosk B' => 2] as $nm => $i) { $q("INSERT INTO assets SET asset_type='Tablet', asset_name='$nm', asset_serial='" . sprintf('DMPX%07d', $i) . "', asset_make='Apple', asset_client_id=" . ($i === 1 ? 1 : 2)); }
$post('tech', ['test_mdm_connection' => 1]);
$ok($row()['last_test_at'] === null, 'a non-admin cannot run Test connection');
$post('admin', ['test_mdm_connection' => 1]);
$t = $row();
$ok((int) $t['last_test_success'] === 1 && $t['last_test_error'] === null, 'Test connection through the handler succeeds against the mock');
$q("UPDATE manageengine_mdm_integrations SET refresh_token_enc='" . $esc(encryptSetting('bad-refresh-VALUE9')) . "', token_cache_enc=NULL");
$post('admin', ['test_mdm_connection' => 1]);
$t = $row();
$ok((int) $t['last_test_success'] === 0 && $t['last_test_error_code'] === 'auth_failed' && strpos((string) $t['last_test_error'], 'bad-refresh-VALUE9') === false, 'a failing test is stored as auth_failed without the secret');
$r = w('admin', 'GET', $S);
$ok(strpos($r['body'], 'Sign-in failed') !== false && strpos($r['body'], 'bad-refresh-VALUE9') === false, 'the page shows the failure in words, not the secret');
$post('admin', $f);
$post('tech', ['sync_mdm_now' => 1]);
$ok((int) $one('SELECT COUNT(*) FROM mdm_sync_log') === 0, 'a non-admin cannot run Sync now');
$post('admin', ['sync_mdm_now' => 1]);
$lr = $db->query('SELECT * FROM mdm_sync_log ORDER BY id DESC LIMIT 1')->fetch_assoc();
$ok($lr && $lr['status'] === 'success' && (int) $lr['triggered_by'] === 1 && (int) $lr['devices_total'] === 5 && (int) $lr['devices_matched'] === 2 && (int) $lr['devices_unmatched'] === 3, 'Sync now through the handler: 5 devices, 2 linked, 3 to review, started by the admin');
$ok((int) $one('SELECT COUNT(*) FROM assets') === 2, 'Sync now creates no asset');
$r = w('admin', 'GET', $S);
$ok(strpos($r['body'], 'Recent syncs') !== false && strpos($r['body'], '3 need review') !== false, 'the page lists the recent sync and the review count');
$q("UPDATE manageengine_mdm_integrations SET enabled=0");
$before = (int) $one('SELECT COUNT(*) FROM mdm_sync_log');
$post('admin', ['sync_mdm_now' => 1]);
$ok((int) $one('SELECT COUNT(*) FROM mdm_sync_log') === $before, 'disabled: Sync now refuses to run');
$q("UPDATE manageengine_mdm_integrations SET enabled=1");

// ---- agent pages
$A = '/agent/mdm_devices.php';
$a1 = (int) $one("SELECT asset_id FROM assets WHERE asset_name='kiosk A'"); $a2 = (int) $one("SELECT asset_id FROM assets WHERE asset_name='kiosk B'");
$r = w('admin', 'GET', $A);
$ok($r['code'] === 200 && strpos($r['body'], 'Linked devices') !== false && strpos($r['body'], 'Needs review (3)') !== false && strpos($r['body'], 'iPad 1') !== false, 'admin: the devices page shows the fleet overview and linked devices');
$ok(strpos($r['body'], 'Not seen in 7+ days') !== false || strpos($r['body'], 'Not seen in 5+ days') !== false, 'the overview has the not-seen card');
$r = w('nothing', 'GET', $A);
$ok($r['code'] !== 200 || strpos($r['body'], 'Linked devices') === false, "a role without client access cannot open the devices page (HTTP {$r['code']})");
$r = w('viewer', 'GET', $A . '?view=unmatched');
$ok($r['code'] === 200 && strpos($r['body'], 'DMPX0000003') !== false && strpos($r['body'], 'create_mdm_asset') === false && strpos($r['body'], 'Needs edit access') !== false, 'viewer (level 1): sees the review list but has no Create/Link controls');
$r = w('tech', 'GET', $A . '?view=unmatched');
$ok($r['code'] === 200 && strpos($r['body'], 'name="create_mdm_asset"') !== false && strpos($r['body'], 'name="link_mdm_unmatched"') !== false, 'tech (level 3): gets Create asset and Link controls');
$r = w('deptb', 'GET', $A);
$ok($r['code'] === 200 && strpos($r['body'], 'iPad 2') !== false && strpos($r['body'], 'iPad 1') === false && strpos($r['body'], 'Needs review') === false, 'department-restricted user: only their department\'s devices, no review tab');
$r = w('deptb', 'GET', $A . '?view=unmatched');
$ok(strpos($r['body'], 'DMPX0000003') === false, 'department-restricted user cannot reach the (department-less) review list by URL');
$r = w('admin', 'GET', $A . '?platform=' . urlencode("iOS' OR '1'='1") . '&state=stale&client_id=' . urlencode('1 OR 1=1'));
$ok($r['code'] === 200 && strpos($r['body'], "OR '1'='1") === false, 'hostile filter values are ignored, not echoed or executed');
$r = w('admin', 'GET', $A . '?platform=ios');
$ok($r['code'] === 200 && strpos($r['body'], 'iPad 1') !== false, 'the platform filter is case-insensitive and only accepts platforms that exist');

// ---- review-list handler
$P = '/agent/mdm_devices.php?view=unmatched';
$u3 = (int) $one("SELECT id FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740003'");
$u4 = (int) $one("SELECT id FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740004'");
$u5 = (int) $one("SELECT id FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740005'");
$ap = fn (string $who, array $fields, bool $withCsrf = true) => w($who, 'POST', '/agent/post.php', $fields + ($withCsrf ? ['csrf_token' => $GLOBALS['csrf']] : []), $P);
$assets0 = (int) $one('SELECT COUNT(*) FROM assets');
$ap('tech', ['create_mdm_asset' => 1, 'unmatched_id' => $u3, 'client_id' => 1], false);
$ok((int) $one('SELECT COUNT(*) FROM assets') === $assets0, 'Create asset without a CSRF token does nothing');
$ap('viewer', ['create_mdm_asset' => 1, 'unmatched_id' => $u3, 'client_id' => 1]);
$ok((int) $one('SELECT COUNT(*) FROM assets') === $assets0 && (int) $one("SELECT COUNT(*) FROM mdm_unmatched_devices WHERE id=$u3") === 1, 'viewer (level 1) cannot create an asset');
$ap('nothing', ['create_mdm_asset' => 1, 'unmatched_id' => $u3, 'client_id' => 1]);
$ok((int) $one('SELECT COUNT(*) FROM assets') === $assets0, 'a role with no client access cannot create an asset');
$ap('deptb', ['create_mdm_asset' => 1, 'unmatched_id' => $u3, 'client_id' => 1]);
$ok((int) $one('SELECT COUNT(*) FROM assets') === $assets0, 'a department-restricted user cannot create an asset in another department');
// a department-scoped non-admin (deptb, level 3 but limited to Dept B) is refused on EVERY review-list action, even for their own department
$q("INSERT INTO assets SET asset_type='Tablet', asset_name='deptb target', asset_serial='DEPTB-1', asset_make='x', asset_client_id=2");
$deptbAsset = (int) $db->insert_id;
$ap('deptb', ['create_mdm_asset' => 1, 'unmatched_id' => $u3, 'client_id' => 2]);
$ok((int) $one('SELECT COUNT(*) FROM assets') === $assets0 + 1 && (int) $one("SELECT COUNT(*) FROM mdm_unmatched_devices WHERE id=$u3") === 1, 'scoped user: Create asset is refused even in their own department');
$ap('deptb', ['link_mdm_unmatched' => 1, 'unmatched_id' => $u3, 'asset_id' => $deptbAsset]);
$ok((int) $one("SELECT COUNT(*) FROM asset_mdm_links WHERE asset_id=$deptbAsset") === 0 && (int) $one("SELECT COUNT(*) FROM mdm_unmatched_devices WHERE id=$u3") === 1, 'scoped user: Link to existing asset is refused even for an asset in their department');
$ap('deptb', ['dismiss_mdm_unmatched' => 1, 'unmatched_id' => $u3]);
$ok((int) $one("SELECT dismissed FROM mdm_unmatched_devices WHERE id=$u3") === 0, 'scoped user: Ignore is refused');
$q("UPDATE mdm_unmatched_devices SET dismissed=1 WHERE id=$u3");
$ap('deptb', ['restore_mdm_unmatched' => 1, 'unmatched_id' => $u3]);
$ok((int) $one("SELECT dismissed FROM mdm_unmatched_devices WHERE id=$u3") === 1, 'scoped user: Restore is refused');
$q("UPDATE mdm_unmatched_devices SET dismissed=0 WHERE id=$u3");
$q("DELETE FROM assets WHERE asset_id=$deptbAsset"); $assets0 = (int) $one('SELECT COUNT(*) FROM assets');
$ap('tech', ['create_mdm_asset' => 1, 'unmatched_id' => $u3, 'client_id' => 1]);
$ok((int) $one('SELECT COUNT(*) FROM assets') === $assets0 + 1 && (int) $one("SELECT COUNT(*) FROM mdm_unmatched_devices WHERE id=$u3") === 0 && (int) $one("SELECT COUNT(*) FROM asset_mdm_links WHERE mdm_device_id='9007199254740003'") === 1, 'tech (level 3): Create asset makes one asset, links it, clears the review row');
$ap('viewer', ['link_mdm_unmatched' => 1, 'unmatched_id' => $u4, 'asset_id' => $a2]);
$ok((int) $one("SELECT COUNT(*) FROM mdm_unmatched_devices WHERE id=$u4") === 1, 'viewer cannot link an asset');
$q("INSERT INTO assets SET asset_type='Tablet', asset_name='manual target', asset_serial='MANUAL-1', asset_make='x', asset_client_id=1");
$free = (int) $db->insert_id;
$ap('tech', ['link_mdm_unmatched' => 1, 'unmatched_id' => $u4, 'asset_id' => $free]);
$ok((int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740004'") === $free, 'tech: Link to existing asset links the chosen asset');
$ap('viewer', ['dismiss_mdm_unmatched' => 1, 'unmatched_id' => $u5]);
$ok((int) $one("SELECT dismissed FROM mdm_unmatched_devices WHERE id=$u5") === 0, 'viewer cannot ignore a device');
$ap('tech', ['dismiss_mdm_unmatched' => 1, 'unmatched_id' => $u5]);
$ok((int) $one("SELECT dismissed FROM mdm_unmatched_devices WHERE id=$u5") === 1, 'tech: Ignore hides the device');
$r = w('tech', 'GET', $P);
$ok(strpos($r['body'], 'DMPX0000005') === false, 'an ignored device leaves the review list');
$r = w('tech', 'GET', $P . '&dismissed=1');
$ok(strpos($r['body'], 'DMPX0000005') !== false && strpos($r['body'], 'Restore to review') !== false, 'ignored devices can be shown and restored');
$ap('tech', ['restore_mdm_unmatched' => 1, 'unmatched_id' => $u5]);
$ok((int) $one("SELECT dismissed FROM mdm_unmatched_devices WHERE id=$u5") === 0, 'Restore puts it back');

// ---- asset panel, list badge, unlink, nav
$D = "/agent/asset_details.php?client_id=1&asset_id=$a1";
$r = w('admin', 'GET', $D);
$ok($r['code'] === 200 && strpos($r['body'], 'ManageEngine MDM: iPad 1') !== false && strpos($r['body'], 'Training iPads') !== false && strpos($r['body'], '77%') !== false && strpos($r['body'], 'name="unlink_mdm_device"') !== false, 'asset page: the ManageEngine MDM panel shows status, groups, battery, and the unlink control');
$r = w('viewer', 'GET', $D);
$ok($r['code'] === 200 && strpos($r['body'], 'ManageEngine MDM: iPad 1') !== false && strpos($r['body'], 'name="unlink_mdm_device"') === false, 'asset page (viewer): panel visible, no unlink control');
$r = w('admin', 'GET', '/agent/assets.php');
$ok($r['code'] === 200 && strpos($r['body'], 'fa-tablet-alt me-1"></i>MDM') !== false, 'assets list: the MDM badge appears on a linked asset');
$r = w('admin', 'GET', '/agent/assets.php?client_id=1');
$ok($r['code'] === 200, 'assets list filtered by department still loads');
$linkId = (int) $one("SELECT id FROM asset_mdm_links WHERE asset_id=$a1");
$w2 = fn ($who) => w($who, 'POST', '/agent/post.php', ['unlink_mdm_device' => 1, 'link_id' => $linkId, 'csrf_token' => $GLOBALS['csrf']], $D);
$w2('viewer'); $ok((int) $one("SELECT COUNT(*) FROM asset_mdm_links WHERE id=$linkId") === 1, 'viewer cannot unlink');
$w2('deptb'); $ok((int) $one("SELECT COUNT(*) FROM asset_mdm_links WHERE id=$linkId") === 1, 'a user without access to that department cannot unlink');
w('tech', 'POST', '/agent/post.php', ['unlink_mdm_device' => 1, 'link_id' => $linkId], $D); $ok((int) $one("SELECT COUNT(*) FROM asset_mdm_links WHERE id=$linkId") === 1, 'unlink without a CSRF token does nothing');
$w2('tech'); $ok((int) $one("SELECT COUNT(*) FROM asset_mdm_links WHERE id=$linkId") === 0, 'tech: unlink removes the link row');
$r = w('admin', 'GET', $D);
$ok(strpos($r['body'], 'ManageEngine MDM: ') === false, 'after unlinking the panel is gone');
$r = w('admin', 'GET', '/agent/clients.php');
$ok($r['code'] === 200 && strpos($r['body'], 'href="/agent/mdm_devices.php"') !== false && strpos($r['body'], 'MDM Devices') !== false, 'navigation: "MDM Devices" appears while the integration is on');
$q('UPDATE manageengine_mdm_integrations SET enabled=0');
$r = w('admin', 'GET', '/agent/clients.php');
$ok($r['code'] === 200 && strpos($r['body'], 'href="/agent/mdm_devices.php"') === false, 'navigation: the entry is gone while the integration is off');
$r = w('admin', 'GET', $A);
$ok($r['code'] === 200 && strpos($r['body'], 'not switched on') !== false, 'the devices page says so while the integration is off');
$r = w('admin', 'GET', '/agent/assets.php');
$ok(strpos($r['body'], 'fa-tablet-alt me-1"></i>MDM') === false, 'the list badge is hidden while the integration is off');

// ---- kiosk status helper: defensive
$q('UPDATE manageengine_mdm_integrations SET enabled=1');
$ok(MdmDeviceStatus::forAssets($db, []) === [] && MdmDeviceStatus::forAssets($db, [0, -1]) === [], 'kiosk helper: nothing to look up is an empty result');
$m = MdmDeviceStatus::forAssets($db, [$a2, 999999]);
$ok(isset($m[$a2]) && !isset($m[999999]) && array_keys($m[$a2]) === ['state', 'label', 'class', 'battery', 'last_contact', 'platform'] && strpos(json_encode($m), 'user') === false && strpos(json_encode($m), 'DMPX') === false, 'kiosk helper: a linked asset yields status, battery and last contact only (no user, serial or email)');
$q('UPDATE manageengine_mdm_integrations SET enabled=0');
$ok(MdmDeviceStatus::forAssets($db, [$a2]) === [], 'kiosk helper: nothing while the integration is off');
$q('RENAME TABLE asset_mdm_links TO asset_mdm_links_hidden');
$ok(MdmDeviceStatus::forAssets($db, [$a2]) === [], 'kiosk helper: a missing table (database update pending) is an empty result, not an error');
$q('RENAME TABLE asset_mdm_links_hidden TO asset_mdm_links');
$ok(strpos((string) @file_get_contents($weblog), 'Fatal') === false, 'the web server log has no PHP fatal error from any of the above');
