<?php
declare(strict_types=1);
/* Still-open modals: ticket_add_watcher + asset_bulk_assign_contact department scope (and the bulk-assign handler). */
require __DIR__ . '/secauthz_bootstrap.php';
eval('namespace ITFlow\Assets; class AssetAssignmentService { public function __construct($d) {} public function recordChangeIfNeeded($a, $c, $u): void {} }');
sec_reset_fixture();
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'A1', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 2, contact_name = 'B1', contact_client_id = 2");
$q("INSERT INTO assets SET asset_id = 1, asset_name = 'A-pc', asset_client_id = 1");
$q("INSERT INTO assets SET asset_id = 2, asset_name = 'B-pc', asset_client_id = 2");
$GLOBALS['T_perm'] = ['module_assets' => 2, 'module_support' => 2];

// Modals are whole pages (they print JSON), so assert the guard is present and precedes the contact query.
foreach ([['agent/modals/ticket/ticket_add_watcher.php', 'enforceClientAccess($client_id)', 'SELECT contact_id, contact_name, contact_email FROM contacts'],
          ['agent/modals/asset/asset_bulk_assign_contact.php', 'enforceClientAccess($client_id)', 'SELECT * FROM contacts']] as [$f, $guard, $query]) {
    $s = (string) file_get_contents(SECAUTHZ_ROOT . "/$f");
    $g = strpos($s, $guard); $qp = strpos($s, $query);
    $ok($g !== false && $qp !== false && $g < $qp, basename($f) . ": enforceClientAccess runs before the department's contacts are listed");
}
// ...and the guard really does stop a restricted agent (same call the modals make)
$denied = false; try { enforceClientAccess(2); } catch (SecRedirect $e) { $denied = true; }
$ok($denied, "enforceClientAccess(<other department>) refuses the restricted agent; own department passes: " . (enforceClientAccess(1) === true ? 'yes' : 'no'));

// bulk assign handler: contact and assets must be in the same department
$F = 'agent/post/asset.php';
$r = sec_run($F, 'bulk_assign_asset_contact', ['bulk_assign_asset_contact' => 1, 'csrf_token' => 't', 'bulk_contact_id' => 1, 'asset_ids' => [1, 2]]);
$ok((int) $one("SELECT asset_contact_id FROM assets WHERE asset_id = 2") === 0, "bulk assign: another department's asset is not assigned the contact ($r)");
$q("UPDATE assets SET asset_contact_id = 0");
$r = sec_run($F, 'bulk_assign_asset_contact', ['bulk_assign_asset_contact' => 1, 'csrf_token' => 't', 'bulk_contact_id' => 1, 'asset_ids' => [1]]);
$ok((int) $one("SELECT asset_contact_id FROM assets WHERE asset_id = 1") === 1, "bulk assign: same-department assignment still works ($r)");
$GLOBALS['session_is_admin'] = true; $q("UPDATE assets SET asset_contact_id = 0");
$r = sec_run($F, 'bulk_assign_asset_contact', ['bulk_assign_asset_contact' => 1, 'csrf_token' => 't', 'bulk_contact_id' => 2, 'asset_ids' => [1]]);
$ok((int) $one("SELECT asset_contact_id FROM assets WHERE asset_id = 1") === 0, "bulk assign: even an administrator cannot cross-assign a contact to another department's asset ($r)");
exit($T_fails ? 1 : 0);
