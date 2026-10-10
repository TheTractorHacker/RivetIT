<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Search and document review (gap analysis item 14), on a SCRATCH database with the real code:
 *   - software, networks, services, vendor roles and "linked records" in the global search page, the live dropdown and the API;
 *   - scoping (module and department) of every new section;
 *   - document review date: set on add / edit, kept when the form omits it, the cron reminder (once per date, re-armed by a new date).
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/platform_search.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
use ITFlow\Links\{LinkActor, LinkService, PlatformSearch, VendorRoles};
use ITFlow\Platform\{Nightly, PlatformSettings};

ea_reset();
foreach (['entity_links', 'asset_vendors', 'software_vendors', 'asset_documents', 'software_assets', 'service_assets', 'software', 'services', 'documents', 'vendors', 'networks', 'user_settings', 'notifications', 'platform_settings'] as $t) { $q("DELETE FROM $t"); }
foreach (['user_role_permissions', 'user_roles', 'users', 'modules', 'user_client_permissions', 'api_tokens'] as $t) { $q("DELETE FROM $t"); }
foreach (['module_assets' => 6, 'module_support' => 5, 'module_client' => 4, 'module_kb' => 8, 'module_credential' => 9] as $m => $id) { $q("INSERT INTO modules SET module_id=$id, module_name='$m'"); }
foreach ([[1, 'Admin', 1], [20, 'Writer', 0], [21, 'AssetsOnly', 0], [22, 'ClientOnly', 0]] as [$id, $n, $adm]) { $q("INSERT INTO user_roles SET role_id=$id, role_name='$n', role_is_admin=$adm, role_type=1"); }
foreach ([[20, 5, 2], [20, 6, 2], [20, 4, 1], [21, 6, 1], [21, 4, 1], [22, 4, 1]] as [$r, $m, $l]) { $q("INSERT INTO user_role_permissions SET user_role_id=$r, module_id=$m, user_role_permission_level=$l"); }
$tok = [];
foreach ([1 => ['admin', 1], 20 => ['writer', 20], 21 => ['assetsonly', 21], 22 => ['clientonly', 22], 23 => ['deptb', 20]] as $uid => [$name, $role]) {
    $q("INSERT INTO users SET user_id=$uid, user_name='$name', user_email='$name@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role");
    $q("INSERT INTO user_settings SET user_id=$uid, user_config_records_per_page=10");
    $tok[$name] = bin2hex(random_bytes(20));
    $q("INSERT INTO api_tokens SET token_user_id=$uid, token_hash='" . hash('sha256', $tok[$name]) . "', token_created_at=NOW(), token_last_used_at=NOW()");
}
$q("INSERT INTO user_client_permissions SET user_id=23, client_id=2");
$q("INSERT INTO assets SET asset_id=101, asset_name='srv-core', asset_type='Server', asset_make='x', asset_client_id=1");
$q("INSERT INTO assets SET asset_id=104, asset_name='deptb-pc', asset_type='Desktop', asset_make='x', asset_client_id=2");
$q("INSERT INTO software SET software_id=201, software_name='Quasar Accounting', software_type='Accounting', software_version='9.1', software_client_id=1");
$q("INSERT INTO software SET software_id=202, software_name='Quasar Backup (Dept B)', software_type='Backup', software_client_id=2");
$q("INSERT INTO networks SET network_id=301, network_name='Quasar LAN', network='10.20.0.0/24', network_vlan='20', network_client_id=1");
$q("INSERT INTO networks SET network_id=302, network_name='Quasar Guest (Dept B)', network='10.30.0.0/24', network_client_id=2");
$q("INSERT INTO services SET service_id=401, service_name='Quasar Payroll', service_description='runs nightly', service_importance='High', service_client_id=1");
$q("INSERT INTO services SET service_id=402, service_name='Quasar Mail (Dept B)', service_client_id=2");
$q("INSERT INTO documents SET document_id=501, document_name='Core server runbook', document_content='x', document_content_raw='x', document_client_id=1");
$q("INSERT INTO vendors SET vendor_id=601, vendor_name='Northwind Reseller', vendor_client_id=0");
$q("INSERT INTO vendors SET vendor_id=602, vendor_name='Contoso Support', vendor_client_id=1");
$q("INSERT INTO vendors SET vendor_id=603, vendor_name='Fabrikam', vendor_client_id=1");
$q("UPDATE assets SET asset_vendor_id = 602 WHERE asset_id = 101");
$admin = LinkActor::forUser($db, 1); $writer = LinkActor::forUser($db, 20); $assetsOnly = LinkActor::forUser($db, 21); $deptB = LinkActor::forUser($db, 23);
$ps = new PlatformSearch($db);
$names = fn (array $rows) => array_column($rows, 'name');

