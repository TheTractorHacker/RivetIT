<?php
/*
 * Seeds a THROWAWAY install for tests/i29_leftovers.php and tests/browser/i29_leftovers_smoke.mjs (issue #29 leftovers). Fictional data only.
 * Refuses any database whose name does not contain "scratch". WIPES the tables it fills. Needs the install's own setup (scripts/setup_cli.php)
 * to have run first (admin user 1, roles, modules, ticket statuses).
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/browser/i29_leftovers_seed.php /tmp/i29-seed.json
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
$q = static function (string $sql) use ($db) { $r = $db->query($sql); if ($r === false) { fwrite(STDERR, "SQL failed: " . $db->error . "\n  $sql\n"); exit(1); } return $r; };

foreach (['rmm_check_deployments', 'rmm_check_policies', 'asset_rmm_links', 'rmm_integrations', 'software_assets', 'software_contacts', 'software', 'vendors', 'tasks', 'tickets', 'projects',
    'department_sites', 'email_queue', 'assets', 'contacts', 'locations', 'logs'] as $t) { $q("DELETE FROM `$t`"); }
// This seed owns every login on the scratch install: it replaces the setup's users and roles with its own.
foreach (['user_role_permissions', 'user_settings', 'users', 'user_roles'] as $t) { $q("DELETE FROM `$t`"); }
$q("DELETE FROM clients");

$pw = 'Scratch-Pass-1234';
$H = $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT));

// An administrator, a role with Support + Department access but NO Finance (the vendors permission case), and one with Finance only.
$q("INSERT INTO user_roles SET role_id = 20, role_name = 'Scratch Admin', role_is_admin = 1, role_type = 1");
$q("INSERT INTO user_roles SET role_id = 21, role_name = 'Scratch Support', role_is_admin = 0, role_type = 1");
$q("INSERT INTO user_roles SET role_id = 22, role_name = 'Scratch Finance', role_is_admin = 0, role_type = 1");
$mods = [];
$res = $q("SELECT module_id, module_name FROM modules");
while ($r = $res->fetch_assoc()) { $mods[$r['module_name']] = (int) $r['module_id']; }
foreach (['module_support' => 2, 'module_client' => 2] as $m => $lvl) { $q("INSERT INTO user_role_permissions SET user_role_id = 21, module_id = {$mods[$m]}, user_role_permission_level = $lvl"); }
foreach (['module_financial' => 2, 'module_support' => 1] as $m => $lvl) { if (isset($mods[$m])) { $q("INSERT INTO user_role_permissions SET user_role_id = 22, module_id = {$mods[$m]}, user_role_permission_level = $lvl"); } }
foreach ([[1, 'Scratch Admin', 'admin@scratch.test', 20], [2, 'Sam Support', 'sam.support@scratch.test', 21], [3, 'Fin Finance', 'fin.finance@scratch.test', 22]] as [$uid, $name, $email, $role]) {
    $q("INSERT INTO users SET user_id = $uid, user_name = '$name', user_email = '$email', user_password = '$H', user_type = 1, user_status = 1, user_role_id = $role");
    $q("INSERT INTO user_settings (user_id) VALUES ($uid)");
}
// An archived agent (the users CSV must leave it out unless asked) and a portal login (never in the agent CSV)
$q("INSERT INTO users SET user_id = 4, user_name = 'Olive Archived', user_email = 'olive.archived@scratch.test', user_password = '$H', user_type = 1, user_status = 1, user_role_id = 21, user_archived_at = NOW()");
$q("INSERT INTO users SET user_id = 5, user_name = 'Portal Pat', user_email = 'portal.pat@scratch.test', user_password = '$H', user_type = 2, user_status = 1, user_auth_method = 'local'");
$q("INSERT INTO user_settings (user_id) VALUES (4), (5)");

// Departments, sites and people
$q("INSERT INTO clients SET client_id = 1, client_name = 'Alpha Dept', client_created_at = NOW()");
$q("INSERT INTO clients SET client_id = 2, client_name = 'Beta Dept', client_created_at = NOW()");
$q("INSERT INTO clients SET client_id = 3, client_name = 'Gamma Dept (archived)', client_created_at = NOW(), client_archived_at = NOW()");
$q("INSERT INTO locations SET location_id = 11, location_name = 'Alpha Annex', location_client_id = 1");
$q("INSERT INTO locations SET location_id = 12, location_name = 'Shared Campus', location_client_id = 1");
$q("INSERT INTO department_sites SET client_id = 2, location_id = 12");
$people = [[21, 'Ann Primary', 'ann@a.example', 1, 1, 11], [22, 'Bob Shared', 'bob@a.example', 1, 0, 12], [23, 'Cora Beta', 'cora@b.example', 2, 1, 0], [24, 'Dan Archived', 'dan@a.example', 1, 0, 0], [26, 'Gus Gamma', 'gus@g.example', 3, 1, 0]];
foreach ($people as [$id, $name, $email, $cl, $prim, $loc]) {
    $arch = $id === 24 ? ', contact_archived_at = NOW()' : '';
    $q("INSERT INTO contacts SET contact_id = $id, contact_name = '$name', contact_email = '$email', contact_client_id = $cl, contact_primary = $prim, contact_location_id = $loc, contact_technical = 1, contact_title = 'Analyst'$arch");
}

// Assets: Ann has one in Alpha; Beta has one that a bug once linked to an Alpha license
$q("INSERT INTO assets SET asset_id = 31, asset_name = 'ALPHA-LAPTOP-1', asset_type = 'Laptop', asset_client_id = 1, asset_contact_id = 21");
$q("INSERT INTO assets SET asset_id = 32, asset_name = 'ALPHA-LAPTOP-2', asset_type = 'Laptop', asset_client_id = 1, asset_contact_id = 22");
$q("INSERT INTO assets SET asset_id = 33, asset_name = 'BETA-DESKTOP-1', asset_type = 'Desktop', asset_client_id = 2, asset_contact_id = 23");
$q("INSERT INTO assets SET asset_id = 34, asset_name = 'BETA-DESKTOP-2', asset_type = 'Desktop', asset_client_id = 2, asset_contact_id = 0");

// Software licence in Alpha: seats on ALPHA-LAPTOP-1 and Ann, plus an out-of-department seat on the Beta desktop (must survive an edit)
$q("INSERT INTO software SET software_id = 41, software_name = 'Scratch Suite', software_type = 'Productivity', software_client_id = 1, software_seats = 5");
$q("INSERT INTO software_assets (software_id, asset_id) VALUES (41, 31), (41, 33)");
$q("INSERT INTO software_contacts (software_id, contact_id) VALUES (41, 21)");

// Vendors: one company-wide with an account number, one in Alpha
$q("INSERT INTO vendors SET vendor_id = 51, vendor_name = 'Acme Telecom (scratch)', vendor_account_number = 'ACCT-SECRET-777', vendor_contact_name = 'Vic Vendor', vendor_client_id = 0");
$q("INSERT INTO vendors SET vendor_id = 52, vendor_name = 'Alpha Print Supply', vendor_account_number = 'ACCT-ALPHA-1', vendor_client_id = 1");

// Projects: one with NO department, one in Alpha; a closed Alpha ticket whose updated_at is NULL; project-only tasks
$q("INSERT INTO projects SET project_id = 61, project_prefix = 'PRJ-', project_number = 1, project_name = 'Company Wide Rollout', project_client_id = 0, project_created_at = NOW()");
$q("INSERT INTO projects SET project_id = 62, project_prefix = 'PRJ-', project_number = 2, project_name = 'Alpha Migration', project_client_id = 1, project_created_at = NOW()");
$q("INSERT INTO tickets SET ticket_id = 71, ticket_prefix = 'T-', ticket_number = 7001, ticket_subject = 'Closed Alpha ticket', ticket_details = 'x', ticket_status = 5, ticket_created_by = 1, ticket_client_id = 1, ticket_closed_at = NOW() - INTERVAL 3 DAY, ticket_updated_at = NULL");
$q("INSERT INTO tickets SET ticket_id = 72, ticket_prefix = 'T-', ticket_number = 7002, ticket_subject = 'Open Alpha ticket', ticket_details = 'x', ticket_status = 2, ticket_created_by = 1, ticket_client_id = 1, ticket_project_id = 62");
$q("INSERT INTO tasks SET task_id = 81, task_name = 'Plan cutover', task_project_id = 62, task_status = 'To Do', task_order = 1");
$q("INSERT INTO tasks SET task_id = 82, task_name = 'Order cables', task_project_id = 62, task_status = 'To Do', task_order = 2");
$q("INSERT INTO tasks SET task_id = 83, task_name = 'Ticket-bound task', task_ticket_id = 72, task_order = 3");

// RMM: a Tactical integration that points at a closed port (nothing is ever contacted), one policy deployed to two devices
$q("INSERT INTO rmm_integrations SET id = 91, name = 'Scratch Tactical', type = 'tactical_rmm', api_url = 'http://127.0.0.1:9', api_key_enc = 'not-a-real-key', enabled = 1");
$q("INSERT INTO asset_rmm_links SET id = 92, asset_id = 31, integration_id = 91, tactical_agent_id = 'agent-a', hostname = 'alpha-laptop-1', os_name = 'Windows 11'");
$q("INSERT INTO asset_rmm_links SET id = 93, asset_id = 32, integration_id = 91, tactical_agent_id = 'agent-b', hostname = 'alpha-laptop-2', os_name = 'Windows 11'");
$q("INSERT INTO rmm_check_policies SET id = 94, name = 'Scratch disk policy', platform = 'windows', check_type = 'diskspace', warning_threshold = 80, critical_threshold = 90, enabled = 1");
$q("INSERT INTO rmm_check_deployments SET policy_id = 94, link_id = 92, tactical_check_id = '501', status = 'active'");
$q("INSERT INTO rmm_check_deployments SET policy_id = 94, link_id = 93, tactical_check_id = '502', status = 'active'");

file_put_contents($argv[1] ?? 'php://stdout', json_encode(['password' => $pw, 'admin' => 'admin@scratch.test', 'support' => 'sam.support@scratch.test', 'finance' => 'fin.finance@scratch.test'], JSON_PRETTY_PRINT));
