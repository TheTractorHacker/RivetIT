<?php
/*
 * ManageEngine MDM mapping options over REAL HTTP (php -S with forged sessions, scratch DB): the admin-only mapping settings and master switch,
 * the dry-run preview, the Mapping and Users-to-contacts pages, the asset search endpoint, and EVERY new POST action (single and bulk) for each
 * kind of person: admin, tech (unrestricted, level 3), viewer (level 1), a role with no client access, and a department-scoped technician, who
 * must be refused on all of them (the earlier security review caught exactly this gap on the review list).
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/manageengine_mdm_mapping_web.php
 * config.php must point at the scratch database and set $config_https_only = FALSE.
 */
require __DIR__ . '/manageengine_mdm_lib.php';

use ITFlow\Integrations\ManageEngine\MdmClientFactory;
use ITFlow\Integrations\ManageEngine\MdmDeviceMapper;
use ITFlow\Integrations\ManageEngine\MdmMappingSettings;

if (stripos((string) file_get_contents("$root/config.php"), '$config_https_only = FALSE') === false) { fwrite(STDERR, "config.php needs \$config_https_only = FALSE\n"); exit(2); }

// ---- seed
foreach (['user_role_permissions', 'user_client_permissions', 'users', 'user_roles', 'modules', 'user_settings', 'clients', 'logs', 'audit_events', 'contacts', 'asset_assignments', 'asset_history',
    'mdm_mapping_settings', 'mdm_user_contact_map', 'mdm_field_write_log'] as $t) { $q("DELETE FROM $t"); }
mdm_clean();
foreach (['module_client' => 4, 'module_support' => 5, 'module_admin' => 9] as $m => $id) { $q("INSERT INTO modules SET module_id=$id, module_name='$m'"); }
foreach ([[1, 'Admin', 1], [2, 'Tech', 0], [3, 'Viewer', 0], [4, 'Nothing', 0]] as [$id, $n, $adm]) { $q("INSERT INTO user_roles SET role_id=$id, role_name='$n', role_is_admin=$adm, role_type=1"); }
foreach ([[2, 4, 3], [2, 5, 3], [3, 4, 1], [3, 5, 1]] as [$r, $m, $l]) { $q("INSERT INTO user_role_permissions SET user_role_id=$r, module_id=$m, user_role_permission_level=$l"); }
foreach ([1 => ['admin', 1], 10 => ['tech', 2], 11 => ['viewer', 3], 12 => ['nothing', 4], 13 => ['deptb', 2]] as $uid => [$name, $role]) {
    $q("INSERT INTO users SET user_id=$uid, user_name='$name', user_email='$name@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role");
    $q("INSERT INTO user_settings SET user_id=$uid");
}
$q("INSERT INTO user_client_permissions SET user_id=13, client_id=2");   // user 13 may only see Dept B
$q("INSERT INTO clients SET client_id=1, client_name='Dept A', client_type=''"); $q("INSERT INTO clients SET client_id=2, client_name='Dept B', client_type=''");
$iid = mdm_save_row();
$mkAsset = function (string $name, string $serial, int $client, array $over = []) use ($q, $db, $esc): int {
    $o = $over + ['model' => 'orig-model', 'contact' => 0];
    $q("INSERT INTO assets SET asset_type='Tablet', asset_name='" . $esc($name) . "', asset_serial='" . $esc($serial) . "', asset_make='Apple', asset_model='" . $esc($o['model']) . "', asset_os='orig-os', asset_status='Active', asset_client_id=$client, asset_contact_id=" . (int) $o['contact']);
    return (int) $db->insert_id;
};
$mkContact = function (string $name, string $email, int $client) use ($q, $db, $esc): int { $q("INSERT INTO contacts SET contact_name='" . $esc($name) . "', contact_email='" . $esc($email) . "', contact_client_id=$client"); return (int) $db->insert_id; };
$dev = fn (int $n, string $email = '', array $over = []) => MdmDeviceMapper::normalize(mdm_list_row($n, $over + ($email !== '' ? ['user' => ['user_name' => "U$n", 'user_id' => $n, 'user_email' => $email]] : [])), mdm_detail_row(), mdm_summary_row($n));

