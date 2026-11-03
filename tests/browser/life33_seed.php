<?php
/*
 * Seeds a THROWAWAY install for tests/browser/life33_smoke.mjs (HR portal requests, Okta settings card, legacy rules in the unified list).
 * Refuses any database whose name does not contain "scratch". WIPES the tables it fills. Fictional data only.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/browser/life33_seed.php /tmp/life33-seed.json [dark]
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
$q = static function (string $sql) use ($db) { $r = $db->query($sql); if ($r === false) { fwrite(STDERR, "SQL failed: " . $db->error . "\n  $sql\n"); exit(1); } return $r; };
$dark = ($argv[2] ?? '') === 'dark' ? 1 : 0;

foreach (['hr_workflow_requests', 'ticket_automation_rules', 'automation_rules', 'workflow_task_log', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates',
    'contacts', 'clients', 'user_settings', 'user_client_permissions', 'user_role_permissions', 'user_roles', 'users', 'companies', 'logs', 'notifications', 'audit_events'] as $t) { $q("DELETE FROM `$t`"); }

$admin_pw = 'Scratch-Admin-1234'; $emp_pw = 'Employee-Pass-123';
$H = static fn(string $p) => password_hash($p, PASSWORD_DEFAULT);
$q("INSERT INTO companies SET company_id = 1, company_name = 'Scratch Co'");
$q("UPDATE settings SET config_client_portal_enable = 1, config_portal_hr_requests = 1, config_lifecycle_okta_actions = 0, config_theme_dark_default = $dark, config_portal_home_sections = 'requests,approvals,devices,onboarding,training,catalog,announcements,kb' WHERE company_id = 1");
$q("INSERT INTO user_roles SET role_id = 1, role_name = 'Admin', role_is_admin = 1, role_type = 1");
$q("INSERT INTO users SET user_id = 1, user_name = 'Scratch Admin', user_email = 'admin@scratch.test', user_password = '" . $db->real_escape_string($H($admin_pw)) . "', user_type = 1, user_status = 1, user_role_id = 1");
$q("INSERT INTO user_settings (user_id) VALUES (1)");
$q("INSERT INTO clients SET client_id = 1, client_name = 'Dept A'");
$q("INSERT INTO clients SET client_id = 2, client_name = 'Dept B'");

// portal people: Mona (manager of Rita and Ron), Dee (department administrator), Eve (ordinary employee), Fay (Dept B manager)
$people = [[11, 21, 1, 'Mona Manager', 'mona@a.test', 0, 'Team Lead', 0], [12, 22, 1, 'Rita Report', 'rita@a.test', 0, 'Analyst', 21], [13, 23, 1, 'Ron Report', 'ron@a.test', 0, 'Clerk', 21],
    [14, 24, 1, 'Dee Admin', 'dee@a.test', 1, 'Office Manager', 0], [15, 25, 1, 'Eve Employee', 'eve@a.test', 0, 'Designer', 0], [16, 26, 2, 'Fay Foreign', 'fay@b.test', 0, 'Clerk', 0]];
foreach ($people as [$uid, $cid, $client, $name, $email, $primary, $title, $mgr]) {
    $q("INSERT INTO users SET user_id = $uid, user_name = '$name', user_email = '$email', user_password = '" . $db->real_escape_string($H($emp_pw)) . "', user_type = 2, user_status = 1, user_auth_method = 'local'");
    $q("INSERT INTO user_settings (user_id) VALUES ($uid)");
    $q("INSERT INTO contacts SET contact_id = $cid, contact_name = '$name', contact_email = '$email', contact_client_id = $client, contact_user_id = $uid, contact_primary = $primary, contact_title = '$title', contact_manager_id = $mgr, contact_employment_status = 'active', contact_employee_type = 'employee'");
}
$q("UPDATE contacts SET contact_manager_id = 21 WHERE contact_id IN (22, 23)");

foreach ([[70, 'Standard onboarding', 'onboarding'], [71, 'Standard offboarding', 'offboarding'], [72, 'Promotion checklist', 'role_change']] as [$id, $n, $t]) {
    $q("INSERT INTO workflow_templates SET workflow_template_id = $id, name = '$n', type = '$t', is_active = 1");
    $q("INSERT INTO workflow_template_tasks SET workflow_template_id = $id, title = 'Do the thing', task_type = 'manual', required = 1, sort_order = 1");
}
// legacy ticket rules for the unified list
$q("INSERT INTO ticket_automation_rules SET rule_id = 1, rule_name = 'Tell the tech when a server goes offline', rule_enabled = 1, rule_trigger = 'asset_offline', rule_cond_field = 'client_id', rule_cond_op = 'equals', rule_cond_value = '1', rule_action = 'notify_assignee'");
$q("INSERT INTO ticket_automation_rules SET rule_id = 2, rule_name = 'Bump old tickets', rule_enabled = 1, rule_trigger = 'schedule', rule_cond_field = 'age_hours', rule_cond_op = 'greater_than', rule_cond_value = '72', rule_action = 'set_priority', rule_action_value = 'High'");

file_put_contents($argv[1] ?? '/tmp/life33-seed.json', json_encode(['admin' => ['admin@scratch.test', $admin_pw], 'mona' => ['mona@a.test', $emp_pw], 'eve' => ['eve@a.test', $emp_pw], 'dee' => ['dee@a.test', $emp_pw]]));
echo "seeded\n";
