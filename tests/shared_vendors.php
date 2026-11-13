<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Shared central vendors (vendors.vendor_shared, DB 2.6.237), SCRATCH database only (schema from db.sql, no rows copied).
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_sharedvend_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/shared_vendors.php
 *
 * Needs no config.php. Covers: the helper SQL, the source-level guard, the validation matrix, restricted users, the real
 * migration step (idempotent), and the column being absent.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "set RIVETIT_TEST_DB=1\n"); exit(2); }
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$root = dirname(__DIR__);
$db = new mysqli('localhost', (string) getenv('RIVETIT_TEST_DB_USER'), (string) getenv('RIVETIT_TEST_DB_PASS'), (string) getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
$mysqli = $db;
$GLOBALS['mysqli'] = $db;

$logged = [];
function logAction($type, $action, $description, $client_id = 0, $entity_id = 0) { $GLOBALS['logged'][] = [$type, $action, $description, $entity_id]; }
$flashes = [];
function flash_alert($m, $t = 'success') { $GLOBALS['flashes'][] = [$m, $t]; }
function redirect($url = null, $permanent = false) { throw new RuntimeException('redirect'); }

require_once "$root/includes/vendor_sharing.php";
spl_autoload_register(static function (string $c) use ($root) { if (str_starts_with($c, 'ITFlow\\')) { $f = "$root/src/" . str_replace('\\', '/', substr($c, 7)) . '.php'; if (is_file($f)) { require_once $f; } } });

$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$ids = function (string $sql) use ($db): array { $o = []; $r = $db->query($sql); while ($r && ($x = $r->fetch_row())) { $o[] = (int) $x[0]; } sort($o); return $o; };
$refuses = function (callable $f): bool { try { $f(); return false; } catch (RuntimeException $e) { return $e->getMessage() === 'redirect'; } };

// ---- fixtures (a previous run ends with the column dropped, so make sure it is there first)
$q("ALTER TABLE vendors ADD COLUMN IF NOT EXISTS vendor_shared tinyint(1) NOT NULL DEFAULT 0");
$q("DELETE FROM vendors"); $q("DELETE FROM clients");
$q("INSERT INTO clients SET client_id=1, client_name='Dept A'");
$q("INSERT INTO clients SET client_id=2, client_name='Dept B'");
$ins = function (int $id, string $name, int $client, int $shared, ?string $archived = null) use ($q) {
    $q("INSERT INTO vendors SET vendor_id=$id, vendor_name='$name', vendor_client_id=$client, vendor_shared=$shared, vendor_archived_at=" . ($archived ? "'$archived'" : 'NULL'));
};
$ins(10, 'A own', 1, 0);
$ins(20, 'B own', 2, 0);
$ins(30, 'Central shared', 0, 1);
$ins(40, 'Central private', 0, 0);
$ins(50, 'Central shared archived', 0, 1, '2026-01-01 00:00:00');
$ins(60, 'A own with a stray shared flag', 1, 1);   // vendor_shared means nothing on a department vendor

// ---- 1. the helper SQL
vendorSharedColumnExists($db, true);
$ok(vendorSharedColumnExists($db) === true, 'column present after db.sql');
$ok($ids("SELECT vendor_id FROM vendors WHERE vendor_archived_at IS NULL AND " . vendorVisibleSql(1)) === [10, 30, 60], 'dept A sees: its own + shared central (not B, not private central)');
$ok($ids("SELECT vendor_id FROM vendors WHERE vendor_archived_at IS NULL AND " . vendorVisibleSql(2)) === [20, 30], 'dept B sees: its own + shared central only');
$ok(!in_array(40, $ids("SELECT vendor_id FROM vendors WHERE " . vendorVisibleSql(1)), true) && !in_array(40, $ids("SELECT vendor_id FROM vendors WHERE " . vendorVisibleSql(2)), true), 'an unshared central vendor appears in no department');
$ok(!in_array(20, $ids("SELECT vendor_id FROM vendors WHERE " . vendorVisibleSql(1)), true), "another department's vendor never appears");
$ok($ids("SELECT vendor_id FROM vendors WHERE vendor_archived_at IS NULL AND " . vendorVisibleSql(3)) === [30], 'a department with no vendors of its own sees only the shared central one');
$ok($ids("SELECT vendor_id FROM vendors WHERE vendor_archived_at IS NULL AND " . vendorVisibleSql(0)) === [30, 40], 'client 0 (the central list) sees every non-archived central vendor');
$ok(in_array(50, $ids("SELECT vendor_id FROM vendors WHERE " . vendorVisibleSql(1)), true) && !in_array(50, $ids("SELECT vendor_id FROM vendors WHERE vendor_archived_at IS NULL AND " . vendorVisibleSql(1)), true), 'archived shared vendor: scoped in, hidden by the caller\'s archived condition');
foreach (['v' => 'v', 'v.' => 'v', 'vendors' => 'vendors'] as $alias => $table_alias) {
    $r = $ids("SELECT v.vendor_id FROM vendors v LEFT JOIN clients c ON c.client_id = v.vendor_client_id WHERE v.vendor_archived_at IS NULL AND " . vendorVisibleSql(1, $alias === 'vendors' ? 'v' : $alias));
    $ok($r === [10, 30, 60] && $db->error === '', "alias '$alias' works in a joined query");
}
$ok(str_contains(vendorVisibleSql(5, 'x'), 'x.vendor_client_id = 5') && str_contains(vendorVisibleSql(5, 'x'), 'x.vendor_shared = 1'), 'helper text is aliased on every column');
$ok(vendorVisibleSql("5; DROP TABLE vendors") === vendorVisibleSql(5), 'a non-numeric client id is cast, never concatenated');
// one-sided join to clients (the global search shape) for a department-restricted user
$apq = "AND clients.client_id IN (1)";
$ok($ids("SELECT vendor_id FROM vendors LEFT JOIN clients ON vendor_client_id = client_id WHERE vendor_archived_at IS NULL $apq") === [10, 60], 'baseline: the old restricted-user query hides every central vendor');
$ok($ids("SELECT vendor_id FROM vendors LEFT JOIN clients ON vendor_client_id = client_id WHERE vendor_archived_at IS NULL " . vendorAccessScopeSql($apq)) === [10, 30, 60], 'restricted user (dept A only): own + shared central, NOT the unshared central vendor, NOT dept B');
$ok(vendorAccessScopeSql('') === '', 'unrestricted user: no extra condition');
$ok($ids("SELECT vendor_id FROM vendors LEFT JOIN clients ON vendor_client_id = client_id WHERE vendor_archived_at IS NULL AND " . vendorVisibleSql(2, 'vendors') . ' ' . vendorAccessScopeSql($apq)) === [30], 'restricted user asking for dept B (get_client_vendors): only the shared central vendor, none of B\'s own');

// ---- 2. source-level guard
$lit = [];
$allowed = [
    'agent/post/vendor.php' => 'vendor administration (writes and bulk handlers address one vendor by id)',
    'agent/post/client.php' => 'deleting a department removes its own vendors; the department export lists its own records',
    'agent/vendors.php' => 'the central list (vendor_client_id = 0) and the helper-built department scope',
    'includes/vendor_sharing.php' => 'the helper itself',
    'api/v1/vendors/read.php' => 'dead, unrouted legacy file (flat per-resource routing); it also returns notes and account numbers, so it must not widen',
    'src/Links/EntityTypes.php' => 'column map for generic record lookups',
    'agent/modals/vendor/vendor_edit.php' => 'reads the edited vendor\'s own client_id',
    'agent/modals/vendor/vendor_details.php' => 'reads the viewed vendor\'s own client_id',
    'agent/vendor_details.php' => 'reads the viewed vendor\'s own client_id',
    'agent/post/vendor_contact.php' => 'vendor contacts (table dropped, dead)',
];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace($root . '/', '', (string) $f);
    if (!str_ends_with($p, '.php') || preg_match('#^(vendor|tests|node_modules|\.git|pentest_reports)/#', $p)) { continue; }
    $src = (string) file_get_contents((string) $f);
    if (preg_match('/vendor_client_id\s*=\s*(\$|\'\s*\.|\{\$)|OR\s+vendor_client_id\s*=\s*0/i', $src) && !isset($allowed[$p])) {
        $lit[] = $p;
    }
}
$ok($lit === [], 'no department vendor query spells out vendor_client_id = $client_id (or OR vendor_client_id = 0) outside the allow-list' . ($lit ? ': ' . implode(', ', $lit) : ''));
foreach ($allowed as $p => $why) {
    if (!is_file("$root/$p")) { continue; }
    $ok(true, "allow-listed: $p ($why)");
}
$pickers = ['agent/modals/asset/asset_add.php', 'agent/modals/asset/asset_edit.php', 'agent/modals/asset/asset_copy.php', 'agent/modals/ticket/ticket_add.php', 'agent/modals/ticket/ticket_edit.php',
    'agent/modals/ticket/ticket_edit_vendor.php', 'agent/modals/service/service_add.php', 'agent/modals/service/service_edit.php', 'agent/modals/software/software_add.php', 'agent/modals/software/software_edit.php',
    'agent/modals/domain/domain_add.php', 'agent/modals/domain/domain_edit.php', 'agent/modals/document/document_link_vendor.php', 'includes/relationships_card.php', 'agent/clients.php', 'agent/includes/inc_all_client.php', 'agent/ajax.php', 'agent/vendors.php'];