$sessdir = sys_get_temp_dir() . '/mdm_map_sess_' . getmypid(); @mkdir($sessdir, 0700);
$prepend = sys_get_temp_dir() . '/mdm_map_prepend_' . getmypid() . '.php';
file_put_contents($prepend, "<?php\ndefine('RIVETIT_MDM_BASE_URL', " . var_export($base, true) . ");\ndefine('RIVETIT_MDM_ACCOUNTS_URL', " . var_export($base, true) . ");\n");
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $webPort = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
$weblog = sys_get_temp_dir() . "/mdm_map_$webPort.log";
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
$S = '/admin/settings_integrations.php?tab=devicesync';
$M = '/agent/mdm_devices.php?view=mapping';
$U = '/agent/mdm_devices.php?view=users';
$adm = fn (string $who, array $f, bool $withCsrf = true) => w($who, 'POST', '/admin/post.php', $f + ($withCsrf ? ['csrf_token' => $GLOBALS['csrf']] : []), $S);
$ag = fn (string $who, array $f, bool $withCsrf = true, string $ref = '/agent/mdm_devices.php?view=mapping') => w($who, 'POST', '/agent/post.php', $f + ($withCsrf ? ['csrf_token' => $GLOBALS['csrf']] : []), $ref);
$cnt = fn (string $t) => (int) $one("SELECT COUNT(*) FROM $t");
$settings = fn () => MdmMappingSettings::load($db, (int) $one('SELECT id FROM manageengine_mdm_integrations LIMIT 1'));
$assetsHash = fn () => md5(json_encode($GLOBALS['rows']('SELECT * FROM assets ORDER BY asset_id')));

// ---- data: linked devices in two departments, unmatched ones, users and contacts
$cAnn = $mkContact('Ann A', 'ann@example.test', 1);
$cBob = $mkContact('Bob B', 'bob@example.test', 2);
$aA = $mkAsset('A ipad', 'DMPX0000001', 1, ['model' => 'old-model']);
$aB = $mkAsset('B ipad', 'DMPX0000002', 2);
$aFreeA = $mkAsset('free A', 'FREE-0001', 1);
$aFreeA2 = $mkAsset('free A two', 'FREE-0002', 1);
$aFreeB = $mkAsset('free B', 'FREE-0003', 2);
(new MdmDeviceMapper($db, $iid))->syncDevices([$dev(1, 'ann@example.test'), $dev(2, 'bob@example.test'), $dev(3, 'carol@example.test'), $dev(4), $dev(5)]);
$uid = fn (int $n) => (int) $GLOBALS['one']("SELECT id FROM mdm_unmatched_devices WHERE mdm_device_id='" . (9007199254740000 + $n) . "'");
$lid = fn (int $n) => (int) $GLOBALS['one']("SELECT id FROM asset_mdm_links WHERE mdm_device_id='" . (9007199254740000 + $n) . "'");
$ok($lid(1) > 0 && $lid(2) > 0 && $uid(3) > 0 && $uid(4) > 0 && $uid(5) > 0, 'setup: two linked devices (departments A and B), three unmatched');

