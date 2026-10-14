<?php
/*
 * ManageEngine MDM mapping options (src/Integrations/ManageEngine): user -> contact (e-mail match, ambiguity, picker rules, assignment rules and
 * history), field mapping (nothing writes by default, fill-empty vs overwrite, preview writes nothing, master switch, audit rows), the bulk
 * device -> asset mapping actions (access rule on every row, conflicts, replace), the match suggester, the sync hook against the LOCAL mock,
 * and the 2.6.169 migration. Service level; the HTTP side (scoped users, CSRF, pages) is tests/manageengine_mdm_mapping_web.php.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/manageengine_mdm_mapping.php
 */
require __DIR__ . '/manageengine_mdm_lib.php';

use ITFlow\Integrations\ManageEngine\MdmDeviceMapper;
use ITFlow\Integrations\ManageEngine\MdmDeviceMapping;
use ITFlow\Integrations\ManageEngine\MdmFieldWriter;
use ITFlow\Integrations\ManageEngine\MdmMappingSettings;
use ITFlow\Integrations\ManageEngine\MdmMatchSuggester;
use ITFlow\Integrations\ManageEngine\MdmSyncService;
use ITFlow\Integrations\ManageEngine\MdmUserContactMapper;

function map_clean(): void
{
    global $q;
    mdm_clean();
    foreach (['mdm_mapping_settings', 'mdm_user_contact_map', 'mdm_field_write_log', 'asset_assignments', 'asset_history', 'audit_events', 'logs', 'contacts', 'clients', 'asset_sync_state'] as $t) {
        $q("DELETE FROM $t");
    }
}
$mkAsset = function (string $name, string $serial, int $client = 0, array $over = []) use ($q, $db, $esc): int {
    $o = $over + ['model' => 'orig-model', 'os' => 'orig-os', 'contact' => 0, 'type' => 'Tablet'];
    $q("INSERT INTO assets SET asset_type='{$o['type']}', asset_name='" . $esc($name) . "', asset_serial='" . $esc($serial) . "', asset_make='Apple', asset_model='" . $esc($o['model']) . "', asset_os='" . $esc($o['os']) . "', asset_status='Active', asset_client_id=$client, asset_contact_id=" . (int) $o['contact']);
    return (int) $db->insert_id;
};
$mkContact = function (string $name, string $email, int $client, bool $archived = false) use ($q, $db, $esc): int {
    $q("INSERT INTO contacts SET contact_name='" . $esc($name) . "', contact_email='" . $esc($email) . "', contact_client_id=$client, contact_archived_at=" . ($archived ? 'NOW()' : 'NULL'));
    return (int) $db->insert_id;
};
$dev = fn (int $n, array $listOver = [], array $detailOver = []) => MdmDeviceMapper::normalize(mdm_list_row($n, $listOver), mdm_detail_row($detailOver), mdm_summary_row($n));
$user = fn (string $name, string $email) => ['user' => ['user_name' => $name, 'user_id' => 1, 'user_email' => $email]];
$assetsHash = fn () => md5(json_encode($GLOBALS['rows']('SELECT * FROM assets ORDER BY asset_id')));
$cnt = fn (string $t) => (int) $one("SELECT COUNT(*) FROM $t");

// =====================================================================================================================================
// settings: everything off by default; the stored map can only ever name known fields and allowed modes
map_clean();
$iid = mdm_save_row();
$s = MdmMappingSettings::load($db, $iid);
$ok($s['assign_contact'] === false && $s['assign_contact_overwrite'] === false && $s['allow_asset_writes'] === false && $s['field_map'] === [] && $s['exists'] === false, 'defaults: with no row every option is off and the field map is empty (everything is "panel only")');
$ok(MdmMappingSettings::schemaReady($db), 'the three mapping tables exist');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mdm_mapping_settings' AND column_name IN ('assign_contact','assign_contact_overwrite','allow_asset_writes') AND column_default='0'") === 3, 'the three switches default to 0 in the schema');
$m = MdmMappingSettings::cleanFieldMap([
    'device_name' => ['target' => 'asset', 'mode' => 'overwrite'], 'model' => ['target' => 'asset', 'mode' => 'overwrite'], 'os' => ['target' => 'asset'],
    'serial_number' => ['target' => 'asset', 'mode' => 'overwrite'], 'imei' => ['target' => 'asset', 'mode' => 'fill_empty'], 'battery_level' => ['target' => 'asset'],
    'udid' => ['target' => 'panel'], 'passcode_compliant' => 'asset', 'drop_table' => ['target' => 'asset'], 'group_names' => ['target' => 'asset; DROP TABLE assets'],
]);
$ok($m['device_name']['mode'] === 'fill_empty' && $m['serial_number']['mode'] === 'fill_empty', 'cleanFieldMap: name and serial can only ever fill when empty, even if "overwrite" is posted');
$ok($m['model']['mode'] === 'overwrite' && $m['os']['mode'] === 'fill_empty', 'cleanFieldMap: model overwrite is allowed; a missing mode becomes fill_empty');
$ok(!isset($m['imei']) && !isset($m['battery_level']) && !isset($m['udid']) && !isset($m['passcode_compliant']) && !isset($m['drop_table']) && !isset($m['group_names']), 'cleanFieldMap: fields with no asset column, "panel", unknown keys and junk targets are dropped');
$ok(MdmMappingSettings::cleanFieldMap('nonsense') === [] && MdmMappingSettings::cleanFieldMap(null) === [], 'cleanFieldMap: junk input is an empty map');
MdmMappingSettings::save($db, $iid, true, false, true, ['model' => ['target' => 'asset', 'mode' => 'overwrite'], 'bogus' => ['target' => 'asset']], 7);
$s = MdmMappingSettings::load($db, $iid);
$ok($s['assign_contact'] && !$s['assign_contact_overwrite'] && $s['allow_asset_writes'] && array_keys($s['field_map']) === ['model'] && $s['exists'], 'save/load round trip keeps only the valid field');
MdmMappingSettings::switchWritesOff($db, $iid);
$ok(MdmMappingSettings::load($db, $iid)['allow_asset_writes'] === false && MdmMappingSettings::load($db, $iid)['assign_contact'] === true, 'switchWritesOff turns only the master switch off');