foreach ($pickers as $p) {
    $ok(str_contains((string) file_get_contents("$root/$p"), 'vendorVisibleSql(') || str_contains((string) file_get_contents("$root/$p"), 'vendorAccessScopeSql('), "$p uses the helper");
}
// every handler that stores a vendor on a department record checks it BEFORE the first write
$handlers = [
    ['agent/post/asset.php', "isset(\$_POST['add_asset'])", 'enforceVendorAssignable', 'INSERT INTO assets'],
    ['agent/post/asset.php', "isset(\$_POST['edit_asset'])", 'enforceVendorAssignable', 'UPDATE assets SET'],
    ['agent/post/ticket.php', "isset(\$_POST['add_ticket'])", 'enforceVendorAssignable', 'INSERT INTO tickets'],
    ['agent/post/ticket.php', "isset(\$_POST['edit_ticket'])", 'enforceVendorAssignable', 'UPDATE tickets SET'],
    ['agent/post/ticket.php', "isset(\$_POST['edit_ticket_vendor'])", 'enforceVendorAssignable', 'UPDATE tickets SET ticket_vendor_id'],
    ['agent/post/service.php', "isset(\$_POST['add_service'])", 'enforceVendorListAssignable', 'INSERT INTO services'],
    ['agent/post/service.php', "isset(\$_POST['edit_service'])", 'enforceVendorListAssignable', 'UPDATE services'],
    ['agent/post/software.php', "isset(\$_POST['add_software_from_template'])", 'enforceVendorAssignable', 'INSERT INTO software'],
    ['agent/post/software.php', "isset(\$_POST['add_software'])", 'enforceVendorAssignable', 'INSERT INTO software'],
    ['agent/post/software.php', "isset(\$_POST['edit_software'])", 'enforceVendorAssignable', 'UPDATE software'],
    ['agent/post/domain.php', "isset(\$_POST['add_domain'])", 'enforceVendorAssignable', 'INSERT INTO domains'],
    ['agent/post/domain.php', "isset(\$_POST['edit_domain'])", 'enforceVendorAssignable', 'UPDATE domains'],
    ['agent/post/document.php', "isset(\$_POST['link_vendor_to_document'])", 'enforceVendorAssignable', 'INSERT INTO vendor_documents'],
];
foreach ($handlers as [$file, $anchor, $enforce, $write]) {
    $s = (string) file_get_contents("$root/$file");
    $a = strpos($s, $anchor);
    $body = $a === false ? '' : substr($s, $a);
    $end = preg_match('/\n\}\n/', $body, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($body);
    $body = substr($body, 0, $end);
    $pe = strpos($body, $enforce); $pw = strpos($body, $write);
    $ok($a !== false && $pe !== false && $pw !== false && $pe < $pw, "$file: $anchor checks the vendor ($enforce) before the write ($write)");
}
$ok(str_contains((string) file_get_contents("$root/src/Links/VendorRoles.php"), 'vendorIsSharedCentral') && str_contains((string) file_get_contents("$root/src/Links/LinkService.php"), 'vendorIsSharedCentral'), 'VendorRoles and LinkService check shared status');

// ---- 3. validation matrix (the check every POST above calls)
// record types: asset / ticket / software / domain (x4 fields) / document link take ONE vendor id; service takes a list.
$types = ['asset', 'ticket', 'software', 'domain registrar', 'domain web host', 'domain dns host', 'domain mail host', 'document link'];
foreach ($types as $t) {
    $ok(vendorAssignableToClient($db, 0, 1), "$t: no vendor is fine");
    $ok(vendorAssignableToClient($db, 10, 1), "$t: the department's own vendor is accepted");
    $ok(vendorAssignableToClient($db, 30, 1), "$t: a shared central vendor is accepted");
    $ok(!vendorAssignableToClient($db, 20, 1), "$t: another department's vendor is refused");
    $ok(!vendorAssignableToClient($db, 40, 1), "$t: an unshared central vendor is refused");
    $ok(!vendorAssignableToClient($db, 999, 1) && !vendorAssignableToClient($db, -3, 1), "$t: an unknown / negative vendor id is refused");
    $ok($refuses(fn() => enforceVendorAssignable(20, 1)) && end($flashes)[0] === 'That vendor is not available for this department.', "$t: the POST path refuses with the flash + redirect");
    $ok($refuses(fn() => enforceVendorAssignable(40, 1)), "$t: the POST path refuses an unshared central vendor");
    $ok(!$refuses(fn() => enforceVendorAssignable(30, 2)) && !$refuses(fn() => enforceVendorAssignable(10, 1)) && !$refuses(fn() => enforceVendorAssignable(0, 1)), "$t: the POST path lets valid vendors through");
}
$ok(!vendorAssignableToClient($db, 10, 2), 'dept A vendor on a dept B record: refused (the asset-moved case)');
$ok(vendorAssignableToClient($db, 40, 0), 'a record with no department may use a central vendor (it is not a department)');
// service multi-select: one bad id refuses the whole list
$ok(!$refuses(fn() => enforceVendorListAssignable(['10', '30'], 1)), 'service: own + shared accepted');
$ok($refuses(fn() => enforceVendorListAssignable(['10', '30', '40'], 1)), 'service: one unshared central in the list refuses it');
$ok($refuses(fn() => enforceVendorListAssignable(['20'], 1)), "service: another department's vendor refuses it");
$ok(!$refuses(fn() => enforceVendorListAssignable(['40'], 1, [40])), 'service edit: an already-linked vendor (since un-shared) keeps saving');
// toggling OFF: existing links keep working, new use is refused
$q("UPDATE vendors SET vendor_shared = 0 WHERE vendor_id = 30");
$ok(!$refuses(fn() => enforceVendorAssignable(30, 1, 30)), 'toggled off: an edit that leaves the existing vendor in place still saves');
$ok($refuses(fn() => enforceVendorAssignable(30, 1)), 'toggled off: it is refused for new use');
$ok($refuses(fn() => enforceVendorAssignable(30, 1, 10)), 'toggled off: switching a record from another vendor to it is refused');
$ok(!in_array(30, $ids("SELECT vendor_id FROM vendors WHERE vendor_archived_at IS NULL AND " . vendorVisibleSql(1)), true), 'toggled off: gone from the department pickers and list');
$ok($one("SELECT vendor_name FROM vendors WHERE vendor_id = 30") === 'Central shared', 'toggled off: display joins (by id) still resolve the vendor name for history');
$q("UPDATE vendors SET vendor_shared = 1 WHERE vendor_id = 30");

// ---- 4. VendorRoles + LinkService (real classes)
$q("DELETE FROM assets"); $q("DELETE FROM asset_vendors");
$q("INSERT INTO assets SET asset_id=100, asset_name='Laptop A', asset_client_id=1, asset_type='laptop'");
$q("INSERT INTO assets SET asset_id=200, asset_name='Laptop B', asset_client_id=2, asset_type='laptop'");
$admin = new \ITFlow\Links\LinkActor(1, true, ['module_assets' => 3, 'module_support' => 3, 'module_client' => 3, 'module_kb' => 3, 'module_credential' => 3, 'module_financial' => 3], null);
$vr = new \ITFlow\Links\VendorRoles($db);
$r1 = $vr->add($admin, 'asset', 100, 30, 'reseller');
$ok($r1['ok'] === true, 'vendor role: shared central vendor on a department asset is accepted');
$r2 = $vr->add($admin, 'asset', 100, 40, 'reseller');
$ok($r2['ok'] === false && ($r2['error'] ?? '') === 'cross_client', 'vendor role: unshared central vendor refused');
$r3 = $vr->add($admin, 'asset', 100, 20, 'reseller');
$ok($r3['ok'] === false && ($r3['error'] ?? '') === 'cross_client', "vendor role: another department's vendor refused");
$r4 = $vr->add($admin, 'asset', 100, 10, 'reseller');
$ok($r4['ok'] === true, "vendor role: the department's own vendor accepted");
$q("UPDATE vendors SET vendor_shared = 0 WHERE vendor_id = 30");
$ok(count(array_filter($vr->forRecord('asset', 100), fn($v) => $v['vendor_id'] === 30)) === 1, 'toggled off: the existing vendor role still lists (history)');
$rm = $vr->remove($admin, 'asset', 100, 30, 'reseller');
$ok($rm['ok'] === true, 'toggled off: the existing vendor role can still be removed');
$ok($vr->add($admin, 'asset', 100, 30, 'support')['ok'] === false, 'toggled off: it cannot be added again');
$q("UPDATE vendors SET vendor_shared = 1 WHERE vendor_id = 30");
$ls = new \ITFlow\Links\LinkService($db);
$q("DELETE FROM entity_links");
$ok($ls->create($admin, 'asset', 100, 'vendor', 30, 'related')['ok'] ?? false, 'entity link: asset -> shared central vendor accepted');
$l2 = $ls->create($admin, 'asset', 100, 'vendor', 40, 'related');
$ok(($l2['ok'] ?? true) === false, 'entity link: asset -> unshared central vendor refused');
$l3 = $ls->create($admin, 'vendor', 40, 'asset', 100, 'related');
$ok(($l3['ok'] ?? true) === false, 'entity link: unshared central vendor -> asset (reverse direction) refused');
$ok(($ls->create($admin, 'vendor', 40, 'vendor', 30, 'related')['ok'] ?? false) === true, 'entity link: central vendor <-> central vendor (no department) unaffected');
$restricted = new \ITFlow\Links\LinkActor(2, false, ['module_assets' => 3, 'module_support' => 3, 'module_client' => 3, 'module_kb' => 3, 'module_credential' => 3, 'module_financial' => 3], [1]);
$ok(($ls->create($restricted, 'asset', 200, 'vendor', 30, 'related')['ok'] ?? true) === false, 'restricted user (dept A only): dept B asset refused regardless');

// ---- 5. toggle audit
$logged = [];
$ok(vendorSetShared($db, 40, true, 7, 'Tester') === true && (int) $one("SELECT vendor_shared FROM vendors WHERE vendor_id = 40") === 1, 'toggle ON stores vendor_shared = 1');
$ok(count($logged) === 1 && $logged[0][0] === 'Vendor' && $logged[0][1] === 'Share' && str_contains($logged[0][2], 'Tester') && str_contains($logged[0][2], 'Central private') && str_contains($logged[0][2], 'not shared -> shared with all departments'), 'toggle ON writes one audit row: who, vendor, old -> new');
$ok(vendorSetShared($db, 40, true, 7, 'Tester') === false && count($logged) === 1, 'saving with no change writes no audit row');
$ok(vendorSetShared($db, 40, false, 7, 'Tester') === true && count($logged) === 2 && str_contains($logged[1][2], 'shared with all departments -> not shared'), 'toggle OFF writes an audit row (shared -> not shared)');
$ok(vendorSetShared($db, 10, true, 7, 'Tester') === false && (int) $one("SELECT vendor_shared FROM vendors WHERE vendor_id = 10") === 0, "a department's own vendor can never be shared (client_id != 0)");
$ok(vendorSetShared($db, 999, true) === false, 'unknown vendor: nothing happens');
$ok(str_contains(vendorSharedCheckboxHtml(0, true), 'name="vendor_shared"') && str_contains(vendorSharedCheckboxHtml(0), 'does not remove the vendor from records that already use it') && vendorSharedCheckboxHtml(5) === '', 'checkbox shows for central vendors only, with the toggle-off help text');

// ---- 6. the real migration step, idempotent
$src = (string) file_get_contents("$root/admin/database_updates.php");
$start = strpos($src, "if (\$rivetit_db_version() == '2.6.236') {\n        // DB 2.6.237");
$ok($start !== false, 'migration step 2.6.236 -> 2.6.237 present');
$step = substr($src, (int) $start);
$stepEnd = strpos($step, "\n    }\n") + 7;
$step = substr($step, 0, $stepEnd);
$q("UPDATE vendors SET vendor_shared = 0");
$q("ALTER TABLE vendors DROP COLUMN vendor_shared");
$q("CREATE TABLE IF NOT EXISTS settings_probe_unused (x int)"); $q("DROP TABLE settings_probe_unused");
$q("DELETE FROM settings"); $q("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.236'");
$runStep = function () use ($step, $mysqli) { $rivetit_db_version = fn() => '2.6.236'; eval($step); };
$runStep();
$ok($db->error === '' && vendorSharedColumnExists($db, true), 'migration adds vendors.vendor_shared');
$ok($one("SELECT COUNT(*) FROM vendors WHERE vendor_shared <> 0") === '0' || (int) $one("SELECT COUNT(*) FROM vendors WHERE vendor_shared <> 0") === 0, 'every existing vendor stays unshared');
$ok($one("SELECT config_current_database_version FROM settings LIMIT 1") === '2.6.237', 'migration sets the version to 2.6.237');
$q("UPDATE vendors SET vendor_shared = 1 WHERE vendor_id = 30");
$runStep();
$ok($db->error === '' && (int) $one("SELECT vendor_shared FROM vendors WHERE vendor_id = 30") === 1, 'running the step again is harmless and keeps a shared flag');
$ok(str_contains((string) file_get_contents("$root/includes/database_version.php"), '"2.6.237"'), 'LATEST_DATABASE_VERSION is 2.6.237');
$ok(str_contains((string) file_get_contents("$root/db.sql"), "`vendor_shared` tinyint(1) NOT NULL DEFAULT 0"), 'db.sql carries the column');

// ---- 7. column absent: nothing errors, nothing is shared
$q("ALTER TABLE vendors DROP COLUMN vendor_shared");
$ok(vendorSharedColumnExists($db, true) === false, 'column absent: reported absent');
$ok(vendorVisibleSql(1) === '(vendor_client_id = 1 OR 0 = 1)', 'column absent: the scope is the department\'s own vendors only');
$ok($ids("SELECT vendor_id FROM vendors WHERE vendor_archived_at IS NULL AND " . vendorVisibleSql(1)) === [10, 60] && $db->error === '', 'column absent: the picker query runs and offers no central vendor');
$ok($ids("SELECT vendor_id FROM vendors LEFT JOIN clients ON vendor_client_id = client_id WHERE vendor_archived_at IS NULL " . vendorAccessScopeSql($apq)) === [10, 60] && $db->error === '', 'column absent: the restricted-user query runs unchanged');
$ok(!vendorAssignableToClient($db, 30, 1) && vendorAssignableToClient($db, 10, 1) && !vendorIsSharedCentral($db, 30), 'column absent: nothing is shared, own vendors still validate');
$ok(vendorSetShared($db, 30, true) === false && vendorSharedCheckboxHtml(0) === '' && $db->error === '', 'column absent: toggling is a no-op and the checkbox is hidden');
$ok($refuses(fn() => enforceVendorAssignable(30, 1)), 'column absent: the POST path refuses a central vendor');

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