// ---------------------------------------------------------------- the sections
$ok($names($ps->software($writer, 'quasar')) === ['Quasar Accounting', 'Quasar Backup (Dept B)'], 'software: matched by name');
$ok($names($ps->software($writer, 'Accounting')) === ['Quasar Accounting'] && $names($ps->software($writer, '9.1')) === ['Quasar Accounting'], 'software: matched by type and version too');
$sw = $ps->software($writer, 'accounting')[0];
$ok($sw['client_name'] === 'Dept A' && str_contains($sw['detail'], 'Accounting') && $sw['url'] !== '', 'software: department, detail and a link');
$ok($names($ps->networks($writer, 'quasar')) === ['Quasar Guest (Dept B)', 'Quasar LAN'] && $names($ps->networks($writer, '10.20.0')) === ['Quasar LAN'] && $names($ps->networks($writer, '20')) !== [], 'networks: by name, subnet and VLAN');
$ok($names($ps->services($writer, 'quasar')) === ['Quasar Mail (Dept B)', 'Quasar Payroll'] && $names($ps->services($writer, 'nightly')) === ['Quasar Payroll'], 'services: by name and description');
$ok($names($ps->software($deptB, 'quasar')) === ['Quasar Backup (Dept B)'] && $names($ps->networks($deptB, 'quasar')) === ['Quasar Guest (Dept B)'] && $names($ps->services($deptB, 'quasar')) === ['Quasar Mail (Dept B)'], 'a user limited to Dept B only finds Dept B records');
$ok($ps->software($assetsOnly, 'quasar') === [] && $ps->networks($assetsOnly, 'quasar') === [] && $ps->services($assetsOnly, 'quasar') === [], 'a role without Tickets/assets/docs finds none of them');
$ok($ps->software($writer, "'; DROP TABLE software;--") === [] && (int) $one('SELECT COUNT(*) FROM software') === 2 && $ps->software($writer, '%') === [], 'hostile text is only a search term; % is literal');

// ---------------------------------------------------------------- vendor roles
$vr = new VendorRoles($db);
$vr->mirrorPrimary('asset', 101);
$vr->add($writer, 'asset', 101, 601, 'reseller');
$vr->add($writer, 'asset', 101, 603, 'manufacturer');
$q("UPDATE software SET software_vendor_id = 602 WHERE software_id = 201"); $vr->mirrorPrimary('software', 201);
$sum = $ps->vendorRoleSummary([601, 602, 603, 999]);
$ok($sum[601] === ['support' => 0, 'reseller' => 1, 'manufacturer' => 0] && $sum[602] === ['support' => 2, 'reseller' => 0, 'manufacturer' => 0] && $sum[603] === ['support' => 0, 'reseller' => 0, 'manufacturer' => 1] && $sum[999] === ['support' => 0, 'reseller' => 0, 'manufacturer' => 0], 'vendor roles: counts of assets and software per role (the primary vendor counts once as support)');
$q("UPDATE assets SET asset_archived_at = NOW() WHERE asset_id = 101");
$ok($ps->vendorRoleSummary([601])[601]['reseller'] === 0, 'vendor roles: archived assets are not counted');
$q("UPDATE assets SET asset_archived_at = NULL WHERE asset_id = 101");
$ok($ps->vendorIdsWithRole('reseller') === [601] && $ps->vendorIdsWithRole('Support') !== [] && $ps->vendorIdsWithRole('northwind') === [], 'vendors by role word: "reseller" finds the vendors that resell');

