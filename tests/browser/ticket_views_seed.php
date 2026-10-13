<?php
/*
 * Seeds a THROWAWAY install for tests/browser/ticket_views_smoke.mjs: an administrator (admin@scratch.test / RMM_SMOKE_PASSWORD, default
 * Scratch-Admin-1234), two more agents (Bob Agent, Cara Agent), three ticket categories and six tickets of mixed priority and assignment.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivet_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/browser/ticket_views_seed.php
 *
 * WIPES tickets, saved ticket views, users and ticket categories of that database (scratch guard of tests/endpoint_agent_lib.php).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';

foreach (['ticket_view_defaults', 'ticket_saved_views', 'tickets', 'ticket_replies', 'user_settings', 'user_role_permissions', 'user_roles', 'users'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM categories WHERE category_type = 'Ticket'");
$q("INSERT INTO user_roles SET role_id=1, role_name='Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO user_roles SET role_id=2, role_name='Tech', role_is_admin=0, role_type=1");
$pw = (string) (getenv('RMM_SMOKE_PASSWORD') ?: 'Scratch-Admin-1234');
$hash = $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT));
$q("INSERT INTO users SET user_id=1, user_name='Scratch Admin', user_email='admin@scratch.test', user_password='$hash', user_type=1, user_status=1, user_role_id=1");
$q("INSERT INTO users SET user_id=2, user_name='Bob Agent', user_email='bob@scratch.test', user_password='x', user_type=1, user_status=1, user_role_id=2");
$q("INSERT INTO users SET user_id=3, user_name='Cara Agent', user_email='cara@scratch.test', user_password='x', user_type=1, user_status=1, user_role_id=2");
foreach ([1, 2, 3] as $u) { $q("INSERT INTO user_settings (user_id) VALUES ($u)"); }
$q("INSERT INTO categories SET category_id=201, category_name='Infrastructure', category_type='Ticket', category_color='blue', category_parent=0");
$q("INSERT INTO categories SET category_id=202, category_name='Helpdesk', category_type='Ticket', category_color='red', category_parent=0");
$q("INSERT INTO categories SET category_id=203, category_name='Networking', category_type='Ticket', category_color='green', category_parent=201");
$rows = [['High', 1, '203', 'Alpha high mine'], ['Medium', 2, '202', 'Bravo medium bob'], ['Low', 0, '203', 'Charlie low unassigned'], ['High', 3, '202', 'Delta high cara'], ['Medium', 0, '201', 'Echo medium unassigned'], ['Low', 2, '202', 'Foxtrot low bob']];
$n = 7000;
foreach ($rows as [$pr, $as, $cat, $sub]) {
    $n++;
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=$n, ticket_subject='$sub', ticket_details='d', ticket_priority='$pr', ticket_status=2, ticket_created_by=1, ticket_assigned_to=$as, ticket_category='$cat'");
}
echo "seeded: 6 open tickets, agents Bob and Cara, admin admin@scratch.test\n";