// =====================================================================================================================================
// user -> contact
$ok(MdmUserContactMapper::userKey(' Ann@Example.TEST ', 'x') === 'ann@example.test', 'userKey: the lower-cased e-mail');
$ok(MdmUserContactMapper::userKey('', ' Kiosk Student ') === 'name:kiosk student' && MdmUserContactMapper::userKey('not-an-email', 'Pat') === 'name:pat', 'userKey: no usable e-mail falls back to "name:" + user name');
$ok(MdmUserContactMapper::userKey('', '') === '' && MdmUserContactMapper::userKey(null, null) === '', 'userKey: no user is no key');

map_clean();
$iid = mdm_save_row();
$q("INSERT INTO clients SET client_id=1, client_name='Dept A', client_type=''"); $q("INSERT INTO clients SET client_id=2, client_name='Dept B', client_type=''");
$cAnn = $mkContact('Ann A', 'ann@example.test', 1);
$cBob = $mkContact('Bob B', 'BOB@Example.TEST ', 2);
$cDupA = $mkContact('Dup in A', 'dup@example.test', 1);
$cDupB = $mkContact('Dup in B', 'dup@example.test', 2);
$cTwinA = $mkContact('Twin in A', 'twin@example.test', 1);
$cTwinB = $mkContact('Twin in B', 'twin@example.test', 2);
$cArch = $mkContact('Archived Al', 'arch@example.test', 1, true);
$aAnn = $mkAsset('ann ipad', 'DMPX0000001', 1);
$aBob = $mkAsset('bob ipad', 'DMPX0000002', 2);
$aDup = $mkAsset('dup ipad', 'DMPX0000003', 1);
$aKiosk = $mkAsset('kiosk ipad', 'DMPX0000004', 1);
$aNone = $mkAsset('nouser ipad', 'DMPX0000005', 1);
$aNobody = $mkAsset('nobody ipad', 'DMPX0000006', 1);
$mapper = new MdmDeviceMapper($db, $iid);
$mapper->syncDevices([
    $dev(1, $user('Ann', 'ann@example.test')), $dev(2, $user('Bob', 'bob@example.test')), $dev(3, $user('Dup', 'dup@example.test')),
    $dev(4, $user('Kiosk Student', '')), $dev(5, ['user' => []]), $dev(6, $user('Nobody', 'nobody@example.test')),
    $dev(7, $user('Twin', 'twin@example.test')),            // unmatched device (no asset): no department to narrow with
    $dev(8, $user('Arch', 'arch@example.test')),            // only an archived contact has this address
]);
$users = new MdmUserContactMapper($db, $iid);
$r = $users->refresh();
$ok($r['users'] === 7 && $cnt('mdm_user_contact_map') === 7, 'refresh: one row per distinct MDM user (the device with no user adds none)');
$byKey = fn (string $k) => $GLOBALS['rows']("SELECT * FROM mdm_user_contact_map WHERE user_key='" . $GLOBALS['esc']($k) . "'")[0] ?? null;
$ok((int) $byKey('ann@example.test')['contact_id'] === $cAnn && $byKey('ann@example.test')['source'] === 'auto', 'refresh: an exact e-mail match is auto-mapped');
$ok((int) $byKey('bob@example.test')['contact_id'] === $cBob, 'refresh: matching ignores case and stray spaces on the contact side');
$ok((int) $byKey('dup@example.test')['contact_id'] === $cDupA, 'refresh: two contacts share the address, the device\'s department (A) picks exactly one');
$ok($byKey('twin@example.test')['contact_id'] === null, 'refresh: two contacts share the address and the device has no department: NOT mapped (Needs mapping)');
$ok($byKey('arch@example.test')['contact_id'] === null && $byKey('nobody@example.test')['contact_id'] === null, 'refresh: an archived contact and a user nobody has are not mapped');
$ok($byKey('name:kiosk student') !== null && $byKey('name:kiosk student')['contact_id'] === null, 'refresh: a user with no e-mail is listed under "name:" and never auto-mapped');
$users->setChoice((int) $byKey('nobody@example.test')['id'], null, true, 5);
$cNobody = $mkContact('Nobody N', 'nobody@example.test', 1);
$users->refresh();
$ok((int) $byKey('nobody@example.test')['ignored'] === 1 && $byKey('nobody@example.test')['contact_id'] === null, 'refresh: a person\'s choice (ignore) is never overridden, even when a matching contact appears');
$users->setChoice((int) $byKey('nobody@example.test')['id'], null, false, 5);
$users->refresh();
$ok($byKey('nobody@example.test')['contact_id'] === null, 'refresh: a user a person cleared (source manual) is not auto-mapped again');
$q("UPDATE contacts SET contact_archived_at=NOW() WHERE contact_id=$cAnn");
$users->refresh();
$ok($byKey('ann@example.test')['contact_id'] === null, 'refresh: an auto-mapping whose contact was archived goes back to "Needs mapping"');
$q("UPDATE contacts SET contact_archived_at=NULL WHERE contact_id=$cAnn");
$users->refresh();
$ok((int) $byKey('ann@example.test')['contact_id'] === $cAnn, 'refresh: and is auto-mapped again when the contact returns');
$r2 = $users->refresh();
$ok($r2['new'] === 0 && $cnt('mdm_user_contact_map') === 7, 'refresh is idempotent');

// picker rules (validateChoice)
$ok($users->validateChoice('twin@example.test', $cTwinB)['ok'] === true, 'picker: a user with no device department may be mapped to any live contact');
$v = $users->validateChoice('ann@example.test', $cBob);
$ok($v['ok'] === false && stripos($v['message'], 'department') !== false, 'picker: a contact outside the department of the user\'s device is refused');
$ok($users->validateChoice('ann@example.test', $cDupA)['ok'] === true, 'picker: a contact in the device\'s department is accepted');
$ok($users->validateChoice('twin@example.test', $cArch)['ok'] === false && $users->validateChoice('twin@example.test', 999999)['ok'] === false, 'picker: archived and missing contacts are refused');

