<?php
/*
 * Seeds a THROWAWAY install for tests/employee_portal_i32.php and tests/browser/employee_portal_i32_smoke.mjs (issue #32, employee portal).
 * Refuses any database whose name does not contain "scratch". WIPES the tables it fills. Never touches a real install.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/browser/employee_portal_i32_seed.php /tmp/ep-seed.json
 *
 * Logins written to the JSON: agent admin admin@scratch.test, employees alice@a.test (dept A), bob@a.test (dept A), carl@b.test (dept B),
 * department administrator dee@a.test (dept A). Passwords are in the JSON.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
$q = static function (string $sql) use ($db) { $r = $db->query($sql); if ($r === false) { fwrite(STDERR, "SQL failed: " . $db->error . "\n  $sql\n"); exit(1); } return $r; };

foreach (['portal_api_tokens', 'portal_feed_state', 'kb_article_views', 'portal_announcements', 'kb_articles', 'ticket_replies', 'tickets', 'endpoint_agent_devices', 'asset_rmm_links', 'assets',
    'workflow_task_log', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates', 'service_catalog_request_approvals', 'service_catalog_requests', 'service_catalog_items',
    'contacts', 'clients', 'user_settings', 'user_role_permissions', 'user_roles', 'users', 'companies', 'logs'] as $t) { $q("DELETE FROM `$t`"); }

$admin_pw = 'Scratch-Admin-1234'; $emp_pw = 'Employee-Pass-123';
$H = static fn(string $p) => password_hash($p, PASSWORD_DEFAULT);
$q("INSERT INTO companies SET company_id = 1, company_name = 'Scratch Co'");
$q("UPDATE settings SET config_client_portal_enable = 1, config_module_enable_kb = 1, config_module_enable_training = 0, config_portal_home_sections = 'requests,approvals,devices,onboarding,training,catalog,announcements,kb' WHERE company_id = 1");
$q("INSERT INTO user_roles SET role_id = 1, role_name = 'Admin', role_is_admin = 1, role_type = 1");
$q("INSERT INTO users SET user_id = 1, user_name = 'Scratch Admin', user_email = 'admin@scratch.test', user_password = '" . $db->real_escape_string($H($admin_pw)) . "', user_type = 1, user_status = 1, user_role_id = 1");
$q("INSERT INTO user_settings (user_id) VALUES (1)");
$q("INSERT INTO clients SET client_id = 1, client_name = 'Dept A'");
$q("INSERT INTO clients SET client_id = 2, client_name = 'Dept B'");

$people = [[11, 21, 1, 'Alice A', 'alice@a.test', 0], [12, 22, 1, 'Bob B', 'bob@a.test', 0], [13, 23, 2, 'Carl C', 'carl@b.test', 0], [14, 24, 1, 'Dee D', 'dee@a.test', 1]];
foreach ($people as [$uid, $cid, $client, $name, $email, $primary]) {
    $q("INSERT INTO users SET user_id = $uid, user_name = '$name', user_email = '$email', user_password = '" . $db->real_escape_string($H($emp_pw)) . "', user_type = 2, user_status = 1, user_auth_method = 'local'");
    $q("INSERT INTO user_settings (user_id) VALUES ($uid)");
    $q("INSERT INTO contacts SET contact_id = $cid, contact_name = '$name', contact_email = '$email', contact_client_id = $client, contact_user_id = $uid, contact_primary = $primary");
}
$q("UPDATE contacts SET contact_manager_id = 24 WHERE contact_id = 21");

// devices: Alice has an agent-reporting laptop (warranty in 20 days) and an RMM-linked Mac last seen 5 days ago; Bob and Carl one each; one unassigned
$d20 = date('Y-m-d', strtotime('+20 days'));
$q("INSERT INTO assets SET asset_id = 31, asset_name = 'ALICE-LAPTOP', asset_type = 'Laptop', asset_make = 'Dell', asset_model = 'Latitude 7440', asset_serial = 'SN-A1', asset_os = 'Windows 11 Pro', asset_status = 'Deployed', asset_warranty_expire = '$d20', asset_install_date = '2024-02-01', asset_purchase_date = '2024-01-15', asset_notes = 'SECRET NOTE', asset_client_id = 1, asset_contact_id = 21");
$q("INSERT INTO assets SET asset_id = 35, asset_name = 'ALICE-MAC', asset_type = 'Laptop', asset_make = 'Apple', asset_model = 'MacBook Air', asset_serial = 'SN-A2', asset_status = 'Deployed', asset_warranty_expire = '" . date('Y-m-d', strtotime('-10 days')) . "', asset_client_id = 1, asset_contact_id = 21");
$q("INSERT INTO assets SET asset_id = 32, asset_name = 'BOB-LAPTOP', asset_type = 'Laptop', asset_make = 'HP', asset_client_id = 1, asset_contact_id = 22");
$q("INSERT INTO assets SET asset_id = 33, asset_name = 'SPARE-LAPTOP', asset_type = 'Laptop', asset_make = 'HP', asset_client_id = 1, asset_contact_id = 0");
$q("INSERT INTO assets SET asset_id = 34, asset_name = 'CARL-LAPTOP', asset_type = 'Laptop', asset_make = 'HP', asset_client_id = 2, asset_contact_id = 23");
$seen = date('Y-m-d H:i:s', time() - 300);
$q("INSERT INTO endpoint_agent_devices SET device_id = 1, install_id = '11111111-1111-1111-1111-111111111111', asset_id = 31, client_id = 1, last_checkin_at = '$seen', pending_reboot = 1, last_ip = '10.9.9.9', logged_in_user = 'alice'");
$q("INSERT INTO asset_rmm_links SET asset_id = 35, integration_id = 1, hostname = 'alice-mac', rmm_status = 'offline', last_seen = '" . date('Y-m-d H:i:s', time() - 5 * 86400) . "', os_name = 'macOS 14.4'");

// tickets: Alice has an open one with a staff reply and a closed one; Bob one; Carl one
$q("INSERT INTO ticket_statuses SET ticket_status_id = 1, ticket_status_name = 'New', ticket_status_color = '#000', ticket_status_active = 1 ON DUPLICATE KEY UPDATE ticket_status_name = 'New'");
$q("INSERT INTO tickets SET ticket_id = 51, ticket_prefix = 'T', ticket_number = 5001, ticket_subject = 'Alice printer broken', ticket_status = 1, ticket_client_id = 1, ticket_contact_id = 21");
$q("INSERT INTO tickets SET ticket_id = 52, ticket_prefix = 'T', ticket_number = 5002, ticket_subject = 'Alice old request', ticket_status = 1, ticket_client_id = 1, ticket_contact_id = 21, ticket_closed_at = NOW() - INTERVAL 2 DAY");
$q("INSERT INTO tickets SET ticket_id = 53, ticket_prefix = 'T', ticket_number = 5003, ticket_subject = 'Bob VPN', ticket_status = 1, ticket_client_id = 1, ticket_contact_id = 22");
$q("INSERT INTO tickets SET ticket_id = 54, ticket_prefix = 'T', ticket_number = 5004, ticket_subject = 'Carl secret', ticket_status = 1, ticket_client_id = 2, ticket_contact_id = 23");
$q("INSERT INTO ticket_replies SET ticket_reply = 'Try restarting', ticket_reply_type = 'Public', ticket_reply_by = 1, ticket_reply_ticket_id = 51, ticket_reply_created_at = NOW() - INTERVAL 1 HOUR");
$q("INSERT INTO ticket_replies SET ticket_reply = 'internal only', ticket_reply_type = 'Internal', ticket_reply_by = 1, ticket_reply_ticket_id = 53, ticket_reply_created_at = NOW() - INTERVAL 1 HOUR");

// knowledge base: 41 company-wide, 42 Dept A only, 43 Dept B only, 44 not client visible, 45 archived
foreach ([[41, 'Reset your password', 0, 1, 'NULL'], [42, 'Dept A parking guide', 1, 1, 'NULL'], [43, 'Dept B secret recipe', 2, 1, 'NULL'], [44, 'Internal runbook', 0, 0, 'NULL'], [45, 'Retired article', 0, 1, 'NOW()']] as [$id, $t, $cl, $vis, $arch]) {
    $q("INSERT INTO kb_articles SET kb_article_id = $id, kb_article_title = '$t', kb_article_content = '<p>x</p>', kb_article_content_raw = 'x', kb_article_client_id = $cl, kb_article_client_visible = $vis, kb_article_archived_at = $arch");
}
foreach ([[41, 22, 3], [42, 22, 1], [43, 23, 5], [44, 22, 4], [45, 22, 6]] as [$art, $contact, $n]) {
    for ($i = 0; $i < $n; $i++) { $q("INSERT INTO kb_article_views SET view_article_id = $art, view_contact_id " . "= $contact, view_client_id = " . ($contact === 23 ? 2 : 1) . ", view_at = NOW() - INTERVAL $i HOUR"); }
}

// announcements: all-department urgent pinned; Dept A info; Dept B info; expired; scheduled; archived
$q("INSERT INTO portal_announcements SET announcement_id = 61, announcement_title = 'Network maintenance tonight', announcement_body = 'The VPN will be down from 10pm.\nPlease save your work. <script>alert(1)</script>', announcement_severity = 'urgent', announcement_client_id = 0, announcement_pinned = 1, announcement_publish_at = NOW() - INTERVAL 1 HOUR");
$q("INSERT INTO portal_announcements SET announcement_id = 62, announcement_title = 'Dept A lunch on Friday', announcement_body = 'Pizza at noon.', announcement_severity = 'info', announcement_client_id = 1, announcement_publish_at = NOW() - INTERVAL 2 HOUR");
$q("INSERT INTO portal_announcements SET announcement_id = 63, announcement_title = 'Dept B only notice', announcement_body = 'For B.', announcement_severity = 'important', announcement_client_id = 2, announcement_publish_at = NOW() - INTERVAL 2 HOUR");
$q("INSERT INTO portal_announcements SET announcement_id = 64, announcement_title = 'Expired notice', announcement_body = 'old', announcement_client_id = 0, announcement_publish_at = NOW() - INTERVAL 5 DAY, announcement_expires_at = NOW() - INTERVAL 1 DAY");
$q("INSERT INTO portal_announcements SET announcement_id = 65, announcement_title = 'Scheduled for next week', announcement_body = 'later', announcement_client_id = 0, announcement_publish_at = NOW() + INTERVAL 7 DAY");
$q("INSERT INTO portal_announcements SET announcement_id = 66, announcement_title = 'Removed notice', announcement_body = 'gone', announcement_client_id = 0, announcement_publish_at = NOW() - INTERVAL 1 HOUR, announcement_archived_at = NOW()");

// Alice's onboarding checklist: one task due tomorrow (shows in the feed), one done, one approval (left in), one automated (left out)
$q("INSERT INTO workflow_templates SET workflow_template_id = 70, name = 'Onboard', type = 'onboarding', is_active = 1");
$q("INSERT INTO workflow_runs SET run_id = 80, workflow_template_id = 70, contact_id = 21, type = 'onboarding', status = 'in_progress'");
$q("INSERT INTO workflow_run_tasks SET run_id = 80, title = 'Sign the handbook', default_owner = 'Employee', sort_order = 1, status = 'pending', task_type = 'manual', due_at = NOW() + INTERVAL 1 DAY, instructions = 'INTERNAL NOTE'");
$q("INSERT INTO workflow_run_tasks SET run_id = 80, title = 'Collect laptop', default_owner = 'IT', sort_order = 2, status = 'completed', task_type = 'manual'");
$q("INSERT INTO workflow_run_tasks SET run_id = 80, title = 'Manager sign-off', sort_order = 3, status = 'pending', task_type = 'approval', approval_status = 'pending'");
$q("INSERT INTO workflow_run_tasks SET run_id = 80, title = 'Create mailbox', sort_order = 4, status = 'pending', task_type = 'action'");

$out = $argv[1] ?? (sys_get_temp_dir() . '/ep-seed.json');
file_put_contents($out, json_encode([
    'admin' => ['email' => 'admin@scratch.test', 'password' => $admin_pw],
    'employee_password' => $emp_pw,
    'alice' => ['email' => 'alice@a.test', 'contact' => 21, 'client' => 1], 'bob' => ['email' => 'bob@a.test', 'contact' => 22, 'client' => 1],
    'carl' => ['email' => 'carl@b.test', 'contact' => 23, 'client' => 2], 'dee' => ['email' => 'dee@a.test', 'contact' => 24, 'client' => 1],
], JSON_PRETTY_PRINT));
echo "seeded; details in $out\n";