// =====================================================================================================================================
// admin settings: mapping options
$r = w('admin', 'GET', $S);
$ok($r['code'] === 200 && strpos($r['body'], 'Mapping options') !== false && strpos($r['body'], 'Allow MDM to write asset fields') !== false && strpos($r['body'], 'Show in the MDM panel only') !== false, 'admin: the Mapping options card is on the Device Sync tab');
$ok(!preg_match('/name="allow_asset_writes"[^>]*checked/', $r['body']) && !preg_match('/name="assign_contact"[^>]*checked/', $r['body']) && !preg_match('/name="assign_contact_overwrite"[^>]*checked/', $r['body']), 'defaults: master switch, assign contact and overwrite are all OFF in the form');
$ok(strpos($r['body'], 'Warning:') !== false && strpos($r['body'], 'Preview') !== false && strpos($r['body'], 'Always overwrite') !== false && strpos($r['body'], 'Fill only when empty') !== false, 'the form carries the warning, the preview button and both write modes');
$ok(substr_count($r['body'], 'name="fm_target[') === 12 && strpos($r['body'], 'No matching asset column') !== false, 'the field table lists all twelve MDM fields; fields with no asset column cannot be written');
$f = ['save_mdm_mapping' => 1, 'assign_contact' => '1', 'allow_asset_writes' => '1', 'fm_target' => ['model' => 'asset', 'device_name' => 'asset', 'imei' => 'asset', "evil'; DROP" => 'asset'], 'fm_mode' => ['model' => 'overwrite', 'device_name' => 'overwrite', 'imei' => 'overwrite']];
$adm('admin', $f, false);
$ok($settings()['exists'] === false, 'a mapping save without a CSRF token saves nothing');
foreach (['tech', 'viewer', 'nothing', 'deptb'] as $who) {
    $adm($who, $f);
    $ok($settings()['exists'] === false, "non-admin ($who): a mapping save through the admin handler saves nothing");
}
$adm('admin', $f);
$s = $settings();
$ok($s['exists'] && $s['assign_contact'] && !$s['assign_contact_overwrite'] && $s['allow_asset_writes'], 'admin: the options are saved (assign on, overwrite off, master on)');
$ok(array_keys($s['field_map']) === ['device_name', 'model'] && $s['field_map']['model']['mode'] === 'overwrite' && $s['field_map']['device_name']['mode'] === 'fill_empty', 'the stored map has only valid fields; name is forced to fill-when-empty; imei and junk keys are dropped');
$ok((int) $one('SELECT updated_by FROM mdm_mapping_settings') === 1, 'the saving admin is recorded');
$r = w('admin', 'GET', $S);
$ok(preg_match('/name="allow_asset_writes"[^>]*checked/', $r['body']) === 1 && preg_match('/name="assign_contact"[^>]*checked/', $r['body']) === 1, 'the form shows the saved switches');
$logs = json_encode($rows('SELECT * FROM logs')) . json_encode($rows('SELECT * FROM audit_events'));
$ok(strpos($logs, 'mapping options') !== false && strpos($logs, 'good-secret') === false && strpos($logs, 'good-refresh') === false, 'the save is in the action log and the audit trail, without any secret');
// preview: writes nothing
$q("UPDATE assets SET asset_model='old-model' WHERE asset_id=$aA");
$before = $assetsHash();
$adm('admin', ['preview_mdm_field_writes' => 1, 'fm_target' => ['model' => 'asset'], 'fm_mode' => ['model' => 'overwrite']], false);
$adm('tech', ['preview_mdm_field_writes' => 1, 'fm_target' => ['model' => 'asset'], 'fm_mode' => ['model' => 'overwrite']]);
$adm('admin', ['preview_mdm_field_writes' => 1, 'fm_target' => ['model' => 'asset'], 'fm_mode' => ['model' => 'overwrite']]);
$r = w('admin', 'GET', $S);
$ok(strpos($r['body'], 'Nothing was written.') !== false && strpos($r['body'], 'old-model') !== false && strpos($r['body'], 'iPad (9th generation)') !== false && strpos($r['body'], 'A ipad') !== false, 'preview: lists the asset, the current value and the new value');
$ok($assetsHash() === $before && $cnt('mdm_field_write_log') === 0 && $cnt('asset_history') === 0, 'preview: no asset, history or write-log row changed');
$ok(json_encode($settings()['field_map']) === json_encode(['device_name' => ['target' => 'asset', 'mode' => 'fill_empty'], 'model' => ['target' => 'asset', 'mode' => 'overwrite']]) && $settings()['allow_asset_writes'], 'preview: the saved options are untouched by a preview');
$r = w('admin', 'GET', $S);
$ok(strpos($r['body'], 'Preview from') === false, 'the preview result is shown once');
// disabling the integration through the connection form switches the master switch off again
$conn = ['save_mdm_connection' => 1, 'data_center' => 'us', 'client_id' => '1000.TESTCLIENT', 'client_secret' => '', 'refresh_token' => '', 'sync_interval_minutes' => 60, 'stale_days' => 7, 'enrich_details' => '1'];
$adm('admin', $conn);   // no "enabled" => disabled
$ok((int) $one('SELECT enabled FROM manageengine_mdm_integrations') === 0 && $settings()['allow_asset_writes'] === false && $settings()['assign_contact'] === true && count($settings()['field_map']) === 2, 'disabling the integration turns the master switch off again (field map and the other switches stay as they were)');
$adm('admin', $f);
$ok($settings()['allow_asset_writes'] === false, 'saving the master switch while the integration is disabled stores it OFF');
$r = w('admin', 'GET', $S);
$ok(preg_match('/name="allow_asset_writes"[^>]*disabled/', $r['body']) === 1, 'and the switch is greyed out while the integration is disabled');
$q('UPDATE manageengine_mdm_integrations SET enabled=1');
$adm('admin', ['assign_contact' => '1', 'save_mdm_mapping' => 1]);
$ok($settings()['field_map'] === [] && $settings()['allow_asset_writes'] === false, 'a save with an empty table clears the field map (every field back to "panel only")');
$adm('admin', ['save_mdm_mapping' => 1]);
$ok($settings()['assign_contact'] === false, 'and a save with the boxes unticked turns the switches off');