// assignment rules
$link = fn (int $assetId) => (int) $GLOBALS['one']("SELECT id FROM asset_mdm_links WHERE asset_id=$assetId");
$contactOf = fn (int $assetId) => (int) $GLOBALS['one']("SELECT asset_contact_id FROM assets WHERE asset_id=$assetId");
$off = MdmMappingSettings::defaults();
$on = ['assign_contact' => true, 'assign_contact_overwrite' => false] + $off;
$ow = ['assign_contact' => true, 'assign_contact_overwrite' => true] + $off;
$before = $assetsHash();
$e = $users->assignForLink($link($aAnn), $off, 1);
$ok($e['status'] === 'disabled' && $assetsHash() === $before && $cnt('asset_assignments') === 0, 'assign: with the switch OFF (default) nothing is written, no history row');
$ok($users->assignAll($off, 1)['disabled'] > 0 && $assetsHash() === $before, 'assignAll with the switch off: every link "disabled", nothing written');
$e = $users->evaluate($link($aAnn), $on);
$ok($e['status'] === 'assign' && $e['contact_id'] === $cAnn && $assetsHash() === $before, 'evaluate (dry run): says it would assign and writes nothing');
$e = $users->assignForLink($link($aAnn), $on, 5);
$ok($e['status'] === 'assign' && $contactOf($aAnn) === $cAnn, 'assign: an EMPTY asset contact is filled');
$h = $rows("SELECT * FROM asset_assignments WHERE asset_id=$aAnn");
$ok(count($h) === 1 && (int) $h[0]['contact_id'] === $cAnn && (int) $h[0]['assigned_by'] === 5 && $h[0]['returned_at'] === null, 'assign: the assignment history row is recorded through AssetAssignmentService (like a manual change)');
$ok($cnt('asset_history') === 1 && $cnt('audit_events') >= 1, 'assign: an asset history line and an audit event are written');
$ok($users->assignForLink($link($aAnn), $on, 5)['status'] === 'unchanged' && $cnt('asset_assignments') === 1, 'assign: the same contact again is "unchanged" and adds no history');
$q("UPDATE assets SET asset_contact_id=$cBob WHERE asset_id=$aAnn");   // a person picked someone else (through the normal edit path: history too)
(new \ITFlow\Assets\AssetAssignmentService($db))->recordChangeIfNeeded($aAnn, $cBob, 1);
$ok($users->assignForLink($link($aAnn), $on, 5)['status'] === 'kept_existing' && $contactOf($aAnn) === $cBob, 'assign: a contact that is already set is KEPT unless overwrite is on');
$e = $users->assignForLink($link($aAnn), $ow, 5);
$ok($e['status'] === 'assign' && $contactOf($aAnn) === $cAnn, 'assign: with overwrite explicitly on, the different contact is replaced');
$hist = $rows("SELECT contact_id, returned_at FROM asset_assignments WHERE asset_id=$aAnn ORDER BY assignment_id");
$ok(count($hist) === 3 && (int) $hist[2]['contact_id'] === $cAnn && $hist[1]['returned_at'] !== null, 'assign: overwrite records the change in the assignment history');
// department rule: Bob (dept B contact) mapped by hand to a dept-A asset's user
$entryDup = $byKey('dup@example.test');
$q("UPDATE mdm_user_contact_map SET contact_id=$cBob, source='manual' WHERE id=" . (int) $entryDup['id']);
$ok($users->assignForLink($link($aDup), $ow, 5)['status'] === 'other_department' && $contactOf($aDup) === 0, 'assign: a contact from another department than the asset is never assigned');
$q("UPDATE mdm_user_contact_map SET contact_id=$cDupA WHERE id=" . (int) $entryDup['id']);
$ok($users->assignForLink($link($aKiosk), $on, 5)['status'] === 'no_mapping' && $contactOf($aKiosk) === 0, 'assign: an unmapped user (no e-mail) changes nothing');
$ok($users->assignForLink($link($aNone), $on, 5)['status'] === 'no_user', 'assign: a device with no user changes nothing');
$q("UPDATE mdm_user_contact_map SET ignored=1, contact_id=NULL WHERE user_key='dup@example.test'");
$ok($users->assignForLink($link($aDup), $on, 5)['status'] === 'ignored' && $contactOf($aDup) === 0, 'assign: an ignored user changes nothing');
$q("UPDATE mdm_user_contact_map SET ignored=0, contact_id=$cDupA WHERE user_key='dup@example.test'");
$q("UPDATE contacts SET contact_archived_at=NOW() WHERE contact_id=$cDupA");
$ok($users->assignForLink($link($aDup), $on, 5)['status'] === 'contact_gone' && $contactOf($aDup) === 0, 'assign: an archived mapped contact is never assigned');
$q("UPDATE contacts SET contact_archived_at=NULL WHERE contact_id=$cDupA");
$q("UPDATE assets SET asset_client_id=0 WHERE asset_id=$aDup");
$ok($users->assignForLink($link($aDup), $on, 5)['status'] === 'assign' && $contactOf($aDup) === $cDupA, 'assign: an asset with no department takes the mapped contact');
$c = $users->assignAll($on, 5, true);
$h1 = $assetsHash(); $users->assignAll($on, 5, true);
$ok($h1 === $assetsHash() && isset($c['no_mapping']), 'assignAll(dry run) counts statuses and writes nothing');

