<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Issue #29 security/permission findings (app-findings.md). Two parts:
 *  1. Source regression guards for the findings that were already fixed (the guard must stay in the code).
 *  2. The one change made here - Technician is seeded with Knowledge Base Modify and NOT Reporting - run against a THROWAWAY mariadb:10.11
 *     that holds db.sql (never a live server). Skips the DB part without the env.
 *   RIVETIT_TEST_I29_PORT=55329 RIVETIT_TEST_I29_ROOT_PASSWORD=... php tests/security_findings_i29.php
 */
$root = dirname(__DIR__);
$fail = 0;
$ok = function ($c, $m) use (&$fail) { echo ($c ? 'PASS  ' : 'FAIL  ') . $m . "\n"; if (!$c) { $fail++; } };
$src = fn ($p) => (string) file_get_contents("$root/$p");

$u = $src('admin/post/users.php');
$ok(str_contains($u, 'this is the only active administrator'), 'Users edit refuses to demote the last active administrator');
$ok(str_contains($src('api/v1/index.php'), 'LegacyKeys::MSG_REMOVED') && !preg_match('/ORDER BY user_id[^;]*LIMIT 1[^;]*api_key/i', $src('api/v1/index.php')), 'X-Api-Key is refused (401), no lowest-id agent fallback');
$rc = $src('agent/post/rmm_check.php');
$ok(preg_match("/action === 'save_policy'\) \{\s*enforceUserPermission\('module_rmm', 2\)/", $rc) === 1, 'RMM save_policy needs level 2');
$ok(preg_match("/action === 'delete_policy'\) \{\s*enforceUserPermission\('module_rmm', 2\)/", $rc) === 1, 'RMM delete_policy needs level 2');
$ok(str_contains($src('agent/reports/schedules.php'), "lookupUserPermission('module_reporting') >= 2"), 'report schedules need Reporting level 2');
$ok(substr_count($src('functions.php'), 'ReportScope::clause') >= 6, 'report data functions apply the per-user department scope');
foreach (['credential_rotation', 'credential_rotation_v2', 'included_issues', 'ticket_by_client', 'ticket_summary', 'client_ticket_time_detail'] as $r) {
    $ok(str_contains($src("agent/reports/$r.php"), 'ReportScope'), "report $r is department-scoped");
}
$ct = $src('client/contacts.php');
$ok(!preg_match('/post\.php\?logout/', $ct) && str_contains($ct, 'Location: index.php'), 'portal Contacts no longer logs an ordinary employee out');
$ok(str_contains($src('client/includes/check_login.php'), 'config_client_portal_enable'), 'portal re-checks the portal switch on every page');
$pc = $src('agent/post/contact.php');
$ok(substr_count($pc, 'contact_user_id FROM contacts') >= 2 && substr_count($pc, 'DELETE FROM users WHERE user_id = $contact_user_id') >= 2, 'contact delete (single and bulk) reads and deletes the portal login');
$ok(str_contains($src('client/post.php'), 'document_created_by = $session_user_id'), 'portal document stores the user id');
foreach (glob("$root/agent/*.php") as $f) {
    $t = file_get_contents($f);
    if (preg_match_all('/<button[^>]*?>/s', '', $m) || !preg_match_all('/<button(?:(?!>).)*?name="bulk_delete_[a-z_]+"/s', $t, $mm)) { continue; }
    foreach ($mm[0] as $btn) { $ok(str_contains($btn, 'confirm-link'), basename($f) . ': bulk delete button has confirm-link'); }
}
$ok(str_contains($src('js/confirm_modal.js'), 'requestSubmit(link)'), 'confirm modal re-submits with the clicked button name');
$ok(str_contains($src('scripts/setup_cli.php'), "module_name = 'module_kb'") && str_contains($src('setup/index.php'), "module_name = 'module_kb'"), 'both installers seed Technician KB access');

$port = getenv('RIVETIT_TEST_I29_PORT'); $pw = (string) getenv('RIVETIT_TEST_I29_ROOT_PASSWORD');
if ($port && $pw !== '') {
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = new mysqli('127.0.0.1', 'root', $pw, '', (int) $port);
    $ok(!$db->connect_errno, 'throwaway MariaDB reachable');
    $name = 'i29a_scratch_' . getmypid();
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    putenv("MYSQL_PWD=$pw");
    exec('mysql -h 127.0.0.1 -P ' . (int) $port . " -uroot $name < " . escapeshellarg("$root/db.sql") . ' 2>&1', $o, $rc2);
    putenv('MYSQL_PWD');
    $ok($rc2 === 0, 'db.sql loads into the scratch database');
    $db->select_db($name);
    // Run exactly the seed statements the installers contain
    foreach ([['scripts/setup_cli.php', ''], ['setup/index.php', '']] as [$file]) {
        $db->query('DELETE FROM user_role_permissions'); $db->query('DELETE FROM user_roles'); $db->query('DELETE FROM modules');
        $code = $src($file);
        preg_match_all('/mysqli_query\(\$mysqli, "(INSERT INTO (?:modules|user_roles|user_role_permissions)[^"]*)"\);/', $code, $stm);
        foreach ($stm[1] as $sql) { $db->query($sql); }
        $kb = $db->query("SELECT p.user_role_permission_level FROM user_role_permissions p JOIN modules m ON m.module_id=p.module_id WHERE p.user_role_id=2 AND m.module_name='module_kb'")->fetch_row();
        $rp = $db->query("SELECT COUNT(*) FROM user_role_permissions p JOIN modules m ON m.module_id=p.module_id WHERE p.user_role_id=2 AND m.module_name='module_reporting'")->fetch_row();
        $ok(($kb[0] ?? null) == 2, "$file: Technician has module_kb Modify (2)");
        $ok((int) $rp[0] === 0, "$file: Technician has no Reporting row");
        $acc = $db->query("SELECT COUNT(*) FROM user_role_permissions p JOIN modules m ON m.module_id=p.module_id WHERE p.user_role_id=1 AND m.module_name='module_kb'")->fetch_row();
        $ok((int) $acc[0] === 0, "$file: Accountant unchanged (no KB row)");
    }
    $db->query("DROP DATABASE `$name`");
} else {
    echo "SKIP  DB part needs RIVETIT_TEST_I29_PORT and RIVETIT_TEST_I29_ROOT_PASSWORD\n";
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