// =====================================================================================================================================
// pages
foreach (['admin', 'tech'] as $who) {
    $r = w($who, 'GET', $M);
    $ok($r['code'] === 200 && strpos($r['body'], 'iPad 1') !== false && strpos($r['body'], 'iPad 3') !== false && strpos($r['body'], 'name="mdm_map_bulk"') !== false && strpos($r['body'], 'Propose matches') !== false, "$who: the Mapping page lists linked and unmatched devices with bulk controls");
}
$r = w('tech', 'GET', $M);
$ok(substr_count($r['body'], 'name="rows[]"') === 5 && strpos($r['body'], 'Replace an existing link') !== false && strpos($r['body'], 'csrf_token') !== false, 'the Mapping page has a checkbox per device, the replace option and a CSRF token');
$r = w('tech', 'GET', $M . '&status=unmatched');
$ok(substr_count($r['body'], 'name="rows[]"') === 3 && strpos($r['body'], 'iPad 1') === false, 'filter: unmatched only');
$r = w('tech', 'GET', $M . '&q=DMPX0000002');
$ok(substr_count($r['body'], 'name="rows[]"') === 1 && strpos($r['body'], 'iPad 2') !== false, 'search: by serial');
$r = w('tech', 'GET', $M . '&q=' . urlencode("x' OR '1'='1") . '&mplatform=' . urlencode("ios' OR 1=1 --") . '&mclient=' . urlencode('1 OR 1=1'));
$ok($r['code'] === 200 && substr_count($r['body'], 'name="rows[]"') === 0 && strpos($r['body'], "OR '1'='1") === false, 'hostile search and filter values are bound, not executed, and not echoed raw');
$r = w('tech', 'GET', $M . '&q=' . urlencode('%'));
$ok($r['code'] === 200 && substr_count($r['body'], 'name="rows[]"') === 0, 'a literal % in the search matches nothing (wildcards are escaped)');
$r = w('tech', 'GET', $M . '&propose=1');
$ok($r['code'] === 200 && strpos($r['body'], 'nothing is linked until you press') !== false, 'Propose matches: the page explains suggestions never link');
$q("UPDATE assets SET asset_name='iPad 3' WHERE asset_id=$aFreeA");
$r = w('tech', 'GET', $M . '&propose=1');
$ok(strpos($r['body'], 'mdm-suggest') !== false && strpos($r['body'], 'same name') !== false && $cnt('asset_mdm_links') === 2, 'Propose matches: an asset with the same name is suggested, and nothing was linked');
$q("UPDATE assets SET asset_name='free A' WHERE asset_id=$aFreeA");
$r = w('viewer', 'GET', $M);
$ok($r['code'] === 200 && strpos($r['body'], 'name="mdm_map_bulk"') === false && strpos($r['body'], 'Propose matches') === false && strpos($r['body'], 'Needs edit access') !== false, 'viewer (level 1): sees the Mapping page read-only, no controls');
$r = w('nothing', 'GET', $M);
$ok($r['code'] !== 200 || strpos($r['body'], 'iPad 1') === false, "a role with no client access cannot open the Mapping page (HTTP {$r['code']})");
foreach ([$M, $U] as $path) {
    $r = w('deptb', 'GET', $path);
    $ok($r['code'] === 200 && strpos($r['body'], 'name="rows[]"') === false && strpos($r['body'], 'iPad 1') === false && strpos($r['body'], 'iPad 3') === false && strpos($r['body'], 'name="map_mdm_user"') === false && strpos($r['body'], '>Mapping<') === false, 'department-scoped user: ' . substr($path, 24) . ' falls back to their own department list (no mapping tabs, no other department\'s devices)');
}
$q('UPDATE manageengine_mdm_integrations SET enabled=1');
(new \ITFlow\Integrations\ManageEngine\MdmUserContactMapper($db, $iid))->refresh();
$r = w('tech', 'GET', $U . '&ustatus=all');
$ok($r['code'] === 200 && strpos($r['body'], 'ann@example.test') !== false && strpos($r['body'], 'Ann A') !== false && strpos($r['body'], 'matched by e-mail') !== false && strpos($r['body'], 'Assign contact from MDM user is OFF') !== false, 'Users page: the e-mail match is shown and the OFF state of the assign switch is said');
$r = w('tech', 'GET', $U);
$ok(strpos($r['body'], 'carol@example.test') !== false && strpos($r['body'], 'Needs mapping') !== false && strpos($r['body'], 'ann@example.test') === false && strpos($r['body'], 'data-kind="contact"') !== false, 'Users page: only users that need mapping by default, each with a contact picker');
$r = w('viewer', 'GET', $U);
$ok($r['code'] === 200 && strpos($r['body'], 'name="map_mdm_user"') === false && strpos($r['body'], 'Needs edit access') !== false, 'viewer: no mapping controls on the Users page');
// ajax asset search
$A1 = '/agent/ajax.php?mdm_search_assets=1&q=';
$r = w('tech', 'GET', $A1 . 'free');
$j = json_decode($r['body'], true);
$ok($r['code'] === 200 && count($j['assets'] ?? []) === 3 && strpos($r['body'], 'DMPX') === false, 'asset search: returns matching live assets by name');
$r = w('tech', 'GET', $A1 . 'DMPX0000001');
$j = json_decode($r['body'], true);
$ok(count($j['assets']) === 1 && $j['assets'][0]['linked_to'] === 'iPad 1', 'asset search: says which device an asset is already linked to');
$ok(json_decode(w('tech', 'GET', $A1 . 'f')['body'], true)['assets'] === [], 'asset search: one character returns nothing');
$ok(json_decode(w('deptb', 'GET', $A1 . 'free')['body'] ?: '{"assets":[]}', true)['assets'] === [], 'asset search: a department-scoped user gets nothing');
$r = w('viewer', 'GET', $A1 . 'free');
$ok($r['code'] === 403 || strpos($r['body'], 'free A') === false, "asset search: a level-1 viewer is refused (HTTP {$r['code']})");
$r = w('nothing', 'GET', $A1 . 'free');
$ok($r['code'] === 403 || strpos($r['body'], 'free A') === false, 'asset search: a role with no client access is refused');
$r = w('tech', 'GET', $A1 . urlencode("x' UNION SELECT 1,2,3,4,5,6 -- "));
$ok($r['code'] === 200 && (json_decode($r['body'], true)['assets'] ?? null) === [], 'asset search: a hostile query is just text');