// =====================================================================================================================================
// field mapping
map_clean();
$iid = mdm_save_row();
$a1 = $mkAsset('fm one', 'DMPX0000001', 1, ['model' => 'old-model-1', 'os' => 'old-os-1']);
$a2 = $mkAsset('fm two', 'DMPX0000002', 1, ['model' => '', 'os' => '']);
$a3 = $mkAsset('fm three', '', 1, ['model' => 'keep3', 'os' => 'keep3']);       // empty serial: matched by hand below
$mapper = new MdmDeviceMapper($db, $iid);
$mapper->syncDevices([$dev(1), $dev(2)]);
$q("INSERT INTO asset_mdm_links SET asset_id=$a3, integration_id=$iid, mdm_device_id='manual-3', match_method='manual', device_name='Manual Three', platform='iOS', os_version='16.0', model='iPad Air', serial_number='SER-FROM-MDM-3', enrollment_status='enrolled'");
$w = new MdmFieldWriter($db, $iid);
$map = fn (array $m, bool $master = true) => ['field_map' => $m, 'allow_asset_writes' => $master] + MdmMappingSettings::defaults();
$ok($w->plan(MdmMappingSettings::defaults())['changes'] === [], 'default settings: the plan is empty (every field is "panel only")');
$before = $assetsHash();
$res = $w->apply(MdmMappingSettings::defaults(), 0, 1);
$ok($res['written'] === 0 && $res['blocked'] !== '' && $assetsHash() === $before, 'default settings: apply is blocked (master switch off) and writes nothing');
$res = $w->apply($map(['model' => ['target' => 'asset', 'mode' => 'overwrite']], false), 0, 1);
$ok($res['written'] === 0 && $res['blocked'] !== '' && $assetsHash() === $before && $cnt('mdm_field_write_log') === 0, 'a field map with the master switch OFF writes nothing at all');
// preview == plan: no writes
$plan = $w->plan($map(['model' => ['target' => 'asset', 'mode' => 'overwrite'], 'os' => ['target' => 'asset', 'mode' => 'fill_empty']]));
$ok($assetsHash() === $before && $cnt('mdm_field_write_log') === 0 && $cnt('asset_history') === 0 && $cnt('audit_events') === 0, 'plan (the preview) makes no write of any kind');
$by = [];
foreach ($plan['changes'] as $c) { $by[$c['asset_id'] . ':' . $c['field']] = $c; }
$ok(isset($by["$a1:model"]) && $by["$a1:model"]['old'] === 'old-model-1' && $by["$a1:model"]['new'] === 'iPad (9th generation)', 'plan: overwrite model shows old and new for an asset that has another model');
$ok(isset($by["$a2:model"]) && isset($by["$a2:os"]) && $by["$a2:os"]['new'] === 'iOS 17.5.1', 'plan: empty fields are filled (OS label "iOS 17.5.1")');
$ok(!isset($by["$a1:os"]) && !isset($by["$a3:os"]), 'plan: fill-only OS does not touch an asset that already has an OS');
$ok($plan['examined'] === 3, 'plan: examines the linked, enrolled assets');
// apply: master on + integration enabled
$res = $w->apply($map(['model' => ['target' => 'asset', 'mode' => 'overwrite'], 'os' => ['target' => 'asset', 'mode' => 'fill_empty']]), 55, 1);
$ok($res['blocked'] === '' && $res['written'] === 4 && $res['assets'] === 3, 'apply: writes exactly what the plan listed (4 fields on 3 assets)');
$ok($one("SELECT asset_model FROM assets WHERE asset_id=$a1") === 'iPad (9th generation)' && $one("SELECT asset_os FROM assets WHERE asset_id=$a1") === 'old-os-1', 'apply: overwrite replaced the model; fill left the existing OS alone');
$ok($one("SELECT asset_os FROM assets WHERE asset_id=$a2") === 'iOS 17.5.1' && $one("SELECT asset_os FROM assets WHERE asset_id=$a3") === 'keep3', 'apply: empty OS filled, typed OS kept');
$log = $rows("SELECT * FROM mdm_field_write_log WHERE asset_id=$a1 AND field_key='model'");
$ok(count($log) === 1 && $log[0]['old_value'] === 'old-model-1' && $log[0]['new_value'] === 'iPad (9th generation)' && $log[0]['mode'] === 'overwrite' && (int) $log[0]['sync_log_id'] === 55 && $log[0]['asset_column'] === 'asset_model', 'audit: one write-log row names the field, column, mode, old and new value');
$hist = $rows("SELECT asset_history_description FROM asset_history WHERE asset_history_asset_id=$a1");
$ok(count($hist) === 1 && strpos($hist[0]['asset_history_description'], 'model') !== false && strpos($hist[0]['asset_history_description'], 'old-model-1') !== false, 'audit: the asset history names the field and the old and new value');
$ae = $rows("SELECT * FROM audit_events WHERE event_type='integration.manageengine_mdm.asset_field_write'");
$ok(count($ae) === 4 && strpos($ae[0]['metadata_json'], '"field"') !== false, 'audit: one audit event per written field');
$all = json_encode([$rows('SELECT * FROM mdm_field_write_log'), $rows('SELECT * FROM asset_history'), $ae]);
$ok(strpos($all, 'APN-SECRET-VALUE') === false && strpos($all, 'good-secret') === false && strpos($all, 'good-refresh') === false && stripos($all, 'apn_password') === false, 'audit: no secret (APN password, client secret, token) is in any write record');
$res = $w->apply($map(['model' => ['target' => 'asset', 'mode' => 'overwrite'], 'os' => ['target' => 'asset', 'mode' => 'fill_empty']]), 56, 1);
$ok($res['written'] === 0 && $cnt('mdm_field_write_log') === 4, 'apply again: idempotent, nothing more to write');
// serial and name: fill only, never replace; duplicates skipped
$b = $assetsHash();
$plan = $w->plan($map(['serial_number' => ['target' => 'asset', 'mode' => 'overwrite'], 'device_name' => ['target' => 'asset', 'mode' => 'overwrite']]));
$sers = array_filter($plan['changes'], fn ($c) => $c['field'] === 'serial_number');
$ok(count($sers) === 1 && array_values($sers)[0]['asset_id'] === $a3 && array_values($sers)[0]['new'] === 'SER-FROM-MDM-3' && array_values($sers)[0]['mode'] === 'fill_empty', 'serial: only the asset with an EMPTY serial is filled (the others keep theirs, whatever the posted mode)');
$q("UPDATE assets SET asset_name='' WHERE asset_id=$a2");
$plan = $w->plan($map(['device_name' => ['target' => 'asset', 'mode' => 'overwrite']]));
$ok(count($plan['changes']) === 1 && $plan['changes'][0]['asset_id'] === $a2 && $plan['changes'][0]['new'] === 'iPad 2', 'name: only an empty asset name is filled');
$q("UPDATE assets SET asset_name='fm two' WHERE asset_id=$a2");
$mkAsset('someone has it', 'SER-FROM-MDM-3', 1);
$plan = $w->plan($map(['serial_number' => ['target' => 'asset']]));
$ok($plan['changes'] === [] && count($plan['skipped']) === 1 && stripos($plan['skipped'][0]['reason'], 'serial') !== false, 'serial: a serial already on another live asset is skipped and reported');
// non-enrolled and archived are not used
$q("UPDATE asset_mdm_links SET enrollment_status='removed', model='ZZZ-removed' WHERE asset_id=$a1");
$plan = $w->plan($map(['model' => ['target' => 'asset', 'mode' => 'overwrite']]));
$ok(!array_filter($plan['changes'], fn ($c) => $c['asset_id'] === $a1), 'a removed device is never written to the asset (its data is stale)');
$q("UPDATE asset_mdm_links SET enrollment_status='enrolled', model='' , product_name='' WHERE asset_id=$a1");
$plan = $w->plan($map(['model' => ['target' => 'asset', 'mode' => 'overwrite']]));
$ok(!array_filter($plan['changes'], fn ($c) => $c['asset_id'] === $a1), 'a blank MDM value never blanks an asset field');
// master on but integration disabled
$q('UPDATE manageengine_mdm_integrations SET enabled=0');
$q("UPDATE asset_mdm_links SET model='New Model X' WHERE asset_id=$a2");
$res = $w->apply($map(['model' => ['target' => 'asset', 'mode' => 'overwrite']]), 0, 1);
$ok($res['written'] === 0 && stripos($res['blocked'], 'disabled') !== false && $one("SELECT asset_model FROM assets WHERE asset_id=$a2") !== 'New Model X', 'master switch on but the integration disabled: nothing is written');
$q('UPDATE manageengine_mdm_integrations SET enabled=1');
// a value typed between plan and write is not replaced in fill mode (guard in the UPDATE)
$q("UPDATE assets SET asset_model='' WHERE asset_id=$a2");
$stale = $w->plan($map(['model' => ['target' => 'asset', 'mode' => 'fill_empty']]));
$q("UPDATE assets SET asset_model='typed by a person' WHERE asset_id=$a2");
$ok(count($stale['changes']) >= 1 && $one("SELECT asset_model FROM assets WHERE asset_id=$a2") === 'typed by a person', 'precondition for the race test');
$res = $w->apply($map(['model' => ['target' => 'asset', 'mode' => 'fill_empty']]), 0, 1);
$ok($one("SELECT asset_model FROM assets WHERE asset_id=$a2") === 'typed by a person', 'fill mode never replaces a value a person typed');

