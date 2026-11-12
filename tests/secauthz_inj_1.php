<?php
declare(strict_types=1);
/* IT-INJ-1: a non-primary portal contact must not rewrite a peer login's email / auth method (reset-flow takeover). */
require __DIR__ . '/secauthz_bootstrap.php';
sec_reset_fixture();
$q("INSERT INTO users SET user_id = 21, user_name = 'Tech Peer', user_email = 'tech@a.example', user_password = 'x', user_type = 2, user_auth_method = 'local'");
$q("INSERT INTO users SET user_id = 22, user_name = 'Victim', user_email = 'victim@a.example', user_password = 'x', user_type = 2, user_auth_method = 'local'");
$q("INSERT INTO users SET user_id = 23, user_name = 'Primary', user_email = 'primary@a.example', user_password = 'x', user_type = 2, user_auth_method = 'local'");
$q("INSERT INTO users SET user_id = 24, user_name = 'Other dept', user_email = 'b@b.example', user_password = 'x', user_type = 2, user_auth_method = 'local'");
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'Tech Peer', contact_email = 'tech@a.example', contact_client_id = 1, contact_technical = 1, contact_user_id = 21");
$q("INSERT INTO contacts SET contact_id = 2, contact_name = 'Victim', contact_email = 'victim@a.example', contact_client_id = 1, contact_user_id = 22");
$q("INSERT INTO contacts SET contact_id = 3, contact_name = 'Primary', contact_email = 'primary@a.example', contact_client_id = 1, contact_primary = 1, contact_technical = 1, contact_user_id = 23");
$q("INSERT INTO contacts SET contact_id = 4, contact_name = 'Other', contact_email = 'b@b.example', contact_client_id = 2, contact_user_id = 24");
$post = fn(int $id, string $email, string $auth = 'local', array $x = []) => array_merge(['edit_contact' => 1, 'csrf_token' => 't', 'contact_id' => $id, 'contact_name' => 'Renamed', 'contact_email' => $email, 'contact_auth_method' => $auth, 'contact_technical' => 1], $x);
$u = fn(int $id) => mysqli_fetch_assoc($q("SELECT user_email, user_auth_method, user_name FROM users WHERE user_id = $id"));
$F = 'client/post.php';
$tech = ['session_client_id' => 1, 'session_contact_id' => 1, 'session_user_id' => 21, 'session_contact_primary' => 0, 'session_contact_is_technical_contact' => true, 'session_contact_name' => 'Tech Peer'];
$prim = ['session_client_id' => 1, 'session_contact_id' => 3, 'session_user_id' => 23, 'session_contact_primary' => 1, 'session_contact_is_technical_contact' => true, 'session_contact_name' => 'Primary'];

sec_run($F, 'edit_contact', $post(2, 'attacker@evil.example', 'local'), $tech);
$v = $u(22);
$ok($v['user_email'] === 'victim@a.example', "technical peer cannot change the victim's login email (now {$v['user_email']})");
$ok($one("SELECT contact_email FROM contacts WHERE contact_id = 2") === 'victim@a.example', "...and the contact email is untouched too");
sec_run($F, 'edit_contact', $post(2, 'victim@a.example', 'azure'), $tech);
$ok($u(22)['user_auth_method'] === 'local', "technical peer cannot change the auth method");
sec_run($F, 'edit_contact', $post(2, 'victim@a.example', 'local'), $tech);
$ok($one("SELECT contact_name FROM contacts WHERE contact_id = 2") === 'Renamed', "technical peer can still edit name/flags of a peer");
// contact with no login yet: a technical peer cannot mint one
$q("INSERT INTO contacts SET contact_id = 5, contact_name = 'NoLogin', contact_email = 'nl@a.example', contact_client_id = 1, contact_user_id = 0");
sec_run($F, 'edit_contact', $post(5, 'nl2@a.example', 'local'), $tech);
$ok((int) $one("SELECT COUNT(*) FROM users WHERE user_email IN ('nl@a.example','nl2@a.example')") === 0, "technical peer cannot create a login for a contact");
// other department stays unreachable, self stays unreachable
$r = sec_run($F, 'edit_contact', $post(4, 'x@evil.example'), $prim);
$ok($u(24)['user_email'] === 'b@b.example', "other-department contact cannot be edited even by the primary ($r)");
$r = sec_run($F, 'edit_contact', $post(1, 'self@a.example'), $tech);
$ok($u(21)['user_email'] === 'tech@a.example', "editing your own contact is still refused");
// primary keeps the ability
sec_run($F, 'edit_contact', $post(2, 'new-victim@a.example', 'local'), $prim);
$ok($u(22)['user_email'] === 'new-victim@a.example', "primary contact can still change a peer's login email");
sec_run($F, 'edit_contact', $post(5, 'nl3@a.example', 'local'), $prim);
$ok((int) $one("SELECT COUNT(*) FROM users WHERE user_email = 'nl3@a.example'") === 1, "primary contact can still create a login");
exit($T_fails ? 1 : 0);
