<?php
/*
 * Wave 1 security, item 4: TOTP seeds, recovery codes, MFA enforcement policy.
 *   - ten single-use recovery codes: format, only hashes stored, single use (also under a replay), regenerate cancels the old set,
 *     normalisation of what the user types, wrong code, other user's code
 *   - the MFA policy (off / admins / all), per-user flag, grace days, blocked state, identity-provider exemption
 *   - remember-me may not stand in for the second factor of an admin or a vault user unless the setting says so
 *   - login.php wiring (TOTP seed read through secUserTotpSecret, recovery field, rehash call, remember-me rule) by source inspection,
 *     and the whole flow against the real page in tests/security_login_http.php
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/security_mfa.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_crypto.php";
require_once "$root/includes/security_policy.php";

$q("DELETE FROM user_recovery_codes");
$q("DELETE FROM security_settings");
secSettingsAll($db, true);

// users: an admin role, a vault role, a plain role
$q("DELETE FROM user_role_permissions WHERE user_role_id IN (91,92,93)");
$q("DELETE FROM user_roles WHERE role_id IN (91,92,93)");
$q("INSERT INTO user_roles SET role_id=91, role_name='Sec Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO user_roles SET role_id=92, role_name='Sec Vault', role_is_admin=0, role_type=1");
$q("INSERT INTO user_roles SET role_id=93, role_name='Sec Plain', role_is_admin=0, role_type=1");
$q("DELETE FROM modules WHERE module_name='module_credential'");
$q("INSERT INTO modules SET module_id=71, module_name='module_credential'");
$q("INSERT INTO user_role_permissions SET user_role_id=92, module_id=71, user_role_permission_level=2");
$q("DELETE FROM users WHERE user_email LIKE 'sec-mfa-%'");
$mk = function (string $tag, int $role, string $token = '', string $auth = 'local', string $created = '2026-01-01 00:00:00') use ($q, $esc, $one): int {
    $q("INSERT INTO users SET user_name='Sec $tag', user_email='sec-mfa-$tag@example.test', user_password='x', user_auth_method='$auth', user_type=1, user_status=1, user_role_id=$role, user_token='" . $esc($token) . "', user_created_at='$created'");
    $id = (int) $one("SELECT user_id FROM users WHERE user_email='sec-mfa-$tag@example.test'");
    $q("INSERT INTO user_settings SET user_id=$id ON DUPLICATE KEY UPDATE user_id=user_id");
    return $id;
};
$admin = $mk('admin', 91); $vault = $mk('vault', 92); $plain = $mk('plain', 93); $other = $mk('other', 93);

// ------------------------------------------------------------------ recovery codes
$codes = secRecoveryCodesGenerate($db, $plain);
$ok(count($codes) === 10, 'ten recovery codes are generated');
$ok(count(array_unique($codes)) === 10, 'and they are all different');
$allFormat = true; foreach ($codes as $c) { $allFormat = $allFormat && preg_match('/^[a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5}$/', $c) === 1; }
$ok($allFormat, 'each code is xxxxx-xxxxx from an alphabet without look-alike characters');
$stored = $rows("SELECT code_hash FROM user_recovery_codes WHERE code_user_id=$plain");
$clearStored = false; foreach ($stored as $r) { foreach ($codes as $c) { if (str_contains($r['code_hash'], str_replace('-', '', $c))) { $clearStored = true; } } }
$ok(count($stored) === 10 && !$clearStored && str_starts_with($stored[0]['code_hash'], '$2y$'), 'only password_hash() values are stored, never the codes');
$ok(secRecoveryCodesRemaining($db, $plain) === 10, 'ten codes remain');

$ok(secRecoveryCodeConsume($db, $plain, 'wrong-codes', '127.0.0.1') === false && secRecoveryCodeConsume($db, $plain, 'abcde-fghjk', '127.0.0.1') === false, 'a wrong code is refused');
$ok(secRecoveryCodesRemaining($db, $plain) === 10, 'a wrong code uses nothing up');
$ok(secRecoveryCodeConsume($db, $other, $codes[0], '127.0.0.1') === false, 'another user cannot use this user\'s code');
$ok(secRecoveryCodeConsume($db, $plain, $codes[0], '10.0.0.5') === true, 'a correct code is accepted once');
$ok(secRecoveryCodeConsume($db, $plain, $codes[0], '10.0.0.5') === false, 'the same code is refused the second time (single use)');
$ok(secRecoveryCodesRemaining($db, $plain) === 9, 'nine codes remain after one is used');
$ok($one("SELECT code_used_ip FROM user_recovery_codes WHERE code_user_id=$plain AND code_used_at IS NOT NULL") === '10.0.0.5', 'the IP that used the code is recorded');
$typed = strtoupper(str_replace('-', ' ', $codes[1]));
$ok(secRecoveryCodeConsume($db, $plain, "  $typed ", '127.0.0.1') === true, 'case, dashes and spaces the user types do not matter');
$ok(secLooksLikeRecoveryCode($codes[2]) && !secLooksLikeRecoveryCode('123456') && !secLooksLikeRecoveryCode('abc') && !secLooksLikeRecoveryCode('abcde-fghi0'), 'secLooksLikeRecoveryCode tells a code from a six digit TOTP code');
// replay under concurrency: two consumers of the same code, the second must lose (atomic UPDATE ... WHERE code_used_at IS NULL)
$c3 = $codes[3];
$first = secRecoveryCodeConsume($db, $plain, $c3, 'a'); $second = secRecoveryCodeConsume($db, $plain, $c3, 'b');
$ok($first === true && $second === false, 'a code can never be accepted twice');

$new = secRecoveryCodesGenerate($db, $plain);
$ok(count($new) === 10 && secRecoveryCodesRemaining($db, $plain) === 10, 'regenerating gives a fresh set of ten');
$ok(secRecoveryCodeConsume($db, $plain, $codes[4], 'x') === false, 'an old code stops working after regeneration');
$ok(secRecoveryCodeConsume($db, $plain, $new[0], 'x') === true, 'a code from the new set works');
secRecoveryCodesDelete($db, $plain);
$ok(secRecoveryCodesRemaining($db, $plain) === 0 && secRecoveryCodeConsume($db, $plain, $new[1], 'x') === false, 'turning MFA off removes the codes');

// ------------------------------------------------------------------ the policy
$now = strtotime('2026-10-09 12:00:00');
$st = fn(int $uid) => secMfaEnforcementState($db, $uid, $now);
$ok(secSetting('mfa_policy') === 'off', 'the policy is off by default');
$ok($st($admin)['applies'] === false && $st($plain)['applies'] === false, 'policy off: nobody is required');

secSettingSet($db, 'mfa_policy', 'admins'); secSettingSet($db, 'mfa_grace_days', 7); secSettingSet($db, 'mfa_policy_since', '2026-10-09 08:00:00');
$a = $st($admin);
$ok($a['applies'] && !$a['enrolled'] && $a['in_grace'] && !$a['blocked'], 'policy admins: an admin without MFA is required but in grace');
$ok($a['grace_ends'] === strtotime('2026-10-16 08:00:00'), 'the grace period ends grace-days after the policy was switched on');
$ok($st($plain)['applies'] === false && $st($vault)['applies'] === false, 'policy admins: other roles are not affected');
$later = secMfaEnforcementState($db, $admin, strtotime('2026-10-17 00:00:00'));
$ok($later['blocked'] && !$later['in_grace'], 'after the grace period an unenrolled admin is blocked');
$q("UPDATE users SET user_token='" . $esc(secUserTotpStore('JBSWY3DPEHPK3PXP')) . "' WHERE user_id=$admin");
$a2 = secMfaEnforcementState($db, $admin, strtotime('2026-10-30 00:00:00'));
$ok($a2['applies'] && $a2['enrolled'] && !$a2['blocked'], 'an enrolled admin is never blocked');
$q("UPDATE users SET user_token='" . $esc('JBSWY3DPEHPK3PXP') . "' WHERE user_id=$admin");
$ok(secMfaEnforcementState($db, $admin, strtotime('2026-10-30 00:00:00'))['enrolled'], 'a legacy plaintext seed counts as enrolled too');
$q("UPDATE users SET user_token='' WHERE user_id=$admin");

secSettingSet($db, 'mfa_policy', 'all');
$ok($st($plain)['applies'] && $st($vault)['applies'] && $st($admin)['applies'], 'policy all: every agent is required');
// a user created after the policy: grace starts at account creation
$newbie = $mk('newbie', 93, '', 'local', '2026-10-09 11:00:00');
$ok(secMfaEnforcementState($db, $newbie, strtotime('2026-10-12 00:00:00'))['in_grace'] && secMfaEnforcementState($db, $newbie, strtotime('2026-10-17 00:00:00'))['blocked'], 'a new account gets the grace period from its own creation');
// grace 0: blocked at once
secSettingSet($db, 'mfa_grace_days', 0);
$ok($st($plain)['blocked'], 'grace 0 days: blocked at once');
// per-user flag with policy off
secSettingSet($db, 'mfa_policy', 'off');
$q("UPDATE user_settings SET user_config_force_mfa=1 WHERE user_id=$plain");
$f = $st($plain);
$ok($f['applies'] && $f['blocked'] && $f['reason'] === 'user' && $f['grace_ends'] === null, 'the per-user "require MFA" flag applies with no grace');
$q("UPDATE user_settings SET user_config_force_mfa=0 WHERE user_id=$plain");
// identity-provider accounts
secSettingSet($db, 'mfa_policy', 'all');
$sso = $mk('sso', 93, '', 'oidc');
$ok($st($sso)['applies'] === false, 'an account that signs in through an identity provider is exempt');
// portal users are not agents
$q("INSERT INTO users SET user_name='Sec Portal', user_email='sec-mfa-portal@example.test', user_password='x', user_type=2, user_status=1, user_role_id=0");
$ok($st((int) $one("SELECT user_id FROM users WHERE user_email='sec-mfa-portal@example.test'"))['applies'] === false, 'department (portal) logins are not covered by the agent policy');

// policy setting normalisation
secSettingSet($db, 'mfa_policy', 'everyone-please');
$ok(secSetting('mfa_policy') === 'off', 'an unknown policy value falls back to off');
secSettingSet($db, 'mfa_grace_days', 9999);
$ok(secSettingInt('mfa_grace_days') === 90, 'grace days are capped at 90');

// the hard gate: blocked users reach only /agent/user/*; in grace they get a one-time notice
secSettingSet($db, 'mfa_policy', 'all'); secSettingSet($db, 'mfa_grace_days', 0);
$gateRedirected = null;
// secMfaGate redirects via redirect() (exit); run it in a child process so the exit can be observed
$php = function (string $script, string $path) use ($root): array {
    $code = '$_SERVER["DOCUMENT_ROOT"]=' . var_export($root, true) . ';$_SERVER["REMOTE_ADDR"]="127.0.0.1";ob_start();require ' . var_export("$root/config.php", true) . ';require ' . var_export("$root/functions.php", true) . ';ob_end_clean();require ' . var_export("$root/includes/security_policy.php", true) . ';$_SESSION=[];secMfaGate($mysqli,' . $GLOBALS['__gate_uid'] . ',' . var_export($path, true) . ');echo "PASSED_GATE";';
    return sec_sh('php -r ' . escapeshellarg($code));
};
$GLOBALS['__gate_uid'] = $plain;
[$c, $o] = $php('', '/agent/tickets.php');
$ok(!str_contains($o, 'PASSED_GATE'), 'a blocked user is stopped on an ordinary agent page');
[$c, $o] = $php('', '/agent/user/user_security.php');
$ok(str_contains($o, 'PASSED_GATE'), 'a blocked user can still open the account pages needed to enrol');
[$c, $o] = $php('', '/agent/user/post.php');
$ok(str_contains($o, 'PASSED_GATE'), 'and post the enrolment form');
secSettingSet($db, 'mfa_grace_days', 30);
$q("UPDATE users SET user_created_at=NOW() WHERE user_id=$plain");
[$c, $o] = $php('', '/agent/tickets.php');
$ok(str_contains($o, 'PASSED_GATE'), 'in the grace period the user is let through');

// ------------------------------------------------------------------ remember-me
secSettingSet($db, 'remember_me_skips_mfa', 0);
$ok(secRememberMeMaySkipMfa($db, $plain) === true, 'remember-me may skip the second factor for a plain user');
$ok(secRememberMeMaySkipMfa($db, $admin) === false, 'remember-me may NOT skip it for an administrator');
$ok(secRememberMeMaySkipMfa($db, $vault) === false, 'remember-me may NOT skip it for a user with vault access');
secSettingSet($db, 'remember_me_skips_mfa', 1);
$ok(secRememberMeMaySkipMfa($db, $admin) === true && secRememberMeMaySkipMfa($db, $vault) === true, 'the setting allows it for them when switched on');
secSettingSet($db, 'remember_me_skips_mfa', 0);
$ok(secUserHasVaultAccess($db, $vault) && secUserHasVaultAccess($db, $admin) && !secUserHasVaultAccess($db, $plain), 'vault access is read from the role permissions');

// ------------------------------------------------------------------ wiring in login.php / api
$login = file_get_contents("$root/login.php");
$ok(str_contains($login, 'secUserTotpSecret($selectedRow[\'user_token\']') && str_contains($login, 'secUserTotpSecret($pm_row[\'user_token\'])'), 'login.php reads the agent and portal TOTP seed through secUserTotpSecret');
$ok(str_contains($login, "secRememberMeMaySkipMfa(\$mysqli, \$user_id)"), 'login.php applies the remember-me rule');
$ok(str_contains($login, 'secRecoveryCodeConsume(') && str_contains($login, "name='recovery_code'") && str_contains($login, "auth.recovery_code_used"), 'login.php accepts and audits a recovery code');
$ok(str_contains($login, 'secPasswordRehashIfNeeded('), 'login.php rehashes an out-of-date hash');
$api = file_get_contents("$root/api/v1/auth.php");
$ok(str_contains($api, 'secUserTotpSecret($user[\'user_token\']') && str_contains($api, 'secRecoveryCodeConsume('), 'the mobile API login reads the wrapped seed and accepts a recovery code');
$pf = file_get_contents("$root/agent/user/post/profile.php");
$ok(str_contains($pf, 'secUserTotpStore($token)') && str_contains($pf, 'secRecoveryCodesGenerate($mysqli, $session_user_id)'), 'enabling MFA stores the seed wrapped and creates the recovery codes');

// cleanup
$q("DELETE FROM user_recovery_codes");
$q("DELETE FROM users WHERE user_email LIKE 'sec-mfa-%'");
$q("DELETE FROM user_roles WHERE role_id IN (91,92,93)");
$q("DELETE FROM user_role_permissions WHERE user_role_id IN (91,92,93)");
$q("DELETE FROM modules WHERE module_id=71");
$q("DELETE FROM security_settings");