// =====================================================================================================================================
// the sync hook, end to end against the LOCAL mock
map_clean();
$iid = mdm_save_row();
$reset();
mdm_seed_mock($mock, 3, [1 => ['list' => ['user' => ['user_name' => 'Ann', 'user_id' => 9, 'user_email' => 'ann@example.test']]]]);
$q("INSERT INTO clients SET client_id=1, client_name='Dept A', client_type=''");
$cAnn = $mkContact('Ann A', 'ann@example.test', 1);
$s1 = $mkAsset('sync one', 'DMPX0000001', 1, ['model' => 'orig-model']);
$s2 = $mkAsset('sync two', 'DMPX0000002', 1, ['model' => '']);
$opts = ['mdm_base' => $base, 'accounts_base' => $base, 'sleep' => function () {}];
$before = $assetsHash();
$run = MdmSyncService::run($db, $db->query('SELECT * FROM manageengine_mdm_integrations')->fetch_assoc(), 1, 60, $opts);
$ok($run['ok'] && $run['stats']['matched'] === 2, 'sync with the defaults: runs and links');
$ok($assetsHash() === $before, 'sync with the defaults: NO asset field and no asset contact is written (current behaviour kept)');
$ok($cnt('mdm_field_write_log') === 0 && $cnt('asset_assignments') === 0, 'sync with the defaults: no write-log or assignment row');
$lr = $db->query('SELECT * FROM mdm_sync_log ORDER BY id DESC LIMIT 1')->fetch_assoc();
$ok(strpos((string) $lr['errors'], 'asset field writes') === false && strpos((string) $lr['errors'], 'asset contacts set') === false, 'sync log has no write line while the options are off');
$ok($cnt('mdm_user_contact_map') >= 1 && (int) $one("SELECT contact_id FROM mdm_user_contact_map WHERE user_key='ann@example.test'") === $cAnn, 'sync records the device users and auto-maps the exact e-mail (own table only)');
// turn it all on
MdmMappingSettings::save($db, $iid, true, false, true, ['model' => ['target' => 'asset', 'mode' => 'fill_empty']], 1);
$run = MdmSyncService::run($db, $db->query('SELECT * FROM manageengine_mdm_integrations')->fetch_assoc(), 1, 60, $opts);
$ok($run['ok'], 'sync with the options on: runs');
$ok($one("SELECT asset_model FROM assets WHERE asset_id=$s1") === 'orig-model' && $one("SELECT asset_model FROM assets WHERE asset_id=$s2") === 'iPad (9th generation)', 'sync with fill-empty model on: the empty model is filled, the typed one is kept');
$ok((int) $one("SELECT asset_contact_id FROM assets WHERE asset_id=$s1") === $cAnn && $cnt('asset_assignments') === 1, 'sync with "assign contact" on: the empty asset contact is filled and the history recorded');
$lr = $db->query('SELECT * FROM mdm_sync_log ORDER BY id DESC LIMIT 1')->fetch_assoc();
$ok(strpos((string) $lr['errors'], 'note: asset field writes: 1 field(s) on 1 asset(s)') !== false && strpos((string) $lr['errors'], 'note: asset contacts set from MDM users: 1') !== false && $lr['status'] === 'success', 'the sync log carries one counting line per option and the run stays a success');
$ok((int) $one('SELECT sync_log_id FROM mdm_field_write_log LIMIT 1') === (int) $lr['id'], 'the write-log row points at the sync run that made it');
// master off again: the field map stays but writes stop
MdmMappingSettings::save($db, $iid, true, false, false, ['model' => ['target' => 'asset', 'mode' => 'overwrite']], 1);
$q("UPDATE assets SET asset_model='again-orig' WHERE asset_id=$s1");
MdmSyncService::run($db, $db->query('SELECT * FROM manageengine_mdm_integrations')->fetch_assoc(), 1, 60, $opts);
$ok($one("SELECT asset_model FROM assets WHERE asset_id=$s1") === 'again-orig', 'sync with a field map but the master switch OFF writes nothing');
$lr = $db->query('SELECT * FROM mdm_sync_log ORDER BY id DESC LIMIT 1')->fetch_assoc();
$ok(strpos((string) $lr['errors'], 'asset field writes') === false, 'and says nothing about writes');
// a mapping failure never fails the run
$q('RENAME TABLE mdm_field_write_log TO mdm_field_write_log_hidden');
MdmMappingSettings::save($db, $iid, false, false, true, ['model' => ['target' => 'asset', 'mode' => 'overwrite']], 1);
$run = MdmSyncService::run($db, $db->query('SELECT * FROM manageengine_mdm_integrations')->fetch_assoc(), 1, 60, $opts);
$lr = $db->query('SELECT * FROM mdm_sync_log ORDER BY id DESC LIMIT 1')->fetch_assoc();
$ok($run['ok'] && $lr['status'] === 'success', 'sync: a failure in the mapping step does not fail the run');
$q('RENAME TABLE mdm_field_write_log_hidden TO mdm_field_write_log');
$q('RENAME TABLE mdm_mapping_settings TO mdm_mapping_settings_hidden');
$before = $assetsHash();
$run = MdmSyncService::run($db, $db->query('SELECT * FROM manageengine_mdm_integrations')->fetch_assoc(), 1, 60, $opts);
$ok($run['ok'] && $assetsHash() === $before && MdmMappingSettings::load($db, $iid)['allow_asset_writes'] === false && !MdmMappingSettings::schemaReady($db), 'a missing mapping table (database update pending): the sync still works, every option reads as off');
$q('RENAME TABLE mdm_mapping_settings_hidden TO mdm_mapping_settings');