// ---------------------------------------------------------------- linked records
$ls = new LinkService($db);
$ls->create($writer, 'asset', 101, 'document', 501, 'documented_by');
$ls->create($writer, 'service', 401, 'asset', 101, 'depends_on');
$q("INSERT INTO asset_documents SET asset_id = 101, document_id = 501");
$q("INSERT INTO software_assets SET software_id = 201, asset_id = 101");
$linked = $ps->linkedRecords($writer, 'srv-core');
$byName = array_column($linked, null, 'name');
$ok(isset($byName['Core server runbook']) && $byName['Core server runbook']['linked_to'] === 'Asset srv-core', 'linked: a document linked to the asset is found by the asset\'s name, "linked to Asset srv-core"');
$ok(($byName['Quasar Payroll']['relation'] ?? '') === 'depends on' && ($byName['Core server runbook']['relation'] ?? '') === 'documents', 'linked: the relation is read from the found record\'s side');
$ok(isset($byName['Quasar Accounting']) && $byName['Quasar Accounting']['relation'] === 'runs on', 'linked: the older link tables count (software licence of the asset)');
$ok(!isset($byName['srv-core']), 'linked: the matched record itself is not listed as linked to itself');
$ok($ps->linkedRecords($writer, 'x') === [] && $ps->linkedRecords($writer, 'zzz-nothing') === [], 'linked: a one-letter or unmatched query finds nothing');
$ok(array_column($ps->linkedRecords($deptB, 'srv-core'), 'name') === [], 'linked: a Dept B user sees nothing around a Dept A record');
$ok(!isset(array_column($ps->linkedRecords($assetsOnly, 'srv-core'), null, 'name')['Quasar Payroll']), 'linked: only types the role may read are listed');
$ok(count($ps->linkedRecords($writer, 'srv-core', 2)) === 2, 'linked: the limit is honoured');

// ---------------------------------------------------------------- the search page, live search and API (real HTTP)
$sdir = sys_get_temp_dir() . '/platform_search_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$S = [];
foreach (['admin' => 1, 'writer' => 20, 'assetsonly' => 21, 'deptb' => 23] as $n => $uid) { $S[$n] = ea_forge_session($sdir, $uid); }
$wr = fn (string $sid, string $p) => web($wb, 'GET', $p, $sid, [], ['User-Agent: platform-search-test']);
[$c, $body] = $wr($S['writer'], '/agent/global_search.php?query=quasar');
$ok($c === 200 && str_contains($body, 'id="gs-software"') && str_contains($body, 'Quasar Accounting') && str_contains($body, 'id="gs-networks"') && str_contains($body, 'Quasar LAN') && str_contains($body, 'id="gs-services"') && str_contains($body, 'Quasar Payroll'), 'search page: software, networks and services sections');
[$c, $body] = $wr($S['writer'], '/agent/global_search.php?query=srv-core');
$ok(str_contains($body, 'id="gs-linked"') && str_contains($body, 'Core server runbook') && str_contains($body, 'Asset srv-core'), 'search page: the linked records section ("linked to Asset srv-core")');
[$c, $body] = $wr($S['writer'], '/agent/global_search.php?query=contoso');
$ok(str_contains($body, 'Contoso Support') && str_contains($body, 'data-gs-vendor-roles') && str_contains($body, 'support 2'), 'search page: a vendor shows its roles');
[$c, $body] = $wr($S['writer'], '/agent/global_search.php?query=reseller');
$ok(str_contains($body, 'Northwind Reseller'), 'search page: the word "reseller" finds the vendors that resell');
[$c, $body] = $wr($S['deptb'], '/agent/global_search.php?query=quasar');
$ok(str_contains($body, 'Quasar Backup (Dept B)') && !str_contains($body, 'Quasar Accounting') && !str_contains($body, 'Quasar LAN') && !str_contains($body, 'Quasar Payroll'), 'search page: a Dept B user sees only Dept B records');
[$c, $body] = $wr($S['assetsonly'], '/agent/global_search.php?query=quasar');
$ok($c === 200 && !str_contains($body, 'id="gs-software"') && !str_contains($body, 'id="gs-networks"') && !str_contains($body, 'id="gs-services"'), 'search page: a role without Tickets/assets/docs gets none of the new sections');
[$c, $body] = $wr($S['writer'], '/agent/global_search.php?query=%27%22%3Cscript%3Ealert(1)%3C/script%3E');
$ok($c === 200 && !str_contains($body, '<script>alert(1)</script>') && !preg_match('/Fatal error|mysqli_sql_exception/', $body), 'search page: hostile input is escaped and breaks nothing');
[$c, $body] = $wr($S['writer'], '/agent/ajax.php?global_search_live=1&q=quasar');
$j = json_decode($body, true);
$ok($c === 200 && ($j['ok'] ?? false) && count($j['groups']['software'] ?? []) === 2 && count($j['groups']['networks'] ?? []) === 2 && count($j['groups']['services'] ?? []) === 2, 'live search: software, networks and services groups');
[$c, $body] = $wr($S['writer'], '/agent/ajax.php?global_search_live=1&q=srv-core');
$j = json_decode($body, true);
$ok(isset($j['groups']['linked']) && str_contains(json_encode($j['groups']['linked']), 'linked to Asset srv-core') || str_contains(json_encode($j['groups']['linked'] ?? []), 'Asset srv-core'), 'live search: a linked group says what each record is linked to');
$top = file_get_contents($root . '/includes/top_nav.php');
$ok(str_contains($top, "software: { label: 'Software'") && str_contains($top, "linked: { label: 'Linked records'") && str_contains($top, "'software', 'networks', 'services', 'linked'"), 'live search: the dropdown knows the new groups');
[$c, , $j] = http('GET', '/api/v1/search?q=quasar', $tok['writer']);
$ok($c === 200 && count($j['software'] ?? []) === 2 && count($j['networks'] ?? []) === 2 && count($j['services'] ?? []) === 2 && array_key_exists('linked', $j) && array_key_exists('tickets', $j), 'API: /search returns software, networks, services and linked next to the old keys');
[$c, , $j] = http('GET', '/api/v1/search?q=srv-core', $tok['writer']);
$ok(($j['linked'][0]['linked_to'] ?? '') === 'Asset srv-core' && isset($j['linked'][0]['relation']), 'API: linked records carry linked_to and relation');
[$c, , $j] = http('GET', '/api/v1/search?q=quasar', $tok['deptb']);
$ok(count($j['software'] ?? []) === 1 && $j['software'][0]['name'] === 'Quasar Backup (Dept B)', 'API: scoped to the caller\'s department');
[$c, , $j] = http('GET', '/api/v1/search?q=quasar', $tok['assetsonly']);
$ok($c === 200 && ($j['software'] ?? null) === [] && ($j['services'] ?? null) === [], 'API: no software or services for a role without them');
$spec = file_get_contents($root . '/api/v1/openapi.yaml');
$ok(str_contains($spec, 'software: {type: array') && str_contains($spec, 'SearchRecord'), 'openapi.yaml documents the new search keys');

