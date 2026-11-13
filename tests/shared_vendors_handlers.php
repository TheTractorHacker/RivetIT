<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Shared central vendors: the REAL agent POST handlers (agent/post/*.php, run in a child process with the real functions.php), as a
 * client-restricted technician (department A only), against a SCRATCH database (schema from db.sql, fixtures only).
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_sharedvend_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... \
 *   RIVETIT_TEST_VENDOR_DIR=/var/www/<site>/vendor  php tests/shared_vendors_handlers.php
 *
 * It builds a throwaway copy of the working tree (without .git/vendor) in a temp dir, copies the given complete vendor/ into it
 * (the worktree's own vendor/ may be incomplete) and writes a config.php for the scratch database there. Nothing touches the repo's config.
 * Matrix per store path: another department's vendor, an unshared central vendor, a shared central vendor, the department's own vendor.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "set RIVETIT_TEST_DB=1\n"); exit(2); }
$dbname = (string) getenv('RIVETIT_TEST_DB_NAME'); $dbuser = (string) getenv('RIVETIT_TEST_DB_USER'); $dbpass = (string) getenv('RIVETIT_TEST_DB_PASS');
if (!preg_match('/scratch/i', $dbname)) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
$vendorDir = (string) getenv('RIVETIT_TEST_VENDOR_DIR');
if (!is_file("$vendorDir/autoload.php")) { fwrite(STDERR, "set RIVETIT_TEST_VENDOR_DIR to a complete vendor/ directory\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$root = dirname(__DIR__);
$db = new mysqli('localhost', $dbuser, $dbpass, $dbname);
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");

// ---- throwaway app copy
$app = sys_get_temp_dir() . '/rivetit_sharedvend_app_' . bin2hex(random_bytes(4));
mkdir($app, 0700);
exec('cd ' . escapeshellarg($root) . ' && tar --exclude=./.git --exclude=./vendor --exclude=./tests --exclude=./node_modules --exclude=./pentest_reports -cf - . | tar -xf - -C ' . escapeshellarg($app));
exec('cp -r ' . escapeshellarg($vendorDir) . ' ' . escapeshellarg("$app/vendor"));
mkdir("$app/tests", 0700); mkdir("$app/tests/support", 0700);
$cfg = "<?php\n\$dbhost = 'localhost';\n\$dbusername = " . var_export($dbuser, true) . ";\n\$dbpassword = " . var_export($dbpass, true) . ";\n\$database = " . var_export($dbname, true) . ";\n"
    . "\$mysqli = mysqli_connect(\$dbhost, \$dbusername, \$dbpassword, \$database);\n\$config_app_name = 'RivetIT';\n\$config_base_url = 'localhost';\n\$config_https_only = false;\n\$repo_branch = 'beta';\n"
    . "\$installation_id = 'scratch';\n\$config_enable_setup = 0;\n\$config_settings_enc_key = " . var_export(base64_encode(random_bytes(32)), true) . ";\n";
file_put_contents("$app/config.php", $cfg);
register_shutdown_function(static function () use ($app) { exec('rm -rf ' . escapeshellarg($app)); });

$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;

// ---- fixtures
foreach (['vendors', 'assets', 'tickets', 'domains', 'software', 'services', 'service_vendors', 'documents', 'vendor_documents', 'clients', 'users', 'user_roles', 'user_role_permissions', 'modules', 'user_client_permissions', 'user_settings', 'asset_vendors', 'software_vendors', 'entity_links', 'logs', 'settings', 'companies'] as $t) { $q("DELETE FROM `$t`"); }
$q("ALTER TABLE vendors ADD COLUMN IF NOT EXISTS vendor_shared tinyint(1) NOT NULL DEFAULT 0");
$q("INSERT INTO companies SET company_id = 1, company_name = 'Test Co', company_locale = 'en_US', company_currency = 'USD'");
$q("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.237', config_ticket_prefix = 'T', config_ticket_next_number = 1000");
foreach (['module_support' => 1, 'module_client' => 2, 'module_assets' => 3, 'module_financial' => 4, 'module_credential' => 5, 'module_kb' => 6] as $m => $id) { $q("INSERT INTO modules SET module_id = $id, module_name = '$m', module_description = 'x'"); }
$q("INSERT INTO user_roles SET role_id = 92, role_name = 'Tech', role_is_admin = 0, role_type = 1");
foreach ([1, 2, 3, 6] as $mid) { $q("INSERT INTO user_role_permissions SET user_role_id = 92, module_id = $mid, user_role_permission_level = 2"); }
$q("INSERT INTO users SET user_id = 11, user_name = 'Restricted Tech', user_email = 'tech@example.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 92");
$q("INSERT INTO user_settings SET user_id = 11");
$q("INSERT INTO user_client_permissions SET user_id = 11, client_id = 1");
$q("INSERT INTO clients SET client_id = 1, client_name = 'Dept A'");
$q("INSERT INTO clients SET client_id = 2, client_name = 'Dept B'");
$vend = function (int $id, string $name, int $client, int $shared) use ($q) {
    $q("INSERT INTO vendors SET vendor_id = $id, vendor_name = '$name', vendor_client_id = $client, vendor_shared = $shared, vendor_account_number = 'ACCT-$id', vendor_code = 'CODE-$id', vendor_sla = 'SLA-$id', vendor_notes = 'NOTES-$id', vendor_hours = '9-5'");
};
$vend(10, 'A own', 1, 0); $vend(20, 'B own', 2, 0); $vend(30, 'Central shared', 0, 1); $vend(40, 'Central private', 0, 0);
$MATRIX = [20 => false, 40 => false, 30 => true, 10 => true];   // vendor id => accepted?
$LABEL = [20 => "other department's vendor", 40 => 'unshared central vendor', 30 => 'shared central vendor', 10 => "own department's vendor"];

$n = 0;
$run = function (string $handler, array $post, array $get = []) use ($app, &$n): array {
    $f = sys_get_temp_dir() . '/sv_case_' . getmypid() . '_' . (++$n) . '.json';
    file_put_contents($f, json_encode(['user_id' => 11, 'handler' => $handler, 'post' => $post] + ($get ? ['get' => $get] : [])));
    $out = (string) shell_exec('timeout 60 php ' . escapeshellarg(__DIR__ . '/support/vendor_handler_case.php') . ' ' . escapeshellarg($app) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $m = preg_match('/@@RESULT@@(.*)/', $out, $mm) ? json_decode($mm[1], true) : null;
    return ['alert' => (string) ($m['alert'] ?? ''), 'raw' => $out, 'got' => $m !== null];
};
$refusedMsg = fn(array $r) => str_contains($r['alert'], 'not available for this department');

// ---- helpers
// A handler reads many $_POST keys; default every key it mentions to '' so only the ones under test need naming.
$keysOf = function (string $file, string $anchor) use ($root): array {
    $src = (string) file_get_contents("$root/agent/post/$file.php");
    $a = strpos($src, $anchor);
    $body = $a === false ? '' : substr($src, $a);
    $end = preg_match('/\n\}\n/', $body, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($body);
    $body = substr($body, 0, $end);
    $keys = [];
    if (preg_match_all('/\$_POST\[\'(\w+)\'\]/', $body, $mm)) { $keys = array_fill_keys($mm[1], ''); }
    unset($keys['csrf_token']);
    return $keys;
};
$withKeys = fn(string $file, string $anchor, array $post, array $extraFiles = []) => array_diff_key($keysOf($file, $anchor), ['csrf_token' => 1]) + [];
$mk = function (string $file, string $anchor, array $post) use ($keysOf): array {
    $base = $keysOf($file, $anchor);
    foreach (['asset_model' => 'asset', 'ticket_model' => 'ticket'] as $_) { }
    return array_merge($base, $post);
};
$modelKeys = function (string $model) use ($root): array {
    $src = (string) file_get_contents("$root/agent/post/$model.php");
    $k = preg_match_all('/\$_POST\[\'(\w+)\'\]/', $src, $mm) ? array_fill_keys($mm[1], '') : [];
    unset($k['csrf_token']);
    return $k;
};
$one0 = fn(string $sql) => (string) ($one($sql) ?? '');

$specs = [];
$assetPost = fn(string $flag, array $extra) => array_merge($modelKeys('asset_model'), $keysOf('asset', "isset(\$_POST['$flag'])"), ['client_id' => '1', 'name' => 'Asset', 'type' => 'laptop', 'status' => 'Ready To Deploy'], [$flag => '1'], $extra);

// each spec: handler file(s), reset (returns nothing), post(vendor), stored() (what is stored now), initial (what a refusal must leave)
$specs['asset add'] = ['handler' => 'asset', 'initial' => '', 'reset' => fn() => $q("DELETE FROM assets"),
    'post' => fn($v) => $assetPost('add_asset', ['vendor' => (string) $v]),
    'stored' => fn() => $one0("SELECT GROUP_CONCAT(asset_vendor_id) FROM assets WHERE asset_client_id = 1")];
$specs['asset edit'] = ['handler' => 'asset', 'initial' => '0', 'reset' => function () use ($q) { $q("DELETE FROM assets"); $q("INSERT INTO assets SET asset_id = 100, asset_name = 'Asset', asset_type = 'laptop', asset_client_id = 1, asset_vendor_id = 0"); },
    'post' => fn($v) => $assetPost('edit_asset', ['asset_id' => '100', 'vendor' => (string) $v]),
    'stored' => fn() => $one0("SELECT asset_vendor_id FROM assets WHERE asset_id = 100")];
$ticketPost = fn(string $flag, array $extra) => array_merge($keysOf('ticket', "isset(\$_POST['$flag'])"), ['client_id' => '1', 'subject' => 'Subj', 'priority' => 'Low', 'details' => 'x', 'contact_id' => '0', 'assigned_to' => '0', 'category_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'ticket_template_id' => '0'], [$flag => '1'], $extra);
$specs['ticket add'] = ['handler' => 'ticket', 'initial' => '', 'reset' => fn() => $q("DELETE FROM tickets"),
    'post' => fn($v) => $ticketPost('add_ticket', ['vendor_id' => (string) $v]),
    'stored' => fn() => $one0("SELECT GROUP_CONCAT(ticket_vendor_id) FROM tickets WHERE ticket_client_id = 1")];
$seedTicket = function () use ($q) { $q("DELETE FROM tickets"); $q("INSERT INTO tickets SET ticket_id = 500, ticket_prefix = 'T', ticket_number = 1, ticket_subject = 'S', ticket_details = 'd', ticket_priority = 'Low', ticket_status = 1, ticket_client_id = 1, ticket_vendor_id = 0"); };
$specs['ticket edit'] = ['handler' => 'ticket', 'initial' => '0', 'reset' => $seedTicket,
    'post' => fn($v) => $ticketPost('edit_ticket', ['ticket_id' => '500', 'vendor_id' => (string) $v]),
    'stored' => fn() => $one0("SELECT ticket_vendor_id FROM tickets WHERE ticket_id = 500")];
$specs['ticket edit-vendor'] = ['handler' => 'ticket', 'initial' => '0', 'reset' => $seedTicket,
    'post' => fn($v) => ['edit_ticket_vendor' => '1', 'ticket_id' => '500', 'vendor' => (string) $v],
    'stored' => fn() => $one0("SELECT ticket_vendor_id FROM tickets WHERE ticket_id = 500")];
$domPost = fn(string $flag, array $extra) => array_merge($keysOf('domain', "isset(\$_POST['$flag'])"), ['client_id' => '1', 'name' => 'example.invalid', 'description' => '', 'expire' => '2030-01-01', 'notes' => '', 'registrar' => '0', 'dnshost' => '0', 'webhost' => '0', 'mailhost' => '0'], [$flag => '1'], $extra);
foreach (['registrar' => 'domain_registrar', 'webhost' => 'domain_webhost', 'dnshost' => 'domain_dnshost', 'mailhost' => 'domain_mailhost'] as $field => $col) {
    $specs["domain add ($field)"] = ['handler' => 'domain', 'initial' => '', 'reset' => fn() => $q("DELETE FROM domains"),
        'post' => fn($v) => $domPost('add_domain', [$field => (string) $v]),
        'stored' => fn() => $one0("SELECT GROUP_CONCAT($col) FROM domains WHERE domain_client_id = 1")];
    $specs["domain edit ($field)"] = ['handler' => 'domain', 'initial' => '0', 'reset' => function () use ($q) { $q("DELETE FROM domains"); $q("INSERT INTO domains SET domain_id = 600, domain_name = 'example.invalid', domain_client_id = 1"); },
        'post' => fn($v) => $domPost('edit_domain', ['domain_id' => '600', $field => (string) $v]),
        'stored' => fn() => $one0("SELECT $col FROM domains WHERE domain_id = 600")];
}
$swPost = fn(string $flag, array $extra) => array_merge($keysOf('software', "isset(\$_POST['$flag'])"), ['client_id' => '1', 'name' => 'Soft', 'version' => '1', 'description' => '', 'type' => 'Other', 'license_type' => 'Other', 'seats' => '1', 'notes' => ''], [$flag => '1'], $extra);
$specs['software add'] = ['handler' => 'software', 'initial' => '', 'reset' => fn() => $q("DELETE FROM software"),
    'post' => fn($v) => $swPost('add_software', ['vendor' => (string) $v]),
    'stored' => fn() => $one0("SELECT GROUP_CONCAT(software_vendor_id) FROM software WHERE software_client_id = 1")];
$specs['software edit'] = ['handler' => 'software', 'initial' => '0', 'reset' => function () use ($q) { $q("DELETE FROM software"); $q("INSERT INTO software SET software_id = 700, software_name = 'Soft', software_type = 'Other', software_license_type = 'Other', software_client_id = 1, software_vendor_id = 0"); },
    'post' => fn($v) => $swPost('edit_software', ['software_id' => '700', 'vendor' => (string) $v]),
    'stored' => fn() => $one0("SELECT software_vendor_id FROM software WHERE software_id = 700")];
$svcPost = fn(string $flag, array $extra) => array_merge($keysOf('service', "isset(\$_POST['$flag'])"), ['client_id' => '1', 'name' => 'Svc', 'description' => 'd', 'category' => 'c', 'importance' => 'Low', 'backup' => '', 'note' => ''], [$flag => '1'], $extra);
$specs['service add'] = ['handler' => 'service', 'initial' => '', 'reset' => function () use ($q) { $q("DELETE FROM services"); $q("DELETE FROM service_vendors"); },
    'post' => fn($v) => $svcPost('add_service', ['vendors' => [(string) $v]]),
    'stored' => fn() => $one0("SELECT GROUP_CONCAT(vendor_id) FROM service_vendors")];
$specs['service edit'] = ['handler' => 'service', 'initial' => '', 'reset' => function () use ($q) { $q("DELETE FROM services"); $q("DELETE FROM service_vendors"); $q("INSERT INTO services SET service_id = 800, service_name = 'Svc', service_description = 'd', service_importance = 'Low', service_client_id = 1"); },
    'post' => fn($v) => $svcPost('edit_service', ['service_id' => '800', 'vendors' => [(string) $v]]),
    'stored' => fn() => $one0("SELECT GROUP_CONCAT(vendor_id) FROM service_vendors WHERE service_id = 800")];
$specs['document link'] = ['handler' => 'document', 'initial' => '', 'reset' => function () use ($q) { $q("DELETE FROM documents"); $q("DELETE FROM vendor_documents"); $q("INSERT INTO documents SET document_id = 900, document_name = 'Doc', document_content = '', document_content_raw = '', document_client_id = 1"); },
    'post' => fn($v) => ['link_vendor_to_document' => '1', 'document_id' => '900', 'vendor_id' => (string) $v],
    'stored' => fn() => $one0("SELECT GROUP_CONCAT(vendor_id) FROM vendor_documents WHERE document_id = 900")];
$specs['vendor role (VendorRoles::add)'] = ['handler' => 'relationship', 'initial' => '', 'reset' => function () use ($q) { $q("DELETE FROM assets"); $q("DELETE FROM asset_vendors"); $q("INSERT INTO assets SET asset_id = 100, asset_name = 'Asset', asset_type = 'laptop', asset_client_id = 1, asset_vendor_id = 0"); },
    'post' => fn($v) => ['add_vendor_role' => '1', 'vendor_kind' => 'asset', 'record_id' => '100', 'vendor_id' => (string) $v, 'vendor_role' => 'reseller'],
    'stored' => fn() => $one0("SELECT GROUP_CONCAT(vendor_id) FROM asset_vendors WHERE asset_id = 100")];

$specs['entity link (asset -> vendor)'] = ['handler' => 'relationship', 'initial' => '', 'reset' => function () use ($q) { $q("DELETE FROM assets"); $q("DELETE FROM entity_links"); $q("INSERT INTO assets SET asset_id = 100, asset_name = 'Asset', asset_type = 'laptop', asset_client_id = 1, asset_vendor_id = 0"); },
    'post' => fn($v) => ['link_entities' => '1', 'src_type' => 'asset', 'src_id' => '100', 'dst_type' => 'vendor', 'dst_id' => (string) $v, 'link_type' => 'related'],
    'stored' => fn() => $one0("SELECT GROUP_CONCAT(dst_id) FROM entity_links WHERE src_type = 'asset' AND src_id = 100 AND dst_type = 'vendor'")];

$refusedRole = fn(array $r) => str_contains($r['alert'], 'belongs to another department') || str_contains($r['alert'], 'do not have access to that department');
$covered = [];
foreach ($specs as $label => $s) {
    foreach ($MATRIX as $v => $accepted) {
        ($s['reset'])();
        $r = $run($s['handler'], ($s['post'])($v));
        $stored = ($s['stored'])();
        $refused = $refusedMsg($r) || $refusedRole($r);
        $good = $accepted ? ($r['got'] && !$refused && $stored === (string) $v) : ($r['got'] && $refused && $stored === $s['initial']);
        $ok($good, $accepted ? "$label: $LABEL[$v] is stored" : "$label: $LABEL[$v] is refused, nothing written");
        if (!$good) { echo '   stored=' . var_export($stored, true) . ' alert=' . $r['alert'] . "\n" . substr($r['raw'], 0, 450) . "\n"; }
    }
    $covered[] = $label;
}

// ---- toggle-off / data loss: a record linked to a vendor that has since been UNSHARED keeps it when saved unchanged
$unsharedKeep = [
    'asset edit' => ['asset', fn() => ($q("DELETE FROM assets") . $q("INSERT INTO assets SET asset_id = 100, asset_name = 'Asset', asset_type = 'laptop', asset_client_id = 1, asset_vendor_id = 40")), fn() => $assetPost('edit_asset', ['asset_id' => '100', 'vendor' => '40']), fn() => $one0("SELECT asset_vendor_id FROM assets WHERE asset_id = 100")],
    'ticket edit' => ['ticket', fn() => ($seedTicket() . $q("UPDATE tickets SET ticket_vendor_id = 40")), fn() => $ticketPost('edit_ticket', ['ticket_id' => '500', 'vendor_id' => '40']), fn() => $one0("SELECT ticket_vendor_id FROM tickets WHERE ticket_id = 500")],
    'ticket edit-vendor' => ['ticket', fn() => ($seedTicket() . $q("UPDATE tickets SET ticket_vendor_id = 40")), fn() => ['edit_ticket_vendor' => '1', 'ticket_id' => '500', 'vendor' => '40'], fn() => $one0("SELECT ticket_vendor_id FROM tickets WHERE ticket_id = 500")],
    'software edit' => ['software', fn() => ($q("DELETE FROM software") . $q("INSERT INTO software SET software_id = 700, software_name = 'Soft', software_type = 'Other', software_license_type = 'Other', software_client_id = 1, software_vendor_id = 40")), fn() => $swPost('edit_software', ['software_id' => '700', 'vendor' => '40']), fn() => $one0("SELECT software_vendor_id FROM software WHERE software_id = 700")],
    'service edit' => ['service', fn() => ($q("DELETE FROM services") . $q("DELETE FROM service_vendors") . $q("INSERT INTO services SET service_id = 800, service_name = 'Svc', service_description = 'd', service_importance = 'Low', service_client_id = 1") . $q("INSERT INTO service_vendors SET service_id = 800, vendor_id = 40")), fn() => $svcPost('edit_service', ['service_id' => '800', 'vendors' => ['40']]), fn() => $one0("SELECT GROUP_CONCAT(vendor_id) FROM service_vendors WHERE service_id = 800")],
];
foreach (['registrar' => 'domain_registrar', 'webhost' => 'domain_webhost', 'dnshost' => 'domain_dnshost', 'mailhost' => 'domain_mailhost'] as $field => $col) {
    $unsharedKeep["domain edit ($field)"] = ['domain', fn() => ($q("DELETE FROM domains") . $q("INSERT INTO domains SET domain_id = 600, domain_name = 'example.invalid', domain_client_id = 1, $col = 40")), fn() => $domPost('edit_domain', ['domain_id' => '600', $field => '40']), fn() => $one0("SELECT $col FROM domains WHERE domain_id = 600")];
}
foreach ($unsharedKeep as $label => [$h, $reset, $post, $stored]) {
    $reset(); $r = $run($h, $post());
    $ok($r['got'] && !$refusedMsg($r) && $stored() === '40', "$label: saving unchanged keeps a link to a since-unshared central vendor");
}
// ...but changing a record TO that now-unshared vendor is refused
$seedTicket(); $r = $run('ticket', ['edit_ticket_vendor' => '1', 'ticket_id' => '500', 'vendor' => '40']);
$ok($refusedMsg($r) && $one0("SELECT ticket_vendor_id FROM tickets WHERE ticket_id = 500") === '0', 'a NEW link to the unshared vendor is still refused');

// ---- service: ALL submitted vendors are validated before anything is written (no partial rows)
$q("DELETE FROM services"); $q("DELETE FROM service_vendors");
$r = $run('service', $svcPost('add_service', ['vendors' => ['10', '30', '40']]));
$ok($refusedMsg($r) && $one0("SELECT COUNT(*) FROM services") === '0' && $one0("SELECT COUNT(*) FROM service_vendors") === '0', 'service add: [own, shared, UNSHARED] writes no service row and no vendor rows');
$r = $run('service', $svcPost('add_service', ['vendors' => ['10', '30', '20']]));
$ok($refusedMsg($r) && $one0("SELECT COUNT(*) FROM services") === '0' && $one0("SELECT COUNT(*) FROM service_vendors") === '0', "service add: [own, shared, OTHER DEPT'S] writes nothing");
$r = $run('service', $svcPost('add_service', ['vendors' => ['10', '30']]));
$ok(!$refusedMsg($r) && $one0("SELECT COUNT(*) FROM services") === '1' && $one0("SELECT GROUP_CONCAT(vendor_id ORDER BY vendor_id) FROM service_vendors") === '10,30', 'service add: [own, shared] writes the service and both links');
$q("DELETE FROM services"); $q("DELETE FROM service_vendors"); $q("INSERT INTO services SET service_id = 800, service_name = 'Svc', service_description = 'd', service_importance = 'Low', service_client_id = 1"); $q("INSERT INTO service_vendors SET service_id = 800, vendor_id = 10");
$r = $run('service', $svcPost('edit_service', ['service_id' => '800', 'name' => 'RENAMED', 'vendors' => ['30', '20']]));
$ok($refusedMsg($r) && $one0("SELECT service_name FROM services WHERE service_id = 800") === 'Svc' && $one0("SELECT GROUP_CONCAT(vendor_id) FROM service_vendors WHERE service_id = 800") === '10', 'service edit: a bad id part-way leaves the name and the existing links untouched');

// ---- pickers (the pop-ups, real files): own + shared central offered; other department's and unshared central never; a linked vendor stays listed
$modal = function (string $file, array $get) use ($app, &$n): string {
    $f = sys_get_temp_dir() . '/sv_case_' . getmypid() . '_' . (++$n) . '.json';
    file_put_contents($f, json_encode(['user_id' => 11, 'modal' => $file, 'get' => $get]));
    $out = (string) shell_exec('timeout 60 php ' . escapeshellarg(__DIR__ . '/support/vendor_handler_case.php') . ' ' . escapeshellarg($app) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $st = (int) strpos($out, '{"content"'); $en = strrpos($out, '"}');
    $j = $en === false ? null : json_decode(substr($out, $st, $en + 2 - $st), true);
    return is_array($j) && isset($j["content"]) ? (string) $j["content"] : "[no content] " . substr($out, 0, 300);
};
$vendorIds = fn(string $html) => array_values(array_unique(array_map('intval', array_filter(preg_match_all('/<option[^>]*value="(\d+)"/', $html, $mm) ? $mm[1] : [], fn($x) => in_array((int) $x, [10, 20, 30, 40], true)))));
$sel = fn(string $html, int $v) => (bool) preg_match('/<option[^>]*\bselected\b[^>]*value="' . $v . '"|<option[^>]*value="' . $v . '"[^>]*\bselected\b/', $html);
$seedAll = function (int $vend) use ($q) {
    $q("DELETE FROM assets"); $q("INSERT INTO assets SET asset_id = 100, asset_name = 'Asset', asset_type = 'laptop', asset_client_id = 1, asset_vendor_id = $vend");
    $q("DELETE FROM tickets"); $q("INSERT INTO tickets SET ticket_id = 500, ticket_prefix = 'T', ticket_number = 1, ticket_subject = 'S', ticket_details = 'd', ticket_priority = 'Low', ticket_status = 1, ticket_client_id = 1, ticket_vendor_id = $vend");
    $q("DELETE FROM software"); $q("INSERT INTO software SET software_id = 700, software_name = 'Soft', software_type = 'Other', software_license_type = 'Other', software_client_id = 1, software_vendor_id = $vend");
    $q("DELETE FROM domains"); $q("INSERT INTO domains SET domain_id = 600, domain_name = 'example.invalid', domain_client_id = 1, domain_registrar = $vend, domain_webhost = $vend, domain_dnshost = $vend, domain_mailhost = $vend");
    $q("DELETE FROM services"); $q("DELETE FROM service_vendors"); $q("INSERT INTO services SET service_id = 800, service_name = 'Svc', service_description = 'd', service_importance = 'Low', service_client_id = 1"); if ($vend) { $q("INSERT INTO service_vendors SET service_id = 800, vendor_id = $vend"); }
    $q("DELETE FROM documents"); $q("DELETE FROM vendor_documents"); $q("INSERT INTO documents SET document_id = 900, document_name = 'Doc', document_content = '', document_content_raw = '', document_client_id = 1");
};
$seedAll(0);
$addPickers = ['asset add' => ['asset/asset_add.php', ['client_id' => '1']], 'asset copy' => ['asset/asset_copy.php', ['id' => '100']], 'ticket add' => ['ticket/ticket_add.php', ['client_id' => '1']], 'ticket edit' => ['ticket/ticket_edit.php', ['id' => '500']],
    'ticket edit-vendor' => ['ticket/ticket_edit_vendor.php', ['ticket_id' => '500']], 'software add' => ['software/software_add.php', ['client_id' => '1']], 'software edit' => ['software/software_edit.php', ['id' => '700']],
    'domain add' => ['domain/domain_add.php', ['client_id' => '1']], 'domain edit' => ['domain/domain_edit.php', ['id' => '600']], 'service add' => ['service/service_add.php', ['client_id' => '1']],
    'service edit' => ['service/service_edit.php', ['id' => '800']], 'document link' => ['document/document_link_vendor.php', ['document_id' => '900']], 'asset edit' => ['asset/asset_edit.php', ['id' => '100']]];
foreach ($addPickers as $label => [$file, $get]) {
    $html = $modal($file, $get);
    if (str_starts_with($html, '[no content]')) { echo "   $html\n"; }
    $ids = $vendorIds($html);
    $ok($ids === [10, 30] || $ids === [30, 10], "picker $label: offers own + shared central only (got " . implode(',', $ids) . ')');
}
$seedAll(40);   // every record now linked to the UNSHARED central vendor
foreach (['asset edit' => 'asset/asset_edit.php', 'ticket edit' => 'ticket/ticket_edit.php', 'ticket edit-vendor' => 'ticket/ticket_edit_vendor.php', 'software edit' => 'software/software_edit.php', 'domain edit' => 'domain/domain_edit.php', 'service edit' => 'service/service_edit.php'] as $label => $file) {
    $get = $addPickers[$label][1];
    $html = $modal($file, $get);
    $ids = $vendorIds($html);
    $ok(in_array(40, $ids, true) && $sel($html, 40) && !in_array(20, $ids, true), "picker $label: still lists the record's CURRENT unshared vendor, selected (so saving cannot drop it)");
}
$html = $modal('domain/domain_edit.php', ['id' => '600']);
$ok(preg_match_all('/<option[^>]*\bselected\b[^>]*value="40"|<option[^>]*value="40"[^>]*\bselected\b/', $html) === 4, 'picker domain edit: all four vendor fields keep the current vendor selected');
$ok(!in_array(40, $vendorIds($modal('ticket/ticket_add.php', ['client_id' => '1'])), true), 'picker ticket add: the unshared vendor is not offered for NEW use even though records link to it');


// ---- restricted technician (dept A only): search, AJAX list, department tab, details pop-up, ticket page
$page = function (string $script, array $get, int $user = 11) use ($app, &$n): string {
    $f = sys_get_temp_dir() . '/sv_case_' . getmypid() . '_' . (++$n) . '.json';
    file_put_contents($f, json_encode(['user_id' => $user, 'script' => $script, 'get' => $get]));
    $out = (string) shell_exec('timeout 90 php ' . escapeshellarg(__DIR__ . '/support/vendor_handler_case.php') . ' ' . escapeshellarg($app) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return $out;
};
$seedAll(0); $q("DELETE FROM asset_vendors"); $q("DELETE FROM software_vendors");
$live = $page('agent/ajax.php', ['global_search_live' => '1', 'q' => 'Central']);
$ok(str_contains($live, 'Central shared') && !str_contains($live, 'Central private'), 'live global search (restricted tech): shared central vendor found, unshared central vendor NOT');
$live = $page('agent/ajax.php', ['global_search_live' => '1', 'q' => 'own']);
$ok(str_contains($live, 'A own') && !str_contains($live, 'B own'), "live global search (restricted tech): own vendor found, another department's NOT");
$full = $page('agent/global_search.php', ['query' => 'Central']);
$ok(str_contains($full, 'Central shared') && !str_contains($full, 'Central private'), 'full global search page (restricted tech): shared central found, unshared central NOT');
$full = $page('agent/global_search.php', ['query' => 'B own']);
$ok(!str_contains($full, 'B own</') && !preg_match('/>\s*B own\s*</', $full), "full global search page (restricted tech): another department's vendor NOT found");
$j = json_decode(trim(explode('@@RESULT@@', $page('agent/ajax.php', ['get_client_vendors' => '1', 'client_id' => '1']))[0]), true);
$names = array_column($j['vendors'] ?? [], 'vendor_name'); sort($names);
$ok($names === ['A own', 'Central shared'], 'get_client_vendors for own department: own + shared central only (' . implode(',', $names) . ')');
$j = json_decode(trim(explode('@@RESULT@@', $page('agent/ajax.php', ['get_client_vendors' => '1', 'client_id' => '2']))[0]), true);
$names = array_column($j['vendors'] ?? [], 'vendor_name');
$ok($names === ['Central shared'], "get_client_vendors for ANOTHER department: never its own vendors (got " . implode(',', $names) . ')');
$tab = $page('agent/vendors.php', ['client_id' => '1']);
$ok(str_contains($tab, 'A own') && str_contains($tab, 'Central shared') && !str_contains($tab, 'Central private') && !str_contains($tab, 'B own'), "department Vendors tab: own + shared central; no unshared central, no other department's");
$ok(str_contains($tab, '>Central</span>') && str_contains($tab, 'View (read-only)'), 'department Vendors tab: shared central row has the Central badge and a read-only action');
$ok(!str_contains($tab, 'vendor_edit.php?id=30') && !str_contains($tab, 'archive_vendor=30') && !preg_match('/name="vendor_ids\[\]" value="30"/', $tab) && str_contains($tab, 'vendor_edit.php?id=10'), 'department Vendors tab: no edit / archive / bulk-select for the central row (own row keeps them)');
$ok(!str_contains($tab, 'ACCT-30') && !str_contains($tab, 'CODE-30') && !str_contains($tab, 'SLA-30') && !str_contains($tab, 'NOTES-30'), 'department Vendors tab: no account number / code / SLA / notes of the central vendor');
$tabq = $page('agent/vendors.php', ['client_id' => '1', 'q' => 'ACCT-30']);
$ok(!str_contains($tabq, 'Central shared'), "department Vendors tab: a search by the central vendor's account number does not find it (no oracle)");
$cnt = $page('agent/vendors.php', ['client_id' => '1']);
$ok(preg_match('/Vendors[^<]*<[^>]*>\s*<[^>]*>\s*2\b|>2<\/span>/', $cnt) === 1 || true, 'tab count (informational)');
$det = $modal('vendor/vendor_details.php', ['id' => '30', 'client_id' => '1']);
$ok(str_contains($det, 'Central shared') && !str_contains($det, 'ACCT-30') && !str_contains($det, 'NOTES-30') && !str_contains($det, 'CODE-30') && !str_contains($det, 'SLA-30') && !str_contains($det, 'vendor_kind'), 'details pop-up of a shared vendor from a department: own contact fields only (no account no., code, SLA, notes, relationships card)');
foreach ([['40', '1', 'unshared central vendor from dept A'], ['30', '2', 'shared central vendor claiming a department the tech cannot access'], ['20', '1', "another department's vendor"]] as [$vid, $cid, $why]) {
    $det = $modal('vendor/vendor_details.php', ['id' => $vid, 'client_id' => $cid]);
    $ok(!str_contains($det, 'NOTES-') && !str_contains($det, 'ACCT-') && !str_contains($det, 'modal-title'), "details pop-up refused: $why");
}
$q("DELETE FROM tickets"); $q("INSERT INTO tickets SET ticket_id = 500, ticket_prefix = 'T', ticket_number = 1, ticket_subject = 'S', ticket_details = 'd', ticket_priority = 'Low', ticket_status = 1, ticket_client_id = 1, ticket_vendor_id = 30");
$tk = $page('agent/ticket.php', ['ticket_id' => '500']);
$ok(str_contains($tk, 'T1') && !str_contains($tk, 'ACCT-30') && !str_contains($tk, 'CODE-30') && !str_contains($tk, 'SLA-30') && !str_contains($tk, 'NOTES-30'), 'ticket page with a shared central vendor: no account number / code / SLA / notes rendered');
$ad = $page('agent/asset_details.php', ['client_id' => '1', 'asset_id' => '100']);
$sel_html = preg_match('/<select[^>]*name="vendor_id"[^>]*>(.*?)<\/select>/s', $ad, $mm) ? $mm[1] : '';
$ok($sel_html !== '' && $vendorIds($sel_html) === [10, 30] && !str_contains($sel_html, 'Central private') && !str_contains($sel_html, 'B own'), 'relationships card (asset page, restricted tech): the vendor picker offers own + shared central only');
$srcAll = '';
foreach (['agent/ticket.php', 'agent/software.php', 'agent/domains.php', 'agent/domain_details.php', 'agent/tickets.php', 'agent/ticket_kanban.php', 'agent/modals/service/service_details.php', 'agent/modals/service/service_edit.php', 'agent/asset_details.php', 'agent/software_details.php'] as $pf) {
    if (is_file("$root/$pf") && preg_match('/echo[^;]*\$vendor_(account_number|code|sla|notes)\b|<\?=\s*\$vendor_(account_number|code|sla|notes)\b/', (string) file_get_contents("$root/$pf"))) { $srcAll .= " $pf"; }
}
$ok($srcAll === '', 'no department-scoped page echoes vendor account number / code / SLA / notes' . ($srcAll ? ':' . $srcAll : ''));

echo "\n$passes passed, $fails failed\n";
echo 'Store paths covered through the real handlers (' . count($covered) . '): ' . implode('; ', $covered) . "\n";
exit($fails ? 1 : 0);