// =====================================================================================================================================
// bulk device -> asset mapping actions
map_clean();
$iid = mdm_save_row();
$q("INSERT INTO clients SET client_id=1, client_name='Dept A', client_type=''"); $q("INSERT INTO clients SET client_id=2, client_name='Dept B', client_type=''");
$audits = [];
$allow1 = fn (int $c) => $c === 0 || $c === 1;                     // a person who may only touch department 1 (and "no department")
$svc = function (callable $can) use ($db, $iid, &$audits) { return new MdmDeviceMapping($db, $iid, $can, function (string $a, string $t, int $c, int $x) use (&$audits) { $audits[] = [$a, $t, $c, $x]; }); };
$aL1 = $mkAsset('L1 free', 'FREE-0001', 1);
$aL2 = $mkAsset('L2 free', 'FREE-0002', 1);
$aL3 = $mkAsset('L3 free', 'FREE-0003', 1);
$aB1 = $mkAsset('B1 dept b', 'FREE-0004', 2);
$aLinked = $mkAsset('already linked', 'DMPX0000001', 1);
$aArchived = $mkAsset('archived', 'ARCH-0001', 1);
$q("UPDATE assets SET asset_archived_at=NOW() WHERE asset_id=$aArchived");
(new MdmDeviceMapper($db, $iid))->syncDevices([$dev(1), $dev(11), $dev(12), $dev(13), $dev(14)]);   // 1 links by serial; 11-14 have no asset
$u = fn (int $n) => (int) $GLOBALS['one']("SELECT id FROM mdm_unmatched_devices WHERE mdm_device_id='" . (9007199254740000 + $n) . "'");
$lk = fn (int $n) => (int) $GLOBALS['one']("SELECT id FROM asset_mdm_links WHERE mdm_device_id='" . (9007199254740000 + $n) . "'");
$ok($lk(1) > 0 && $u(11) > 0 && $u(12) > 0 && $u(13) > 0 && $u(14) > 0, 'setup: one linked device and four unmatched ones');
$r = $svc($allow1)->run('link', ['u' . $u(11), 'u' . $u(12), 'u' . $u(13)], ['u' . $u(11) => $aL1, 'u' . $u(12) => $aL2, 'u' . $u(13) => $aB1]);
$ok($r['ok'] === 2 && $r['failed'] === 1, 'bulk link: two rows linked, the row whose asset is in a department the person cannot access is refused');
$ok((int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740011'") === $aL1 && $u(13) > 0 && (int) $one("SELECT COUNT(*) FROM asset_mdm_links WHERE asset_id=$aB1") === 0, 'bulk link: the refused row changed nothing');
$ok($r['results'][2]['code'] === 'denied' && $u(11) === 0, 'bulk link: the denial is explained, and linked rows left the review list');
$r = $svc($allow1)->run('link', ['u' . $u(14)], ['u' . $u(14) => $aArchived]);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'no_asset', 'link: an archived asset is refused');
$r = $svc($allow1)->run('link', ['u' . $u(14)], []);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'no_asset', 'link: no asset chosen is refused');
// conflict
$r = $svc($allow1)->run('link', ['u' . $u(14)], ['u' . $u(14) => $aL1]);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'conflict' && strpos($r['results'][0]['message'], 'only one MDM link') !== false && strpos($r['results'][0]['message'], 'Replace') !== false, 'conflict: an asset that already has a device is refused with an explanation and the replace option');
$ok($u(14) > 0 && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740011'") === $aL1, 'conflict: nothing changed');
$r = $svc($allow1)->run('link', ['u' . $u(14)], ['u' . $u(14) => $aL1], true);
$ok($r['ok'] === 1 && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740014'") === $aL1, 'replace: the chosen device takes the asset');
$ok($u(11) > 0 && $one("SELECT reason FROM mdm_unmatched_devices WHERE id=" . $u(11)) === 'unlinked' && $one("SELECT serial_number FROM mdm_unmatched_devices WHERE id=" . $u(11)) === 'DMPX0000011', 'replace: the device that held the asset returns to the review list (reason unlinked, its data kept)');
$ok((int) $one("SELECT COUNT(*) FROM asset_mdm_links WHERE asset_id=$aL1") === 1, 'one asset still has exactly one MDM link');
// two rows in one batch choosing the same asset: the second conflicts
$r = $svc($allow1)->run('link', ['u' . $u(11), 'u' . $u(13)], ['u' . $u(11) => $aL3, 'u' . $u(13) => $aL3]);
$ok($r['ok'] === 1 && $r['failed'] === 1 && $r['results'][1]['code'] === 'conflict', 'bulk link: two devices chosen for the same asset in one batch: the second is a conflict');
// re-link
$tokL2 = 'l' . $lk(12);
$aL4 = $mkAsset('L4 free', 'FREE-0005', 1);
$r = $svc($allow1)->run('link', [$tokL2], [$tokL2 => $aL4]);
$ok($r['ok'] === 1 && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE id=" . $lk(12)) === $aL4 && $one("SELECT match_method FROM asset_mdm_links WHERE id=" . $lk(12)) === 'manual', 're-link: a linked device moves to another asset (match method manual)');
$r = $svc($allow1)->run('link', [$tokL2], [$tokL2 => $aL4]);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'same', 're-link to the same asset: told so, no change');
$r = $svc($allow1)->run('link', [$tokL2], [$tokL2 => $aB1]);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'denied' && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE id=" . $lk(12)) === $aL4, 're-link: the target asset in another department is refused');
// re-link / unlink of a device whose CURRENT asset is in a department the person cannot access
$q("UPDATE assets SET asset_client_id=2 WHERE asset_id=$aL4");
$r = $svc($allow1)->run('link', [$tokL2], [$tokL2 => $aL2]);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'denied' && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE id=" . $lk(12)) === $aL4, 're-link: a device linked to an asset in a forbidden department cannot be moved');
$r = $svc($allow1)->run('unlink', [$tokL2]);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'denied' && $lk(12) > 0, 'unlink: a device linked to an asset in a forbidden department cannot be unlinked');
$q("UPDATE assets SET asset_client_id=1 WHERE asset_id=$aL4");
// unlink
$beforeUnmatched = $cnt('mdm_unmatched_devices');
$r = $svc($allow1)->run('unlink', [$tokL2, 'l' . $lk(1)]);
$ok($r['ok'] === 2 && $lk(12) === 0 && $lk(1) === 0 && $cnt('mdm_unmatched_devices') === $beforeUnmatched + 2, 'bulk unlink: both links removed and both devices kept in the review list');
$ok($one("SELECT reason FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740012'") === 'unlinked' && $one("SELECT user_email FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740012'") === 'user12@example.test', 'unlink: the device data moves to the review row');
$r = $svc($allow1)->run('unlink', ['u' . $u(13)]);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'not_linked', 'unlink: an unmatched device is not linked');
// ignore / restore
$r = $svc($allow1)->run('ignore', ['u' . $u(13), 'u' . $u(12)]);
$ok($r['ok'] === 2 && (int) $one("SELECT dismissed FROM mdm_unmatched_devices WHERE id=" . $u(13)) === 1, 'bulk ignore: both hidden');
$r = $svc($allow1)->run('restore', ['u' . $u(13)]);
$ok($r['ok'] === 1 && (int) $one("SELECT dismissed FROM mdm_unmatched_devices WHERE id=" . $u(13)) === 0, 'restore: back in the review list');
$aL5 = $mkAsset('L5', 'FREE-0006', 1);
(new MdmDeviceMapper($db, $iid))->syncDevices([$dev(1)]);   // re-links device 1 by serial to aLinked
$r = $svc($allow1)->run('ignore', ['l' . $lk(1)]);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'linked', 'ignore: a linked device must be unlinked first');
// hostile tokens and ids
$beforeAll = md5(json_encode([$rows('SELECT * FROM asset_mdm_links'), $rows('SELECT * FROM mdm_unmatched_devices')]));
$r = $svc($allow1)->run('link', ['x1', 'l0', 'u0', "l1; DROP TABLE assets", 'u-5', '', 'l99999999999'], ['x1' => $aL5]);
$ok($r['ok'] === 0 && $r['failed'] === 7, 'hostile or malformed tokens are all refused');
$r = $svc($allow1)->run('drop', ['u1']);
$ok($r['failed'] === 1 && $r['results'][0]['code'] === 'bad_action', 'an unknown action is refused');
$ok($beforeAll === md5(json_encode([$rows('SELECT * FROM asset_mdm_links'), $rows('SELECT * FROM mdm_unmatched_devices')])) && (int) $one('SELECT COUNT(*) FROM assets') > 5, 'hostile input changed nothing');
$many = []; for ($i = 1; $i <= 600; $i++) { $many[] = 'u' . (100000 + $i); }
$r = $svc($allow1)->run('ignore', $many);
$ok(count($r['results']) === MdmDeviceMapping::MAX_ROWS, 'a bulk request is capped at ' . MdmDeviceMapping::MAX_ROWS . ' rows');
$ok($svc($allow1)->run('ignore', ['u' . $u(13)])['ok'] === 1, 'rows are looked up by integration (the same id works for this integration)');
$auditText = json_encode($audits);
$ok(count($audits) >= 8 && strpos($auditText, 'good-secret') === false && strpos($auditText, 'APN-SECRET') === false && strpos($auditText, 'good-refresh') === false, 'audit callbacks fired for the changes and carry no secret');
$denyAll = fn (int $c) => false;
$beforeAll = md5(json_encode([$rows('SELECT * FROM asset_mdm_links'), $rows('SELECT * FROM assets')]));
$tk = 'l' . $lk(1);
$svc($denyAll)->run('link', [$tk], [$tk => $aL5]);
$svc($denyAll)->run('unlink', [$tk]);
$ok($beforeAll === md5(json_encode([$rows('SELECT * FROM asset_mdm_links'), $rows('SELECT * FROM assets')])), 'an access rule that denies everything makes every link/re-link/unlink a no-op');