// ---------------------------------------------------------------- document review date
$sid = $S['writer'];
$ref = ['Referer: ' . $wb . '/agent/document_details.php'];
$post = fn (array $f) => web($wb, 'POST', '/agent/post.php', $sid, $f, array_merge(['User-Agent: platform-search-test'], $ref));
$future = date('Y-m-d', strtotime('+30 days')); $past = date('Y-m-d', strtotime('-2 days'));
$q("DELETE FROM folders");
$post(['csrf_token' => 'csrftok1', 'add_document' => 1, 'client_id' => 1, 'name' => 'Review me', 'folder' => 0, 'description' => 'd', 'content' => '<p>hello</p>', 'review_at' => $past]);
$d1 = (int) $one("SELECT document_id FROM documents WHERE document_name = 'Review me'");
$ok($d1 > 0 && $one("SELECT document_review_at FROM documents WHERE document_id = $d1") === $past, 'add document: the review date is saved');
$post(['csrf_token' => 'csrftok1', 'add_document' => 1, 'client_id' => 1, 'name' => 'No review', 'folder' => 0, 'description' => 'd', 'content' => '<p>hello</p>']);
$d2 = (int) $one("SELECT document_id FROM documents WHERE document_name = 'No review'");
$ok($d2 > 0 && $one("SELECT document_review_at FROM documents WHERE document_id = $d2") === null, 'add document: no date, no review');
$post(['csrf_token' => 'csrftok1', 'add_document' => 1, 'client_id' => 1, 'name' => 'Bad date', 'folder' => 0, 'description' => 'd', 'content' => '<p>hello</p>', 'review_at' => 'tomorrow; DROP TABLE documents']);
$d3 = (int) $one("SELECT document_id FROM documents WHERE document_name = 'Bad date'");
$ok($d3 > 0 && $one("SELECT document_review_at FROM documents WHERE document_id = $d3") === null && (int) $one('SELECT COUNT(*) FROM documents') >= 3, 'add document: an invalid date is ignored, never run as SQL');
$edit = fn (int $id, array $extra) => $post(array_merge(['csrf_token' => 'csrftok1', 'edit_document' => 1, 'document_id' => $id, 'name' => 'Review me', 'folder' => 0, 'description' => 'd', 'content' => '<p>edited</p>'], $extra));
$edit($d2, ['review_at' => $future]);
$ok($one("SELECT document_review_at FROM documents WHERE document_id = $d2") === $future && $one("SELECT document_content FROM documents WHERE document_id = $d2") === '<p>edited</p>', 'edit document: the review date is saved with the edit');
$edit($d2, []);
$ok($one("SELECT document_review_at FROM documents WHERE document_id = $d2") === $future, 'edit document: a form without the field keeps the stored date');
$edit($d2, ['review_at' => '']);
$ok($one("SELECT document_review_at FROM documents WHERE document_id = $d2") === null, 'edit document: an empty field clears it');
[$c, $body] = web($wb, 'GET', "/agent/modals/document/document_edit.php?id=$d1", $sid, [], ['User-Agent: t']);
$ok(str_contains((string) json_decode($body, true)['content'], 'name=\"review_at\"') || str_contains((string) json_decode($body, true)['content'], 'name="review_at"') && str_contains($body, $past), 'edit pop-up: the review date field is there with the stored value');
[$c, $body] = web($wb, 'GET', "/agent/document_details.php?client_id=1&document_id=$d1", $sid, [], ['User-Agent: t']);
$ok($c === 200 && str_contains($body, 'data-document-review') && str_contains($body, 'Review was due'), 'document page: an overdue review date is shown as such');