// =====================================================================================================================================
// every new POST action, for every kind of person
$snap = fn () => md5(json_encode([$GLOBALS['rows']('SELECT * FROM asset_mdm_links ORDER BY id'), $GLOBALS['rows']('SELECT * FROM mdm_unmatched_devices ORDER BY id'), $GLOBALS['rows']('SELECT * FROM mdm_user_contact_map ORDER BY id'), $GLOBALS['rows']('SELECT * FROM assets ORDER BY asset_id'), $GLOBALS['rows']('SELECT * FROM asset_assignments')]));
$entryCarol = (int) $one("SELECT id FROM mdm_user_contact_map WHERE user_key='carol@example.test'");
$entryAnn = (int) $one("SELECT id FROM mdm_user_contact_map WHERE user_key='ann@example.test'");
$q("UPDATE mdm_mapping_settings SET assign_contact=1");
$tokU3 = 'u' . $uid(3); $tokL1 = 'l' . $lid(1);
$actions = [
    'bulk link' => ['mdm_map_bulk' => 'link', 'rows' => [$tokU3], 'asset_for' => [$tokU3 => $aFreeA]],
    'bulk unlink' => ['mdm_map_bulk' => 'unlink', 'rows' => [$tokL1]],
    'bulk ignore' => ['mdm_map_bulk' => 'ignore', 'rows' => [$tokU3]],
    'bulk restore' => ['mdm_map_bulk' => 'restore', 'rows' => [$tokU3]],
    'single link' => ['mdm_map_one' => "$tokU3|link", 'asset_for' => [$tokU3 => $aFreeA]],
    'single re-link' => ['mdm_map_one' => "$tokL1|link", 'asset_for' => [$tokL1 => $aFreeA]],
    'single unlink' => ['mdm_map_one' => "$tokL1|unlink"],
    'single ignore' => ['mdm_map_one' => "$tokU3|ignore"],
    'bulk link on a department-B asset' => ['mdm_map_bulk' => 'link', 'rows' => [$tokU3], 'asset_for' => [$tokU3 => $aFreeB]],
    'map user' => ['map_mdm_user' => 1, 'entry_id' => $entryCarol, 'contact_id' => $cBob],
    'ignore user' => ['ignore_mdm_user' => 1, 'entry_id' => $entryCarol],
    'clear user mapping' => ['unmap_mdm_user' => 1, 'entry_id' => $entryAnn],
    'rescan users' => ['refresh_mdm_users' => 1],
    'assign contacts now' => ['assign_mdm_contacts' => 'apply'],
    'preview assign contacts' => ['assign_mdm_contacts' => 'preview'],
    'set contact from the asset panel' => ['assign_mdm_contact_asset' => 1, 'link_id' => $lid(2)],
];
$before = $snap(); $logsBefore = $cnt('logs');
foreach (['viewer' => 'viewer (level 1)', 'nothing' => 'no client access', 'deptb' => 'department-scoped technician'] as $who => $label) {
    foreach ($actions as $name => $f) {
        $ag($who, $f);
    }
    $ok($snap() === $before, "$label: refused on all " . count($actions) . ' new actions (single and bulk): nothing changed');
}
foreach ($actions as $name => $f) { $ag('tech', $f, false); }
$ok($snap() === $before, 'tech without a CSRF token: every new action is refused');
foreach ($actions as $name => $f) { w('tech', 'POST', '/agent/post.php', $f + ['csrf_token' => 'wrong'], $M); }
$ok($snap() === $before, 'tech with a wrong CSRF token: every new action is refused');
// the scoped technician is refused even for their OWN department's records
$r = $ag('deptb', ['mdm_map_bulk' => 'link', 'rows' => ['u' . $uid(4)], 'asset_for' => ['u' . $uid(4) => $aB]]);
$r = $ag('deptb', ['mdm_map_one' => 'l' . $lid(2) . '|unlink']);
$r = $ag('deptb', ['mdm_map_bulk' => 'unlink', 'rows' => ['l' . $lid(2)]]);
$ok($snap() === $before && $lid(2) > 0, 'scoped technician: even their own department\'s link cannot be unlinked or re-linked through the mapping actions (single or bulk)');
$ok($cnt('logs') === $logsBefore, 'refused actions wrote no action-log entry');