// =====================================================================================================================================
// the match suggester (pure)
$ok(MdmMatchSuggester::idScore('DMPX-0000042', 'dmpx0000042') === 1.0 && MdmMatchSuggester::idScore('DMPX0000042', 'DMPX0000043') > 0.8 && MdmMatchSuggester::idScore('DMPX0000042', 'QQQQ9999999') === 0.0 && MdmMatchSuggester::idScore('ab', 'ab') === 0.0, 'suggester: serial similarity (punctuation ignored, one-character typo high, unrelated 0, tiny ids never)');
$ok(MdmMatchSuggester::nameScore('Room 4 iPad', 'room4-ipad') >= 0.9 && MdmMatchSuggester::nameScore('iPad 12', 'Library iPad 12') >= 0.7 && MdmMatchSuggester::nameScore('Printer', 'Switch') === 0.0, 'suggester: name similarity');
$assets = [['asset_id' => 1, 'asset_name' => 'Unrelated laptop', 'asset_serial' => 'ZZZ-1234567', 'asset_tag' => ''], ['asset_id' => 2, 'asset_name' => 'Cart 2 iPad 7', 'asset_serial' => 'DMPX0000099', 'asset_tag' => ''], ['asset_id' => 3, 'asset_name' => 'cart2-ipad7', 'asset_serial' => '', 'asset_tag' => '']];
$s = MdmMatchSuggester::suggest(['device_name' => 'Cart 2 iPad 7', 'serial_number' => 'DMPX0000098', 'imei' => '', 'udid' => ''], $assets);
$ok(count($s) === 2 && $s[0]['asset_id'] === 2 && $s[1]['asset_id'] === 3 && $s[0]['score'] >= $s[1]['score'] && !in_array(1, array_column($s, 'asset_id'), true), 'suggester: ranks the loose matches, leaves out the unrelated asset');
$ok(MdmMatchSuggester::suggest(['device_name' => '', 'serial_number' => '', 'imei' => '', 'udid' => ''], $assets) === [], 'suggester: a device with nothing to compare gets no suggestion');
$b = $assetsHash();
MdmMatchSuggester::suggest(['device_name' => 'x', 'serial_number' => 'DMPX0000098'], $assets);
$ok($b === $assetsHash() && count(MdmMatchSuggester::suggest(['device_name' => 'Cart 2 iPad 7', 'serial_number' => ''], $assets, 1)) === 1, 'suggester: writes nothing and honours the limit');

