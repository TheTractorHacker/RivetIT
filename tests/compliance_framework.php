<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Administration > Compliance > Framework over HTTP (real pages, forged sessions, SCRATCH database) and the wiring around it
 * (RivetCore 1.0.0-rc.44 customizable frameworks, compliance edition prompt phase 4):
 *   - permissions: a non-administrator gets nothing (page and POSTs), a POST without the CSRF token changes nothing
 *   - select / copy / edit (new version, stale edit refused) / archive / delete rules / export (headers) / import (hostile files change nothing)
 *   - a profile named <img onerror> is escaped on the page
 *   - the retention floor of the selected framework: refused in the RMM settings, raised in the audit-trail cleanup, audited
 *   - ProfileChecks join the assessment only while a profile is selected; the assessment records the profile name and version
 *   - a restore test is recorded and judged by restore_test_recency
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/compliance_framework.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/event_bus.php";
require_once "$root/includes/rmm_bootstrap.php";

use ITFlow\Compliance\ComplianceService;

$q("DELETE FROM users WHERE user_id IN (731,732)");
$q("DELETE FROM user_roles WHERE role_id IN (97,98)");
$q("INSERT INTO user_roles SET role_id=97, role_name='Framework Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO user_roles SET role_id=98, role_name='Framework Tech', role_is_admin=0, role_type=1");
foreach ([731 => [97, 'fwadmin'], 732 => [98, 'fwtech']] as $id => [$role, $tag]) {
    $q("INSERT INTO users SET user_id=$id, user_name='Framework $tag', user_email='fw-$tag@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role");
    $q("INSERT INTO user_settings SET user_id=$id ON DUPLICATE KEY UPDATE user_id=user_id");
}
foreach (['compliance_profile_selection_log', 'compliance_profile_selection', 'compliance_profile_requirements', 'compliance_profile_versions', 'compliance_profiles', 'compliance_restore_tests'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM audit_events WHERE event_type LIKE 'compliance.%'");

$sdir = sys_get_temp_dir() . '/fw_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$csrf = 'csrftok12345678';
$sess = fn (int $uid) => sec_forge_session($sdir, ['logged' => true, 'user_id' => $uid, 'csrf_token' => $csrf, 'sec_created' => time(), 'sec_last' => time()]);
$admin = $sess(731); $tech = $sess(732);
$ref = ['Referer: ' . $base . '/admin/compliance_framework.php'];
$post = fn (string $sid, array $f, bool $withCsrf = true) => sec_web($base, 'POST', '/admin/post.php', $sid, $withCsrf ? $f + ['csrf_token' => $csrf] : $f, [], $ref);
$store = fn () => ComplianceService::profiles($db);
$sel = fn () => $store()->selectedKey();

// ------------------------------------------------------------------ permissions and CSRF
[$c, $body] = sec_web($base, 'GET', '/admin/compliance_framework.php', $admin);
$ok($c === 200 && str_contains($body, 'Compliance framework') && str_contains($body, 'Selected framework'), 'administrator: the page renders');
[$c, $body] = sec_web($base, 'GET', '/admin/compliance_framework.php', $tech);
$ok($c === 403 || !str_contains($body, 'Selected framework'), 'non-administrator: the page is refused (' . $c . ')');
$post($tech, ['select_compliance_profile' => 1, 'profile_key' => 'hipaa']);
$ok($sel() === null, 'non-administrator: a select POST changes nothing');
$post($admin, ['select_compliance_profile' => 1, 'profile_key' => 'hipaa'], false);
$ok($sel() === null, 'a select POST without the CSRF token changes nothing');
$post($tech, ['create_compliance_profile' => 1, 'profile_key' => 'tech_made', 'label' => 'x', 'req_type' => ['audit_events']]);
$ok($store()->get('tech_made') === null, 'non-administrator: cannot create a framework');
[$c] = sec_web($base, 'GET', '/admin/post.php?export_compliance_profile=hipaa&csrf_token=' . $csrf, $tech, [], [], $ref);
$ok($c !== 200, 'non-administrator: cannot export');

// ------------------------------------------------------------------ copy, edit, stale edit
$post($admin, ['copy_compliance_profile' => 1, 'source_key' => 'hipaa', 'profile_key' => 'contract_x', 'label' => 'Contract X']);
$p = $store()->get('contract_x');
$ok($p !== null && !$p->builtin && $p->version === 1, 'copy: a custom framework with version 1');
$req = ['req_type' => ['retention_min', 'backup_rpo_max', 'backup_encrypted', 'restore_test_max_age', ''], 'req_class' => ['audit_events', '', '', '', ''], 'req_value' => [2555, 24, 1, 90, ''], 'req_note' => ['', '', '', '', '']];
$post($admin, ['update_compliance_profile' => 1, 'profile_key' => 'contract_x', 'label' => 'Contract X', 'description' => 'test', 'expected_version' => 1, 'change_note' => 'first'] + $req);
$p = $store()->get('contract_x');
$ok($p->version === 2 && count($p->requirements) === 4, 'edit: a new version with four requirements');
$post($admin, ['update_compliance_profile' => 1, 'profile_key' => 'contract_x', 'label' => 'Contract X', 'description' => 'stale', 'expected_version' => 1] + $req);
$ok($store()->get('contract_x')->version === 2 && $store()->get('contract_x')->description === 'test', 'a stale edit (expected version 1, current 2) is refused');
[$c, $body] = sec_web($base, 'GET', '/admin/compliance_framework.php?edit=contract_x', $admin);
$ok(str_contains($body, 'Version history') && str_contains($body, 'Restore this version'), 'the editor shows the version history');
$post($admin, ['update_compliance_profile' => 1, 'profile_key' => 'contract_x', 'label' => 'Contract X', 'description' => 'x', 'expected_version' => 2, 'req_type' => ['retention_min'], 'req_class' => ['audit_events'], 'req_value' => [999999999], 'req_note' => ['']]);
$ok($store()->get('contract_x')->version === 2, 'an out-of-range value is refused and changes nothing');
$post($admin, ['revert_compliance_profile' => 1, 'profile_key' => 'contract_x', 'version' => 1]);
$ok($store()->get('contract_x')->version === 3, 'restore this version: stored as a new version (3)');
$post($admin, ['update_compliance_profile' => 1, 'profile_key' => 'contract_x', 'label' => 'Contract X', 'description' => 'test', 'expected_version' => 3, 'change_note' => 'again'] + $req);

// ------------------------------------------------------------------ escaping
$post($admin, ['copy_compliance_profile' => 1, 'source_key' => 'hipaa', 'profile_key' => 'xss_test', 'label' => '<img src=x onerror=alert(1)>']);
[$c, $body] = sec_web($base, 'GET', '/admin/compliance_framework.php', $admin);
$ok(str_contains($body, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($body, '<img src=x onerror'), 'a hostile name is escaped on the page');

// ------------------------------------------------------------------ export and import
[$c, $body, $hdr] = sec_web($base, 'GET', '/admin/post.php?export_compliance_profile=contract_x&csrf_token=' . $csrf, $admin, [], [], $ref);
$ok($c === 200 && stripos($hdr, 'application/json') !== false && stripos($hdr, 'attachment') !== false && stripos($hdr, 'nosniff') !== false, 'export: JSON, attachment, nosniff');
$exported = $body;
$ok(json_decode($exported, true) !== null && str_contains($exported, 'contract_x'), 'export: valid JSON naming the framework');
$import = function (string $sid, string $json, array $extra = []) use ($base, $csrf, $ref) {
    $tmp = tempnam(sys_get_temp_dir(), 'imp'); file_put_contents($tmp, $json);
    $ch = curl_init($base . '/admin/post.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_HTTPHEADER => $ref,
        CURLOPT_POSTFIELDS => ['import_compliance_profile' => 1, 'csrf_token' => $csrf, 'profile_file' => new CURLFile($tmp, 'application/json', 'f.json')] + $extra]);
    curl_exec($ch); curl_close($ch); @unlink($tmp);
};
$before = count($store()->list(true));
$import($admin, $exported, ['as_key' => 'contract_x_copy']);
$ok($store()->get('contract_x_copy') !== null, 'import: the exported file comes back under a new key');
$n = count($store()->list(true));
foreach ([
    'not json at all', '{"schema_version": 99}', str_replace('"retention_min"', '"drop_tables"', $exported), json_encode(['x' => str_repeat('a', 70000)]),
    str_replace('contract_x', 'hipaa', $exported), preg_replace('/"requirements"\s*:\s*\[/', '"password":"s3cret","requirements":[', $exported),
] as $i => $bad) {
    $import($admin, (string) $bad);
    $ok(count($store()->list(true)) === $n, "import: hostile file #$i changes nothing");
}

// ------------------------------------------------------------------ select: checks and floors
[$c0] = [0];
$assess0 = ComplianceService::assess($db);
$ids0 = array_column($assess0->automatic, 'id');
$ok(!in_array('backup_coverage', $ids0, true), 'no framework selected: the profile checks are not in the assessment');
$post($admin, ['select_compliance_profile' => 1, 'profile_key' => 'contract_x']);
$ok($sel() === 'contract_x', 'select: contract_x is selected');
$assess1 = ComplianceService::assess($db);
$ids1 = array_column($assess1->automatic, 'id');
$ok(in_array('backup_recency', $ids1, true) && in_array('backup_encrypted', $ids1, true) && in_array('audit_retention', $ids1, true) && in_array('restore_test_recency', $ids1, true), 'selected: its requirement checks join the assessment (' . implode(',', array_intersect($ids1, ['backup_recency', 'backup_encrypted', 'audit_retention', 'restore_test_recency'])) . ')');
$meta = $assess1->meta;
$ok(str_contains(json_encode($meta), 'Contract X'), 'the assessment records the framework name');
[$c, $body] = sec_web($base, 'GET', '/admin/compliance_framework.php', $admin);
$ok(str_contains($body, 'Requirements and results') && str_contains($body, 'Retention minimums enforced now') && str_contains($body, '2555'), 'the page shows the requirements, results and the enforced minimum');

$floors = \RivetCore\Compliance\Profile\RetentionFloors::fromStore($store());
$ok($floors->floorDays('audit_events') === 2555 && $floors->effectiveDays('audit_events', 365) === 2555 && $floors->effectiveDays('audit_events', 4000) === 4000, 'RetentionFloors: raises 365 to 2555 and never lowers 4000');

// the RMM settings refuse a retention below the floor of the selected framework, and name it
$post($admin, ['copy_compliance_profile' => 1, 'source_key' => 'hipaa', 'profile_key' => 'floor_test', 'label' => 'Floor test']);
$post($admin, ['update_compliance_profile' => 1, 'profile_key' => 'floor_test', 'label' => 'Floor test', 'description' => '', 'expected_version' => 1,
    'req_type' => ['retention_min'], 'req_class' => ['check_history'], 'req_value' => [200], 'req_note' => ['']]);
$post($admin, ['select_compliance_profile' => 1, 'profile_key' => 'floor_test']);
$rmmSettings = rivetRmmModule($db)->settings();
$r = $rmmSettings->update(['limits_json' => json_encode(['check_history_days' => 30])]);
$ok(!empty($r['errors'] ?? $r) && str_contains(json_encode($r), 'Floor test') && str_contains(json_encode($r), '200'), 'RMM settings: check history 30 days is refused and the message names the framework and 200 days');
$r = $rmmSettings->update(['limits_json' => json_encode(['check_history_days' => 250])]);
$ok(empty($r['errors'] ?? []), 'RMM settings: 250 days (above the floor) is accepted');
$post($admin, ['select_compliance_profile' => 1, 'profile_key' => 'contract_x']);

// ------------------------------------------------------------------ restore test
// The form takes the company time zone (docs/TIMESTAMP_DISPLAY.md), so the posted times are written in it.
// A fixed company zone (not UTC), so the conversion is really exercised and the test does not depend on the server's default zone.
$q("INSERT INTO settings (company_id, config_timezone) VALUES (1, 'America/Chicago') ON DUPLICATE KEY UPDATE config_timezone = 'America/Chicago'");
$inCompanyZone = fn (int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('America/Chicago'))->format('Y-m-d H:i');
$post($admin, ['record_restore_test' => 1, 'tested_at' => $inCompanyZone(time() + 86400 * 2), 'who' => 'T', 'result' => 'pass', 'stores' => ['control_db']]);
$ok((int) $one('SELECT COUNT(*) FROM compliance_restore_tests') === 0, 'a restore test dated in the future is refused');
$post($admin, ['record_restore_test' => 1, 'tested_at' => $inCompanyZone(time() - 3600), 'who' => 'Test Admin', 'result' => 'pass', 'stores' => ['control_db']]);
$ok((int) $one('SELECT COUNT(*) FROM compliance_restore_tests') === 1, 'a restore test is recorded');
$post($tech, ['record_restore_test' => 1, 'tested_at' => $inCompanyZone(time() - 3600), 'who' => 'Tech', 'result' => 'pass', 'stores' => ['control_db']]);
$ok((int) $one('SELECT COUNT(*) FROM compliance_restore_tests') === 1, 'non-administrator: cannot record a restore test');
$res = array_values(array_filter(ComplianceService::assess($db)->automatic, fn ($r) => $r['id'] === 'restore_test_recency'))[0] ?? null;
// Without row counts and without the PostgreSQL store Core only WARNS (it will not call an uncompared restore proven): that is the honest answer here.
$ok($res !== null && $res['status'] === 'warn' && stripos((string) $res['detail'], 'row count') !== false, 'restore_test_recency: a recent test without row counts is a warning that says so (' . ($res['status'] ?? 'missing') . ')');

// ------------------------------------------------------------------ rules for removal and the audit trail
$post($admin, ['delete_compliance_profile' => 1, 'profile_key' => 'contract_x']);
$ok($store()->get('contract_x') !== null, 'a selected framework cannot be deleted');
$post($admin, ['archive_compliance_profile' => 1, 'profile_key' => 'contract_x']);
$ok($store()->get('contract_x')->status === 'active', 'a selected framework cannot be archived');
$post($admin, ['select_compliance_profile' => 1, 'profile_key' => '']);
$ok($sel() === null, 'select none clears the selection');
$post($admin, ['archive_compliance_profile' => 1, 'profile_key' => 'xss_test']);
$ok($store()->get('xss_test')->status === 'archived', 'archive works for an unselected framework');
$post($admin, ['delete_compliance_profile' => 1, 'profile_key' => 'xss_test']);
$ok($store()->get('xss_test') === null, 'a never-selected framework can be deleted');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type LIKE 'compliance.framework.%'") >= 4, 'framework changes are in the audit trail (' . $one("SELECT COUNT(*) FROM audit_events WHERE event_type LIKE 'compliance.framework.%'") . ' events)');
$ok(!str_contains((string) $one("SELECT GROUP_CONCAT(metadata) FROM audit_events WHERE event_type LIKE 'compliance.framework.%'"), 's3cret'), 'no secret in the audit entries');
