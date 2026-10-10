<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Entity links / Relationships (gap analysis item 13), on a SCRATCH database with the real code:
 *   - LinkService: create / delete rules (permissions per module, department scope, global records, duplicates, self links), audit rows;
 *   - the impact walk (transitive, depth 3, cycle safe, hidden records neither listed nor walked through);
 *   - derived read-only rows from the older link tables, unioned at query time with no copy;
 *   - multiple vendors per asset / software with a role, the primary mirrored;
 *   - api/v1/relationships over real HTTP; the Relationships card, the Link item modal, the picker and the post handler over real HTTP.
 * Same scratch rules and environment as tests/endpoint_agent_lib.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/entity_links.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
use ITFlow\Links\{EntityTypes, LinkActor, LinkService, VendorRoles};

ea_reset();
foreach (['entity_links', 'asset_vendors', 'software_vendors', 'asset_documents', 'software_assets', 'service_assets', 'service_vendors', 'software', 'services', 'documents', 'kb_articles', 'vendors', 'networks', 'domains', 'credentials', 'contacts', 'locations'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM modules"); $q("DELETE FROM user_role_permissions"); $q("DELETE FROM user_roles"); $q("DELETE FROM users"); $q("DELETE FROM user_client_permissions"); $q("DELETE FROM api_tokens");
foreach (['module_assets' => 6, 'module_support' => 5, 'module_client' => 4, 'module_kb' => 8, 'module_credential' => 9, 'module_financial' => 10] as $m => $id) { $q("INSERT INTO modules SET module_id=$id, module_name='$m'"); }
// roles: 1 admin; 20 writer (support 2, assets 2, kb 2, departments 1); 21 reader (support 1, assets 1); 22 client-only (module_client 2); 23 writer limited to Dept B
foreach ([[1, 'Admin', 1], [20, 'Writer', 0], [21, 'Reader', 0], [22, 'ClientOnly', 0]] as [$id, $n, $adm]) { $q("INSERT INTO user_roles SET role_id=$id, role_name='$n', role_is_admin=$adm, role_type=1"); }
foreach ([[20, 5, 2], [20, 6, 2], [20, 8, 2], [20, 4, 1], [21, 5, 1], [21, 6, 1], [21, 4, 1], [22, 4, 2]] as [$r, $m, $l]) { $q("INSERT INTO user_role_permissions SET user_role_id=$r, module_id=$m, user_role_permission_level=$l"); }
$tok = [];
foreach ([1 => ['admin', 1], 20 => ['writer', 20], 21 => ['reader', 21], 22 => ['clientonly', 22], 23 => ['deptb', 20]] as $uid => [$name, $role]) {
    $q("INSERT INTO users SET user_id=$uid, user_name='$name', user_email='$name@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role");
    $tok[$name] = bin2hex(random_bytes(20));
    $q("INSERT INTO api_tokens SET token_user_id=$uid, token_hash='" . hash('sha256', $tok[$name]) . "', token_created_at=NOW(), token_last_used_at=NOW()");
}
$q("INSERT INTO user_client_permissions SET user_id=23, client_id=2");
$q("DELETE FROM user_settings");
foreach ([1, 20, 21, 22, 23] as $uid) { $q("INSERT INTO user_settings SET user_id=$uid, user_config_records_per_page=10"); }
// records. Dept A = client 1, Dept B = client 2; client 0 = global
foreach ([[101, 'srv-a', 1], [102, 'srv-b', 1], [103, 'ws-c', 1], [104, 'deptb-pc', 2]] as [$id, $n, $c]) { $q("INSERT INTO assets SET asset_id=$id, asset_name='$n', asset_type='Server', asset_make='x', asset_client_id=$c"); }
$q("INSERT INTO software SET software_id=201, software_name='erp', software_client_id=1");
$q("INSERT INTO services SET service_id=301, service_name='payroll', service_client_id=1");
$q("INSERT INTO documents SET document_id=401, document_name='runbook', document_content='x', document_content_raw='x', document_client_id=1");
$q("INSERT INTO vendors SET vendor_id=501, vendor_name='GlobalVendor', vendor_client_id=0");
$q("INSERT INTO vendors SET vendor_id=502, vendor_name='DeptBVendor', vendor_client_id=2");
$q("INSERT INTO vendors SET vendor_id=503, vendor_name='DeptAVendor', vendor_client_id=1");
$q("INSERT INTO kb_articles SET kb_article_id=601, kb_article_title='How to restart', kb_article_content='x', kb_article_content_raw='x', kb_article_client_id=0");
$q("INSERT INTO kb_articles SET kb_article_id=602, kb_article_title='DeptA only', kb_article_content='x', kb_article_content_raw='x', kb_article_client_id=1");
$q("INSERT INTO credentials SET credential_id=701, credential_name='srv-a admin', credential_client_id=1");

$admin = LinkActor::forUser($db, 1);
$writer = LinkActor::forUser($db, 20);
$reader = LinkActor::forUser($db, 21);
$clientOnly = LinkActor::forUser($db, 22);
$deptB = LinkActor::forUser($db, 23);
$svc = new LinkService($db);
$auditCount = fn (string $like) => (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = '" . $esc($like) . "'");

// ---------------------------------------------------------------- the registry
$ok(count(EntityTypes::all()) === 13 && !array_diff(['asset', 'software', 'vendor', 'document', 'kb_article', 'service', 'network', 'domain', 'certificate', 'credential', 'contact', 'location', 'ticket'], array_keys(EntityTypes::all())), 'the registry has the 13 record types');
$ok(EntityTypes::LINK_TYPES === ['depends_on', 'runs_on', 'supported_by', 'documented_by', 'related'], 'the five relationship types');
$cols = $rows("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'entity_links'");
$ok(count($cols) === 10, 'entity_links has the 10 specified columns');
$uniq = $rows("SELECT column_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'entity_links' AND index_name = 'uniq_entity_link' ORDER BY seq_in_index");
$ok(array_column($uniq, 'column_name') === ['src_type', 'src_id', 'dst_type', 'dst_id', 'link_type'], 'unique on the five identity columns');
$ok($one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'entity_links' AND index_name = 'idx_entity_links_dst'") == 2, 'an index on the destination');
foreach (EntityTypes::all() as $t => $spec) {
    $r = $db->query("SELECT {$spec['pk']}, {$spec['name']}, {$spec['client']}" . ($spec['archived'] ? ", {$spec['archived']}" : '') . " FROM {$spec['table']} LIMIT 1");
    $ok($r !== false, "registry: $t columns exist ({$spec['table']})");
}
foreach (EntityTypes::derivedMaps() as $m) {
    $r = $db->query("SELECT {$m['lcol']}, {$m['rcol']} FROM {$m['table']} LIMIT 1");
    $ok($r !== false, "derived map {$m['table']} columns exist");
}

// ---------------------------------------------------------------- create rules
$r = $svc->create($writer, 'asset', 101, 'document', 401, 'documented_by', 'see page 2');
$ok($r['ok'] === true && $r['link_id'] > 0, 'writer links an asset to a document in the same department');
$row = $rows('SELECT * FROM entity_links')[0];
$ok($row['client_id'] == 1 && $row['src_type'] === 'asset' && $row['dst_type'] === 'document' && $row['link_type'] === 'documented_by' && $row['note'] === 'see page 2' && $row['created_by'] == 20, 'the link row carries department, ends, type, note and author');
$ok($auditCount('entity_link.create') === 1 && str_contains((string) $one("SELECT summary FROM audit_events WHERE event_type='entity_link.create'"), 'srv-a'), 'an audit row is written for the create');
$ok($svc->create($writer, 'asset', 101, 'document', 401, 'documented_by')['error'] === 'exists', 'the same link twice is refused (409 in the API)');
$ok($svc->create($writer, 'asset', 101, 'document', 401, 'related')['ok'] === true, 'a different relationship type between the same records is a separate link');
$ok($svc->create($writer, 'asset', 101, 'asset', 101, 'related')['error'] === 'self_link', 'a record cannot link to itself');
$ok($svc->create($writer, 'asset', 101, 'asset', 104, 'related')['error'] === 'cross_client', 'two departments cannot be linked');
$ok($svc->create($writer, 'asset', 101, 'vendor', 502, 'supported_by')['error'] === 'cross_client', 'a vendor of another department cannot be linked');
$ok($svc->create($writer, 'asset', 101, 'vendor', 501, 'supported_by')['ok'] === true, 'a global vendor (department 0) links to a department asset');
$ok((int) $one("SELECT client_id FROM entity_links WHERE dst_type='vendor'") === 1, 'a link to a global record is scoped to the other end\'s department');
$ok($svc->create($writer, 'kb_article', 601, 'asset', 101, 'documented_by')['ok'] === true, 'a global KB article links to an asset (KB <-> asset)');
$ok($svc->create($writer, 'kb_article', 602, 'asset', 101, 'documented_by')['ok'] === true, 'a department KB article links to an asset of that department');
$ok($svc->create($writer, 'kb_article', 602, 'asset', 104, 'documented_by')['error'] === 'cross_client', 'a department KB article cannot link to another department\'s asset');
$ok($svc->create($writer, 'nonsense', 1, 'asset', 101, 'related')['error'] === 'bad_type' && $svc->create($writer, 'asset', 101, 'document', 401, 'bogus')['error'] === 'bad_link_type', 'unknown record or relationship type is refused');
$ok($svc->create($writer, 'asset', 101, 'document', 9999, 'related')['error'] === 'not_found', 'a missing record is refused');
$n0 = (int) $one('SELECT COUNT(*) FROM entity_links');
$ok($svc->create($reader, 'asset', 102, 'document', 401, 'related')['error'] === 'forbidden', 'a read-only role cannot create');
$ok($svc->create($clientOnly, 'contact', 1, 'asset', 101, 'related')['error'] !== 'exists' && (int) $one('SELECT COUNT(*) FROM entity_links') === $n0, 'a role without Tickets/assets cannot link assets');
$ok($svc->create($writer, 'asset', 101, 'credential', 701, 'related')['error'] === 'forbidden', 'a role without Credentials cannot link a credential');
$ok($svc->create($deptB, 'asset', 101, 'document', 401, 'related')['error'] === 'forbidden', 'a user limited to Dept B cannot link Dept A records');
$ok((int) $one('SELECT COUNT(*) FROM entity_links') === $n0, 'no forbidden attempt wrote a row');
$ok($svc->create($admin, 'asset', 102, 'credential', 701, 'related')['ok'] === true, 'an admin can link a credential');

// ---------------------------------------------------------------- delete
$lid = (int) $one("SELECT link_id FROM entity_links WHERE src_type='asset' AND src_id=102 AND dst_type='credential'");
$ok($svc->delete($reader, $lid)['error'] === 'forbidden', 'a read-only role cannot unlink');
$ok($svc->delete($deptB, $lid)['error'] === 'forbidden', 'a user outside the department cannot unlink');
$ok($svc->delete($admin, $lid)['ok'] === true && (int) $one("SELECT COUNT(*) FROM entity_links WHERE link_id = $lid") === 0, 'an authorised user unlinks');
$ok($auditCount('entity_link.delete') === 1, 'an audit row is written for the delete');
$ok($svc->delete($admin, $lid)['error'] === 'not_found', 'unlinking twice is a not-found');
$kbLink = (int) $one("SELECT link_id FROM entity_links WHERE src_type='kb_article' AND src_id=601");
$ok($svc->delete($writer, $kbLink)['ok'] === true, 'a KB editor may remove a KB <-> asset link');

// ---------------------------------------------------------------- derived rows (no copy)
$q("DELETE FROM entity_links");
$q("INSERT INTO asset_documents SET asset_id=101, document_id=401");
$q("INSERT INTO software_assets SET software_id=201, asset_id=101");
$q("INSERT INTO service_assets SET service_id=301, asset_id=101");
$q("INSERT INTO service_vendors SET service_id=301, vendor_id=503");
$ok($svc->create($writer, 'asset', 102, 'asset', 101, 'depends_on', '')['ok'], 'seed: srv-b depends on srv-a');
$stored = (int) $one('SELECT COUNT(*) FROM entity_links');
$v = $svc->view($writer, 'asset', 101);
$sources = array_map(fn ($e) => $e['source'], $v['outgoing']);
$ok(in_array('Asset documents', $sources, true) && ($v['outgoing'][array_search('Asset documents', $sources)]['derived'] ?? false) === true, 'outgoing: the asset_documents pair is shown as a derived row');
$inSources = array_map(fn ($e) => $e['source'], $v['incoming']);
$ok(in_array('Software licences', $inSources, true) && in_array('Service assets', $inSources, true) && in_array('Link', $inSources, true), 'referenced by: software licences, service assets and the real link');
$ok((int) $one('SELECT COUNT(*) FROM entity_links') === $stored, 'deriving wrote nothing: the legacy tables are not copied');
$d = $svc->view($writer, 'document', 401);
$ok(count(array_filter($d['incoming'], fn ($e) => $e['derived'] && $e['src_type'] === 'asset')) === 1, 'the document sees the same pair from the other side');
$ok(array_values(array_filter($v['outgoing'], fn ($e) => $e['derived']))[0]['link_id'] === 0, 'a derived row has no link id (cannot be unlinked here)');
$s = $svc->view($writer, 'service', 301);
$ok(in_array('Service vendors', array_map(fn ($e) => $e['source'], $s['outgoing']), true), 'service -> vendor pair is derived');
// a primary vendor is the same relationship via the column and via its mirror: one row
$q("UPDATE assets SET asset_vendor_id = 503 WHERE asset_id = 101");
(new VendorRoles($db))->mirrorPrimary('asset', 101);
$vv = $svc->view($writer, 'asset', 101);
$ok(count(array_filter($vv['outgoing'], fn ($e) => $e['dst_type'] === 'vendor' && $e['dst_id'] === 503)) === 1, 'the primary vendor appears once (column and mirror are merged)');

// ---------------------------------------------------------------- visibility filters in the view
$vr = $svc->view($reader, 'asset', 101);
$ok($vr['can_write'] === false && count($vr['outgoing']) > 0, 'a reader sees the card but cannot change it');
$q("INSERT INTO credentials SET credential_id=702, credential_name='hidden-cred', credential_client_id=1");
$q("INSERT INTO asset_credentials SET asset_id=101, credential_id=702");
$vw = $svc->view($writer, 'asset', 101);
$ok(!array_filter($vw['outgoing'], fn ($e) => $e['dst_type'] === 'credential'), 'a role without Credentials is not shown credential rows');
$va = $svc->view($admin, 'asset', 101);
$ok((bool) array_filter($va['outgoing'], fn ($e) => $e['dst_type'] === 'credential'), '... an admin is');
$q("INSERT INTO entity_links SET client_id=2, src_type='asset', src_id=104, dst_type='asset', dst_id=101, link_type='related', created_by=1");   // forced data: a cross-department row
$vw = $svc->view($writer, 'asset', 101);
$ok(true, 'view tolerates a stray cross-department row');
$vb = $svc->view($deptB, 'asset', 101);
$ok($vb['outgoing'] === [] && $vb['incoming'] === [], 'a user limited to Dept B sees nothing of a Dept A record');
$q("DELETE FROM entity_links WHERE client_id=2");

// ---------------------------------------------------------------- impact: transitive, depth 3, cycle safe
// chain: asset 101 <- (runs_on, derived) software 201 <- (depends_on) service 301 <- (depends_on) asset 103 <- (depends_on) asset 102
$q("DELETE FROM entity_links");
$q("DELETE FROM asset_documents"); $q("DELETE FROM service_assets"); $q("DELETE FROM service_vendors"); $q("DELETE FROM asset_credentials"); $q("UPDATE assets SET asset_vendor_id = 0"); $q("DELETE FROM asset_vendors");
$ok($svc->create($writer, 'service', 301, 'software', 201, 'depends_on')['ok'], 'chain: service depends on software');
$ok($svc->create($writer, 'asset', 103, 'service', 301, 'depends_on')['ok'], 'chain: workstation depends on the service');
$ok($svc->create($writer, 'asset', 102, 'asset', 103, 'depends_on')['ok'], 'chain: srv-b depends on the workstation');
$imp = $svc->impact($writer, 'asset', 101);
$byKey = [];
foreach ($imp as $n) { $byKey[$n['type'] . ':' . $n['id']] = $n['depth']; }
$ok($byKey === ['software:201' => 1, 'service:301' => 2, 'asset:103' => 3], 'impact of an asset: software (1), service (2), workstation (3); the fourth level is cut off: ' . json_encode($byKey));
$ok(array_column($svc->impact($writer, 'asset', 101, 2), 'id') === [201, 301], 'depth 2 stops at two levels');
$ok(count($svc->impact($writer, 'asset', 101, 99)) === 3, 'depth is capped at 3 whatever is asked');
// a documented_by / related link is not an impact edge
$ok($svc->create($writer, 'document', 401, 'asset', 101, 'documented_by')['ok'] && $svc->create($writer, 'asset', 104 - 1, 'asset', 101, 'related')['ok'], 'seed: documented_by and related links to the asset');
$ok(!in_array('document:401', array_map(fn ($n) => $n['type'] . ':' . $n['id'], $svc->impact($writer, 'asset', 101)), true), 'a document describing the asset is referenced, but not an impact');
// cycle: make the asset depend on the far end of its own chain
$ok($svc->create($writer, 'asset', 101, 'asset', 102, 'depends_on')['ok'], 'cycle: srv-a depends on srv-b, which (through the chain) depends on srv-a');
$t0 = microtime(true);
$imp = $svc->impact($writer, 'asset', 101);
$keys = array_map(fn ($n) => $n['type'] . ':' . $n['id'], $imp);
$ok(count($keys) === count(array_unique($keys)) && !in_array('asset:101', $keys, true) && microtime(true) - $t0 < 2, 'a cycle ends the walk: each record once, never the record itself, no loop');
$ok(array_column($svc->impact($writer, 'asset', 102), 'depth', 'id')[101] ?? 0 >= 1, 'the cycle is walkable from any member');
// a self-referencing pair in raw data (forced) must not loop either
$q("INSERT INTO entity_links SET client_id=1, src_type='asset', src_id=103, dst_type='asset', dst_id=103, link_type='depends_on', created_by=1");
$ok(count($svc->impact($writer, 'asset', 103)) >= 0, 'a raw self-link does not loop the walk');
$q("DELETE FROM entity_links WHERE src_id=103 AND dst_id=103");
// hidden records are neither listed nor walked through
$q("INSERT INTO entity_links SET client_id=1, src_type='credential', src_id=701, dst_type='asset', dst_id=101, link_type='depends_on', created_by=1");
$q("INSERT INTO entity_links SET client_id=1, src_type='ticket', src_id=1, dst_type='credential', dst_id=701, link_type='depends_on', created_by=1");
$q("INSERT INTO tickets SET ticket_id=1, ticket_prefix='T', ticket_number=1, ticket_subject='x', ticket_details='x', ticket_client_id=1");
$visW = array_map(fn ($n) => $n['type'] . ':' . $n['id'], $svc->impact($writer, 'asset', 101));
$visA = array_map(fn ($n) => $n['type'] . ':' . $n['id'], $svc->impact($admin, 'asset', 101));
$ok(!in_array('credential:701', $visW, true) && !in_array('ticket:1', $visW, true) && in_array('credential:701', $visA, true) && in_array('ticket:1', $visA, true), 'a record the role cannot see is not listed, nor is anything reachable only through it');
$ok($svc->impact($deptB, 'asset', 101) === [], 'a user outside the department gets no impact rows');

// ---------------------------------------------------------------- purge on delete
$n = $svc->purgeFor('asset', 103);
$ok($n >= 2 && (int) $one("SELECT COUNT(*) FROM entity_links WHERE (src_type='asset' AND src_id=103) OR (dst_type='asset' AND dst_id=103)") === 0, 'purgeFor removes every link that mentions a deleted record');

// ---------------------------------------------------------------- search (the picker)
$res = $svc->search($writer, 'asset', 'srv', 1);
$ok(array_column($res, 'name') === ['srv-a', 'srv-b'], 'search: assets of the department by name');
$ok(!in_array('deptb-pc', array_column($svc->search($writer, 'asset', '', 1), 'name'), true), 'search: another department is not offered');
$ok(array_column($svc->search($writer, 'vendor', '', 1), 'name') === ['DeptAVendor', 'GlobalVendor'], 'search: department vendors plus global ones');
$ok($svc->search($writer, 'credential', '', 1) === [] && $svc->search($deptB, 'asset', '', 1) === [], 'search: nothing for a type the role cannot read or a department it cannot access');
$ok(count($svc->search($writer, 'asset', "'; DROP TABLE assets;--", 1)) === 0 && (int) $one('SELECT COUNT(*) FROM assets') === 4, 'search: hostile text is only a search term');
$ok(count($svc->search($writer, 'asset', '%', 1)) === 0, 'search: LIKE wildcards are literal');
$glob = $svc->search($writer, 'asset', '', 0);
$ok(count($glob) === 4 && array_column($glob, 'client_name', 'name')['deptb-pc'] === 'Dept B', 'search from a global source (e.g. a global KB article): assets of every department, each with its department name');
$ok(array_column($svc->search($deptB, 'asset', '', 0), 'name') === ['deptb-pc'], 'search from a global source: still only the departments the user may access');
$ok($svc->create($writer, 'kb_article', 601, 'asset', 104, 'documented_by')['ok'] && (int) $one("SELECT client_id FROM entity_links WHERE src_type='kb_article' AND src_id=601 AND dst_id=104") === 2, 'a global KB article links to a Dept B asset; the link belongs to Dept B');
$q("DELETE FROM entity_links WHERE src_type='kb_article' AND src_id=601 AND dst_id=104");

// ---------------------------------------------------------------- vendors with roles
$vr = new VendorRoles($db);
$q("UPDATE assets SET asset_vendor_id = 503 WHERE asset_id = 102");
$vr->mirrorPrimary('asset', 102);
$ok((int) $one("SELECT COUNT(*) FROM asset_vendors WHERE asset_id=102 AND vendor_id=503 AND vendor_role='support'") === 1, 'the primary vendor is mirrored as role support');
$ok($vr->add($writer, 'asset', 102, 501, 'reseller')['ok'] && $vr->add($writer, 'asset', 102, 501, 'manufacturer')['ok'], 'extra vendors (and several roles of one vendor) can be added');
$ok($vr->add($writer, 'asset', 102, 501, 'reseller')['error'] === 'exists', 'the same vendor + role twice is refused');
$ok($vr->add($writer, 'asset', 102, 502, 'support')['error'] === 'cross_client', 'a vendor of another department is refused');
$ok($vr->add($reader, 'asset', 102, 501, 'support')['error'] === 'forbidden' && $vr->add($writer, 'asset', 102, 501, 'wizard')['error'] === 'bad_type', 'a reader cannot add; an unknown role is refused');
$list = $vr->forRecord('asset', 102);
$ok(count($list) === 2 && $list[0]['primary'] === true && $list[0]['vendor_id'] === 503 && $list[1]['roles'] === ['reseller', 'manufacturer'], 'forRecord: the primary first, then the others with their roles');
$ok($vr->remove($writer, 'asset', 102, 503, 'support')['error'] === 'primary', 'the primary vendor is changed on the record, not removed here');
$ok($vr->remove($writer, 'asset', 102, 501, 'reseller')['ok'] && count($vr->forRecord('asset', 102)[1]['roles']) === 1, 'a role can be removed');
$q("UPDATE assets SET asset_vendor_id = 501 WHERE asset_id = 102");
$vr->mirrorPrimary('asset', 102, 503);
$ok((int) $one("SELECT COUNT(*) FROM asset_vendors WHERE asset_id=102 AND vendor_id=503") === 0 && (int) $one("SELECT COUNT(*) FROM asset_vendors WHERE asset_id=102 AND vendor_id=501 AND vendor_role='support'") === 1, 'changing the primary moves the mirrored support row');
$q("UPDATE software SET software_vendor_id = 503 WHERE software_id = 201");
$vr->mirrorPrimary('software', 201);
$ok($vr->add($writer, 'software', 201, 501, 'manufacturer')['ok'] && count($vr->forRecord('software', 201)) === 2, 'software gets several vendors too');
$ok($auditCount('vendor_role.add') >= 3 && $auditCount('vendor_role.remove') >= 1, 'vendor role changes are audited');
$q("UPDATE assets SET asset_vendor_id = 503 WHERE asset_id = 103");   // set without any mirror (an import, an old page)
$ok(count($vr->forRecord('asset', 103)) === 1 && $vr->forRecord('asset', 103)[0]['primary'], 'a primary set by another path is still shown');

// ---------------------------------------------------------------- API over HTTP
$q("DELETE FROM entity_links"); $q("DELETE FROM asset_vendors");
$q("UPDATE settings SET config_module_enable_kb = 1 WHERE company_id = 1");
$get = fn (string $path, ?string $t) => http('GET', $path, $t);
[$c] = $get('/api/v1/relationships?type=asset&id=101', null);
$ok($c === 401, 'API: no token -> 401');
[$c, , $j] = http('POST', '/api/v1/relationships', $tok['writer'], ['src_type' => 'asset', 'src_id' => 101, 'dst_type' => 'document', 'dst_id' => 401, 'link_type' => 'documented_by', 'note' => 'api']);
$ok($c === 201 && ($j['link_id'] ?? 0) > 0, 'API: POST creates a link (201)');
$apiLink = (int) ($j['link_id'] ?? 0);
[$c, , $j] = http('POST', '/api/v1/relationships', $tok['writer'], ['src_type' => 'asset', 'src_id' => 101, 'dst_type' => 'document', 'dst_id' => 401, 'link_type' => 'documented_by']);
$ok($c === 409, 'API: a duplicate is 409');
[$c] = http('POST', '/api/v1/relationships', $tok['writer'], ['src_type' => 'asset', 'src_id' => 101, 'dst_type' => 'asset', 'dst_id' => 104, 'link_type' => 'related']);
$ok($c === 422, 'API: two departments is 422');
[$c] = http('POST', '/api/v1/relationships', $tok['reader'], ['src_type' => 'asset', 'src_id' => 102, 'dst_type' => 'document', 'dst_id' => 401, 'link_type' => 'related']);
$ok($c === 403, 'API: a read-only role is 403');
[$c] = http('POST', '/api/v1/relationships', $tok['writer'], ['src_type' => 'bogus', 'src_id' => 1, 'dst_type' => 'asset', 'dst_id' => 101]);
$ok($c === 400, 'API: an unknown record type is 400');
[$c] = http('POST', '/api/v1/relationships', $tok['writer'], ['src_type' => 'asset', 'src_id' => 101, 'dst_type' => 'document', 'dst_id' => 9999, 'link_type' => 'related']);
$ok($c === 404, 'API: a missing record is 404');
$q("INSERT INTO asset_documents SET asset_id=102, document_id=401");
[$c, , $j] = $get('/api/v1/relationships?type=asset&id=101', $tok['writer']);
$ok($c === 200 && ($j['record']['name'] ?? '') === 'srv-a' && count($j['outgoing'] ?? []) === 1 && ($j['outgoing'][0]['to']['type'] ?? '') === 'document' && ($j['outgoing'][0]['link_id'] ?? 0) === $apiLink && $j['outgoing'][0]['derived'] === false, 'API: GET lists the link with its ends');
[$c, , $j] = $get('/api/v1/relationships?type=document&id=401', $tok['writer']);
$ok($c === 200 && count($j['referenced_by'] ?? []) === 2 && count(array_filter($j['referenced_by'], fn ($e) => $e['derived'] === true && $e['link_id'] === null)) === 1, 'API: referenced_by includes the derived row (link_id null)');
$ok(isset($j['impact']) && is_array($j['impact']), 'API: the impact list is present');
[$c] = $get('/api/v1/relationships?type=asset&id=101', $tok['deptb']);
$ok($c === 404, 'API: a record in a department the user cannot reach is 404 (not 403)');
[$c] = $get('/api/v1/relationships?type=asset', $tok['writer']);
$ok($c === 400, 'API: GET without an id is 400');
[$c] = $get('/api/v1/relationships?type=credential&id=701', $tok['writer']);
$ok($c === 404, 'API: a type the role cannot read is 404');
[$c] = http('DELETE', '/api/v1/relationships/' . $apiLink, $tok['reader']);
$ok($c === 403, 'API: a reader cannot delete');
[$c] = http('DELETE', '/api/v1/relationships/' . $apiLink, $tok['writer']);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM entity_links WHERE link_id = $apiLink") === 0, 'API: DELETE removes the link');
[$c] = http('DELETE', '/api/v1/relationships/' . $apiLink, $tok['writer']);
$ok($c === 404, 'API: deleting again is 404');
[$c] = http('PUT', '/api/v1/relationships', $tok['writer'], []);
$ok($c === 405, 'API: other verbs are 405');
$ok($auditCount('entity_link.create') >= 2 && $auditCount('entity_link.delete') >= 2, 'API writes are audited');
$spec = file_get_contents($root . '/api/v1/openapi.yaml');
$ok(str_contains($spec, "\n  /relationships:") && str_contains($spec, "\n  /relationships/{id}:"), 'openapi.yaml documents /relationships');

// ---------------------------------------------------------------- web: the card, the modal, the picker, the post handler
$sdir = sys_get_temp_dir() . '/entity_links_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir"]);
$webLog0 = (int) @filesize($web['log']);   // the log name is per port: only what this run wrote counts
$wb = "http://127.0.0.1:{$web['port']}";
$S = [];
foreach (['admin' => 1, 'writer' => 20, 'reader' => 21, 'deptb' => 23] as $n => $uid) { $S[$n] = ea_forge_session($sdir, $uid); }
$wr = function (string $base, string $method, string $path, string $sid, array $post = [], array $headers = []) { return \web($base, $method, $path, $sid, $post, array_merge(['User-Agent: entity-links-test'], $headers)); };
$q("DELETE FROM entity_links");
$svc->create($writer, 'asset', 101, 'document', 401, 'documented_by', 'runbook link');
$svc->create($writer, 'service', 301, 'asset', 101, 'depends_on');
[$c, $body] = $wr($wb, 'GET', '/agent/asset_details.php?client_id=1&asset_id=101', $S['writer']);
$ok($c === 200 && str_contains($body, 'id="relationships-card"') && str_contains($body, 'data-rel-type="asset"') && str_contains($body, 'runbook link') && str_contains($body, 'Referenced by') && str_contains($body, 'Impact: what depends on this'), 'asset page: the Relationships card shows links, referenced by and impact');
$ok(str_contains($body, 'data-rel-open-link') && str_contains($body, 'modals/relationships/link_add.php?type=asset') && str_contains($body, 'name="unlink_entity"'), 'asset page (writer): Link item button and unlink buttons');
$ok(str_contains($body, 'payroll') && str_contains($body, 'data-rel-depth="1"'), 'asset page: the service that depends on the asset is in the impact list');
$ok(!str_contains($body, 'Warning:') && !str_contains($body, 'Fatal error'), 'asset page: no PHP warnings');
[$c, $body] = $wr($wb, 'GET', '/agent/asset_details.php?client_id=1&asset_id=101', $S['reader']);
$ok($c === 200 && str_contains($body, 'id="relationships-card"') && !str_contains($body, 'data-rel-open-link') && !str_contains($body, 'name="unlink_entity"') && !str_contains($body, 'name="add_vendor_role"'), 'asset page (reader): the card is read-only');
[$c, $body] = $wr($wb, 'GET', '/agent/document_details.php?client_id=1&document_id=401', $S['writer']);
$ok($c === 200 && str_contains($body, 'id="relationships-card"') && str_contains($body, 'srv-a'), 'document page: the card shows the asset that points at it');
[$c, $body] = $wr($wb, 'GET', '/agent/kb_article.php?id=601', $S['admin']);
$ok($c === 200 && str_contains($body, 'id="relationships-card"') && str_contains($body, 'data-rel-type="kb_article"'), 'KB article page: the Relationships card');
[$c, $body] = $wr($wb, 'GET', '/agent/modals/vendor/vendor_details.php?id=503', $S['admin']);
$j = json_decode($body, true);
$ok($c === 200 && str_contains((string) ($j['content'] ?? ''), 'data-rel-type=\"vendor\"') || str_contains((string) ($j['content'] ?? ''), 'data-rel-type="vendor"'), 'vendor details pop-up: the Relationships card');
[$c, $body] = $wr($wb, 'GET', '/agent/software.php?client_id=1', $S['admin']);
$ok($c === 200 && str_contains($body, 'modals/relationships/relationships.php?type=software&amp;id=201') || str_contains($body, 'modals/relationships/relationships.php?type=software&id=201'), 'software list: a Relationships action on each row');
[$c, $body] = $wr($wb, 'GET', '/agent/modals/relationships/relationships.php?type=software&id=201', $S['admin']);
$j = json_decode($body, true);
$ok($c === 200 && str_contains((string) ($j['content'] ?? ''), 'relationships-card') && str_contains((string) $j['content'], 'data-rel-form'), 'Relationships pop-up for a record type without a page: the card with the inline Link item form');
[$c, $body] = $wr($wb, 'GET', '/agent/modals/relationships/link_add.php?type=asset&id=101', $S['writer']);
$j = json_decode($body, true);
$ok($c === 200 && str_contains((string) ($j['content'] ?? ''), 'name="dst_type"') && str_contains($j['content'], 'name="link_type"') && str_contains($j['content'], 'name="note"') && str_contains($j['content'], 'data-rel-search'), 'Link item pop-up: type picker, search, relationship type and note');
$ok(!str_contains($j['content'], 'value="credential"'), 'Link item pop-up: no type the role cannot read');
[$c] = $wr($wb, 'GET', '/agent/modals/relationships/link_add.php?type=asset&id=101', $S['reader']);
$ok($c === 403, 'Link item pop-up: a reader is refused');
[$c] = $wr($wb, 'GET', '/agent/modals/relationships/link_add.php?type=asset&id=104', $S['deptb']);
$ok($c === 200 || $c === 403, 'Link item pop-up: department user reaches only their own department');
[$c] = $wr($wb, 'GET', '/agent/modals/relationships/link_add.php?type=asset&id=101', $S['deptb']);
$ok($c === 403, 'Link item pop-up: a Dept B user is refused a Dept A record');
[$c, $body] = $wr($wb, 'GET', '/agent/ajax.php?relationship_search=1&type=asset&client_id=1&q=srv', $S['writer']);
$j = json_decode($body, true);
$ok($c === 200 && array_column($j['results'] ?? [], 'name') === ['srv-a', 'srv-b'], 'picker: department assets matching the search');
[$c, $body] = $wr($wb, 'GET', '/agent/ajax.php?relationship_search=1&type=asset&client_id=1&q=srv&exclude_type=asset&exclude_id=101', $S['writer']);
$ok(array_column(json_decode($body, true)['results'] ?? [], 'name') === ['srv-b'], 'picker: the record being linked is left out');
[$c, $body] = $wr($wb, 'GET', '/agent/ajax.php?relationship_search=1&type=asset&client_id=1&q=srv', $S['deptb']);
$ok(($j2 = json_decode($body, true)) && ($j2['results'] ?? null) === [], 'picker: a Dept B user gets nothing for Dept A');
// post handler
$n0 = (int) $one('SELECT COUNT(*) FROM entity_links');
[$c] = $wr($wb, 'POST', '/agent/post.php', $S['writer'], ['csrf_token' => 'wrong', 'link_entities' => 1, 'src_type' => 'asset', 'src_id' => 101, 'dst_type' => 'asset', 'dst_id' => 102, 'link_type' => 'related']);
$ok((int) $one('SELECT COUNT(*) FROM entity_links') === $n0, 'post handler: a wrong CSRF token creates nothing');
[$c] = $wr($wb, 'POST', '/agent/post.php', $S['writer'], ['csrf_token' => 'csrftok1', 'link_entities' => 1, 'src_type' => 'asset', 'src_id' => 101, 'dst_type' => 'asset', 'dst_id' => 102, 'link_type' => 'related', 'note' => '<b>x</b>'], ['Referer: ' . $wb . '/agent/asset_details.php']);
$ok((int) $one('SELECT COUNT(*) FROM entity_links') === $n0 + 1 && $one("SELECT note FROM entity_links WHERE dst_id=102 AND dst_type='asset'") === 'x', 'post handler: a valid form creates the link (note stripped of markup)');
[$c] = $wr($wb, 'POST', '/agent/post.php', $S['reader'], ['csrf_token' => 'csrftok1', 'link_entities' => 1, 'src_type' => 'asset', 'src_id' => 101, 'dst_type' => 'asset', 'dst_id' => 103, 'link_type' => 'related'], ['Referer: ' . $wb . '/agent/asset_details.php']);
$ok((int) $one("SELECT COUNT(*) FROM entity_links WHERE dst_id=103 AND dst_type='asset' AND src_id=101") === 0, 'post handler: a reader creates nothing');
$lid = (int) $one("SELECT link_id FROM entity_links WHERE dst_id=102 AND dst_type='asset'");
[$c] = $wr($wb, 'POST', '/agent/post.php', $S['writer'], ['csrf_token' => 'csrftok1', 'unlink_entity' => 1, 'link_id' => $lid], ['Referer: ' . $wb . '/agent/asset_details.php']);
$ok((int) $one("SELECT COUNT(*) FROM entity_links WHERE link_id = $lid") === 0, 'post handler: unlink removes the link');
[$c] = $wr($wb, 'POST', '/agent/post.php', $S['writer'], ['csrf_token' => 'csrftok1', 'add_vendor_role' => 1, 'vendor_kind' => 'asset', 'record_id' => 101, 'vendor_id' => 501, 'vendor_role' => 'reseller'], ['Referer: ' . $wb . '/agent/asset_details.php']);
$ok((int) $one("SELECT COUNT(*) FROM asset_vendors WHERE asset_id=101 AND vendor_id=501 AND vendor_role='reseller'") === 1, 'post handler: adds a vendor with a role');
[$c, $body] = $wr($wb, 'GET', '/agent/asset_details.php?client_id=1&asset_id=101', $S['writer']);
$ok(str_contains($body, 'GlobalVendor') && str_contains($body, 'reseller'), 'asset page: the vendor and its role are shown');
// hostile record names are escaped in the card
$q("UPDATE documents SET document_name = '<script>alert(1)</script>' WHERE document_id = 401");
[$c, $body] = $wr($wb, 'GET', '/agent/asset_details.php?client_id=1&asset_id=101', $S['admin']);
$ok(!str_contains($body, '<script>alert(1)</script>') && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'the card escapes hostile record names');
$ok(!preg_match('/PHP (Warning|Fatal)/', (string) substr((string) @file_get_contents($web['log']), $webLog0)), 'server log has no PHP warnings or fatals');