// =====================================================================================================================================
// migration 2.6.169 and db.sql
require_once "$root/includes/database_version.php";
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.169', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.169 (' . LATEST_DATABASE_VERSION . ')');
$sql = file_get_contents("$root/db.sql");
$upd = file_get_contents("$root/admin/database_updates.php");
$gate = strpos($upd, "if (\$rivetit_db_version() == '2.6.168') {");
$block = substr($upd, $gate, (strpos($upd, "if (\$rivetit_db_version() == '2.6.169') {") ?: strlen($upd)) - $gate);
$ok($gate !== false && strpos($block, "'2.6.169'") !== false, 'the migration is gated 2.6.168 -> 2.6.169');
$ok(substr_count($block, 'CREATE TABLE IF NOT EXISTS') === 3 && stripos($block, 'ALTER TABLE') === false && stripos($block, 'DROP ') === false && stripos($block, 'INSERT INTO') === false, 'the migration only adds three tables (IF NOT EXISTS): no ALTER (settings and the MDM link tables untouched), no DROP, no row');
foreach (['mdm_mapping_settings', 'mdm_user_contact_map', 'mdm_field_write_log'] as $t) {
    $coll = (string) $one("SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'");
    $ok($coll === 'utf8mb4_general_ci', "table $t uses utf8mb4_general_ci: $coll");
    $ok(preg_match('/CREATE TABLE IF NOT EXISTS `' . $t . '` \((.*?)\n\) ENGINE/s', $sql, $m) === 1, "db.sql defines $t");
    preg_match_all('/^  `([a-z_0-9]+)` ([^,\n]+)/m', $m[1] ?? '', $c2);
    preg_match('/CREATE TABLE IF NOT EXISTS `' . $t . '` \((.*?)\n\s*\) ENGINE/s', $upd, $mm);
    preg_match_all('/^\s*`([a-z_0-9]+)` ([^,\n]+)/m', $mm[1] ?? '', $c3);
    $ok($c2[1] === $c3[1] && $c2[2] === $c3[2], "db.sql and the migration define identical columns and types for $t");
    $live = array_column($rows("SELECT column_name AS n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' ORDER BY ordinal_position"), 'n');
    $ok($live === $c2[1], "the scratch database (built from db.sql, migrated) has the same columns as db.sql for $t");
}
$ok(strpos($sql, 'uca1400') === false, 'db.sql carries no uca1400 collation');
$ok(stripos($block, 'ADD COLUMN') === false && substr_count($block, 'UPDATE `settings`') === 1, 'no settings column is added');
// replay over live data: the update runner is idempotent and keeps rows
$q('DELETE FROM mdm_user_contact_map'); $q('DELETE FROM mdm_mapping_settings');
$iid = mdm_save_row(['client_id' => '1000.KEEPME']);
MdmMappingSettings::save($db, $iid, true, false, false, ['model' => ['target' => 'asset', 'mode' => 'fill_empty']], 1);
for ($run = 1; $run <= 2; $run++) {
    $q("UPDATE settings SET config_current_database_version='2.6.168' WHERE company_id=1");
    $out = shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "update run $run lands on the latest version");
    $ok(MdmMappingSettings::load($db, $iid)['assign_contact'] === true && (int) $one('SELECT COUNT(*) FROM mdm_mapping_settings') === 1, "run $run keeps the saved mapping options");
}