// ---- allowed paths: tech
$ag('tech', ['mdm_map_bulk' => 'link', 'rows' => [$tokU3, 'u' . $uid(4)], 'asset_for' => [$tokU3 => $aFreeA, 'u' . $uid(4) => $aFreeA2]]);
$ok((int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740003'") === $aFreeA && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740004'") === $aFreeA2 && $uid(3) === 0 && $uid(4) === 0, 'tech: bulk link links both devices and clears them from the review list');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type='MDM Device' AND log_description LIKE '%bulk MDM device action%'") === 1 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_type='MDM Device' AND log_action='Link'") >= 2, 'tech: the action log has one entry per link and one for the bulk run');
$r = w('tech', 'GET', $M);
$ok(strpos($r['body'], 'device(s) linked') !== false, 'tech: the result is shown after the redirect');
// conflict through the web: device 5 -> an asset that already has a link
$ag('tech', ['mdm_map_one' => 'u' . $uid(5) . '|link', 'asset_for' => ['u' . $uid(5) => $aFreeA]]);
$r = w('tech', 'GET', $M);
$ok($uid(5) > 0 && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740003'") === $aFreeA && strpos($r['body'], 'only one MDM link') !== false, 'tech: linking to an asset that already has a device is refused with the explanation');
$ag('tech', ['mdm_map_one' => 'u' . $uid(5) . '|link', 'asset_for' => ['u' . $uid(5) => $aFreeA], 'replace' => '1']);
$ok((int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740005'") === $aFreeA && $uid(3) > 0 && $one("SELECT reason FROM mdm_unmatched_devices WHERE id=" . $uid(3)) === 'unlinked', 'tech: with "Replace an existing link" the device takes the asset and the old one returns to the review list');
$tokU3 = 'u' . $uid(3);
$ag('tech', ['mdm_map_one' => "$tokU3|ignore"]);
$ok((int) $one("SELECT dismissed FROM mdm_unmatched_devices WHERE id=" . $uid(3)) === 1, 'tech: ignore');
$ag('tech', ['mdm_map_bulk' => 'restore', 'rows' => [$tokU3]]);
$ok((int) $one("SELECT dismissed FROM mdm_unmatched_devices WHERE id=" . $uid(3)) === 0, 'tech: restore');
$ag('tech', ['mdm_map_bulk' => 'unlink', 'rows' => ['l' . $lid(5)]]);
$ok($lid(5) === 0 && $uid(5) > 0, 'tech: bulk unlink');
$ag('tech', ['mdm_map_bulk' => 'link', 'rows' => ['u' . $uid(5)], 'asset_for' => ['u' . $uid(5) => 999999]]);
$ok($uid(5) > 0, 'tech: a missing asset id changes nothing');
// the department rule on the asset side for a role that is unscoped but whose access list restricts (admin sees everything; tech has no restriction)
$r = $ag('admin', ['mdm_map_bulk' => 'unlink', 'rows' => ['l' . $lid(2)]]);
$ok($lid(2) === 0 && (int) $one("SELECT COUNT(*) FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740002'") === 1, 'admin: unlink works for any department');
$ag('admin', ['mdm_map_bulk' => 'link', 'rows' => ['u' . $uid(2)], 'asset_for' => ['u' . $uid(2) => $aB]]);
$ok($lid(2) > 0, 'admin: link back');
// user -> contact through the web
$entryCarol = (int) $one("SELECT id FROM mdm_user_contact_map WHERE user_key='carol@example.test'");
$ag('tech', ['map_mdm_user' => 1, 'entry_id' => $entryCarol, 'contact_id' => 0], true, $U);
$ok($one("SELECT contact_id FROM mdm_user_contact_map WHERE id=$entryCarol") === null, 'tech: mapping with no contact chosen changes nothing');
$ag('tech', ['map_mdm_user' => 1, 'entry_id' => $entryCarol, 'contact_id' => 999999], true, $U);
$ok($one("SELECT contact_id FROM mdm_user_contact_map WHERE id=$entryCarol") === null, 'tech: mapping to a missing contact changes nothing');
$q("UPDATE contacts SET contact_archived_at=NOW() WHERE contact_id=$cBob");
$ag('tech', ['map_mdm_user' => 1, 'entry_id' => $entryCarol, 'contact_id' => $cBob], true, $U);
$ok($one("SELECT contact_id FROM mdm_user_contact_map WHERE id=$entryCarol") === null, 'tech: mapping to an archived contact is refused');
$q("UPDATE contacts SET contact_archived_at=NULL WHERE contact_id=$cBob");
// Carol's device is linked to a department-A asset, so a department-B contact is outside the picker rule
$ag('tech', ['mdm_map_bulk' => 'link', 'rows' => ['u' . $uid(3)], 'asset_for' => ['u' . $uid(3) => $aFreeA2], 'replace' => '1']);
$ok($lid(3) > 0 && (int) $one("SELECT asset_client_id FROM assets a JOIN asset_mdm_links l ON l.asset_id=a.asset_id WHERE l.id=" . $lid(3)) === 1, 'setup: Carol\'s device is linked to a department-A asset');
$ag('tech', ['map_mdm_user' => 1, 'entry_id' => $entryCarol, 'contact_id' => $cBob], true, $U);
$ok($one("SELECT contact_id FROM mdm_user_contact_map WHERE id=$entryCarol") === null, 'tech: a contact outside the department of the user\'s device is refused by the picker rule (still unmapped)');
$cCarol = $mkContact('Carol A', 'carol-other@example.test', 1);
$ag('tech', ['map_mdm_user' => 1, 'entry_id' => $entryCarol, 'contact_id' => $cCarol], true, $U);
$ok((int) $one("SELECT contact_id FROM mdm_user_contact_map WHERE id=$entryCarol") === $cCarol && $one("SELECT source FROM mdm_user_contact_map WHERE id=$entryCarol") === 'manual', 'tech: a contact from the device\'s department is accepted (source manual)');
$ag('tech', ['ignore_mdm_user' => 1, 'entry_id' => $entryCarol], true, $U);
$ok((int) $one("SELECT ignored FROM mdm_user_contact_map WHERE id=$entryCarol") === 1 && $one("SELECT contact_id FROM mdm_user_contact_map WHERE id=$entryCarol") === null, 'tech: ignore a user');
$ag('tech', ['unmap_mdm_user' => 1, 'entry_id' => $entryCarol], true, $U);
$ok((int) $one("SELECT ignored FROM mdm_user_contact_map WHERE id=$entryCarol") === 0, 'tech: clear the choice');
$b4 = $snap();
$ag('tech', ['map_mdm_user' => 1, 'entry_id' => 999999, 'contact_id' => $cCarol], true, $U);
$ok($snap() === $b4, 'tech: an unknown entry id changes nothing');
$ag('tech', ['map_mdm_user' => 1, 'entry_id' => $entryCarol, 'contact_id' => $cCarol], true, $U);
// assignment through the web: switch off -> refused, switch on -> only an empty contact is filled
$q("UPDATE mdm_mapping_settings SET assign_contact=0");
$bA = $assetsHash();
$ag('tech', ['assign_mdm_contacts' => 'apply'], true, $U);
$ag('tech', ['assign_mdm_contact_asset' => 1, 'link_id' => $lid(1)], true, $U);
$ok($assetsHash() === $bA, 'assign with the switch OFF: bulk and single both refuse and change nothing');
$q("UPDATE mdm_mapping_settings SET assign_contact=1");
$r = w('tech', 'GET', "/agent/asset_details.php?client_id=1&asset_id=$aA");
$ok(strpos($r['body'], 'Set contact from MDM user') !== false, 'asset panel: with the switch on and Ann mapped, the "Set contact from MDM user" offer appears');
$q("UPDATE mdm_mapping_settings SET assign_contact=0");
$r = w('tech', 'GET', "/agent/asset_details.php?client_id=1&asset_id=$aA");
$ok(strpos($r['body'], 'Set contact from MDM user') === false && strpos($r['body'], 'ManageEngine MDM: ') !== false, 'asset panel: no offer while the switch is off');
$r = w('viewer', 'GET', "/agent/asset_details.php?client_id=1&asset_id=$aA");
$q("UPDATE mdm_mapping_settings SET assign_contact=1");
$r = w('viewer', 'GET', "/agent/asset_details.php?client_id=1&asset_id=$aA");
$ok(strpos($r['body'], 'Set contact from MDM user') === false, 'asset panel: a level-1 viewer is never offered it');
$r = w('deptb', 'GET', "/agent/asset_details.php?client_id=2&asset_id=$aB");
$ok(strpos($r['body'], 'Set contact from MDM user') === false, 'asset panel: a department-scoped user is never offered it');
$ag('tech', ['assign_mdm_contacts' => 'preview'], true, $U);
$ok($assetsHash() === $bA, 'preview assign: writes nothing');
$ag('tech', ['assign_mdm_contact_asset' => 1, 'link_id' => $lid(1)], true, $U);
$ok((int) $one("SELECT asset_contact_id FROM assets WHERE asset_id=$aA") === $cAnn && $cnt('asset_assignments') === 1, 'assign (single, from the asset panel): the empty contact is filled and history recorded');
$ag('tech', ['assign_mdm_contacts' => 'apply'], true, $U);
$ok((int) $one("SELECT asset_contact_id FROM assets WHERE asset_id=$aB") === 0 || (int) $one("SELECT asset_contact_id FROM assets WHERE asset_id=$aB") === $cBob, 'assign (bulk) ran');
$q("UPDATE assets SET asset_contact_id=$cAnn WHERE asset_id=$aB");
$ag('tech', ['assign_mdm_contacts' => 'apply'], true, $U);
$ok((int) $one("SELECT asset_contact_id FROM assets WHERE asset_id=$aB") === $cAnn, 'assign (bulk, overwrite off): a contact that is already set is kept');
$r = $ag('tech', ['rescan' => 1, 'refresh_mdm_users' => 1], true, $U);
$ok($cnt('mdm_user_contact_map') >= 3, 'tech: rescan users works');
$allLogs = json_encode($rows('SELECT * FROM logs')) . json_encode($rows('SELECT * FROM audit_events')) . json_encode($rows('SELECT * FROM asset_history'));
$ok(strpos($allLogs, 'good-secret') === false && strpos($allLogs, 'good-refresh') === false && strpos($allLogs, 'APN-SECRET') === false, 'no log, audit or history entry from any of the above holds a secret');
$ok(strpos((string) @file_get_contents($weblog), 'Fatal') === false, 'the web server log has no PHP fatal error from any of the above');