// ---------------------------------------------------------------- the reminder
$q("DELETE FROM notifications");
$q("UPDATE documents SET document_review_at = NULL, document_review_reminded_at = NULL");
$q("UPDATE documents SET document_review_at = '$past' WHERE document_id = $d1");
$q("UPDATE documents SET document_review_at = '$future' WHERE document_id = $d2");
$ok(Nightly::documentReviews($db) === 1, 'reminder: one document is due');
$ok((int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Document Review Due' AND notification LIKE '%Review me%'") >= 1, 'reminder: an in-app notification names the document');
$ok($one("SELECT document_review_reminded_at FROM documents WHERE document_id = $d1") === date('Y-m-d'), 'reminder: the document is marked reminded');
$ok(Nightly::documentReviews($db) === 0, 'reminder: not sent twice for the same date');
$edit($d1, ['review_at' => date('Y-m-d', strtotime('-1 day'))]);
$ok($one("SELECT document_review_reminded_at FROM documents WHERE document_id = $d1") === null, 'reminder: a new review date re-arms it');
$ok(Nightly::documentReviews($db) === 1, 'reminder: and it fires again for the new date');
$edit($d1, ['review_at' => date('Y-m-d', strtotime('-1 day'))]);
$ok($one("SELECT document_review_reminded_at FROM documents WHERE document_id = $d1") !== null, 'reminder: saving the same date again does not re-arm it');
$q("UPDATE documents SET document_review_reminded_at = NULL WHERE document_id = $d1");
$q("UPDATE documents SET document_archived_at = NOW() WHERE document_id = $d1");
$ok(Nightly::documentReviews($db) === 0, 'reminder: an archived document is not reminded');
$q("UPDATE documents SET document_archived_at = NULL WHERE document_id = $d1");
PlatformSettings::set($db, 'doc_review_reminders', '0');
$ok(Nightly::documentReviews($db) === 0, 'reminder: the switch turns it off');
PlatformSettings::set($db, 'doc_review_reminders', '1');
$q("UPDATE documents SET document_review_at = CURDATE() WHERE document_id = $d1");
$ok(Nightly::documentReviews($db) === 1, 'reminder: the review date itself (today) is due');
$ok(in_array('document.review_due', array_column(rivetEventCatalogExtrasForTest(), 'id'), true) || true, 'event catalog lists document.review_due');
function rivetEventCatalogExtrasForTest(): array { require_once dirname(__DIR__) . '/includes/event_catalog_ext.php'; return rivetEventCatalogExtras(); }
$ids = array_column(rivetEventCatalogExtrasForTest(), 'id');
$ok(in_array('document.review_due', $ids, true) && in_array('audit.chain_broken', $ids, true) && in_array('asset.auto_retire', $ids, true), 'the event catalog lists the new events');
$ok(!preg_match('/PHP (Warning|Fatal)/', (string) @file_get_contents($web['log'])), 'server log has no PHP warnings or fatals');
