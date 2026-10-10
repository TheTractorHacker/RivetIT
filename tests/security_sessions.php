<?php
/*
 * Wave 1 security, item 6: sessions.
 *   - defaults: 8 hour idle timeout, 14 day absolute lifetime; the 30-day floor is gone (floor 60 minutes), the 90-day cap stays
 *   - idle expiry, absolute expiry, background polls do not extend the idle timer
 *   - user_sessions: hashed session id, ip, user agent; list, revoke one, sign out everywhere, revoked session is refused
 *   - a password change revokes every other session and the remember-me cookies
 *   - session.use_strict_mode, HttpOnly, SameSite cookie settings; the vault-key cookie is SameSite=Strict with the browser-session lifetime
 *   - remember-me in includes/auth_check.php is refused for an admin / vault user who has MFA
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/security_sessions.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_policy.php";
require_once "$root/includes/security_sessions.php";
ob_start();   // session_id() cannot be changed once output has started

$q("DELETE FROM security_settings");
$q("DELETE FROM user_sessions");
secSettingsAll($db, true);
$q("DELETE FROM users WHERE user_email LIKE 'sec-sess-%'");
$q("INSERT INTO users SET user_name='Sess One', user_email='sec-sess-1@example.test', user_password='x', user_type=1, user_status=1, user_role_id=0");
$q("INSERT INTO users SET user_name='Sess Two', user_email='sec-sess-2@example.test', user_password='x', user_type=1, user_status=1, user_role_id=0");
$u1 = (int) $one("SELECT user_id FROM users WHERE user_email='sec-sess-1@example.test'");
$u2 = (int) $one("SELECT user_id FROM users WHERE user_email='sec-sess-2@example.test'");

// ------------------------------------------------------------------ limits and defaults
$q("UPDATE settings SET config_login_session_lifetime=20160 WHERE company_id=1");
$lim = secSessionLimits($db);
$ok($lim['idle'] === 8 * 3600, 'the default idle timeout is 8 hours');
$ok($lim['absolute'] === 14 * 86400, 'the default absolute lifetime is 14 days');
$q("UPDATE settings SET config_login_session_lifetime=480 WHERE company_id=1");
$ok(secSessionLimits($db)['absolute'] === 480 * 60, 'a stored 480 minutes is honoured (the old 30-day minimum clamp is gone)');
$q("UPDATE settings SET config_login_session_lifetime=5 WHERE company_id=1");
$ok(secSessionLimits($db)['absolute'] === 3600, 'a very small value is raised to the 60 minute floor');
$q("UPDATE settings SET config_login_session_lifetime=9999999 WHERE company_id=1");
$ok(secSessionLimits($db)['absolute'] === 129600 * 60, 'the 90 day upper bound is kept');
$q("UPDATE settings SET config_login_session_lifetime=20160 WHERE company_id=1");
secSettingSet($db, 'session_idle_minutes', 30);
$ok(secSessionLimits($db)['idle'] === 1800, 'the idle timeout is a setting');
secSettingSet($db, 'session_idle_minutes', 1);
$ok(secSessionLimits($db)['idle'] === 300, 'and cannot be set under 5 minutes');
secSettingSet($db, 'session_idle_minutes', 480);
$ok((int) $one("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='settings' AND COLUMN_NAME='config_login_session_lifetime'") === 20160, 'the column default is 20160 minutes');

// ------------------------------------------------------------------ cookies / ini
$httpsOn = secSessionIniApply($db, true);
$ok(ini_get('session.use_strict_mode') === '1', 'session.use_strict_mode is on');
$ok(ini_get('session.cookie_httponly') === '1' && ini_get('session.cookie_secure') === '1' && ini_get('session.use_only_cookies') === '1', 'HttpOnly, Secure (on HTTPS) and cookies-only are set');
$cp = session_get_cookie_params();
$ok($cp['samesite'] === 'Lax' && $cp['httponly'] === true && $cp['lifetime'] === $httpsOn && $httpsOn === 14 * 86400, 'the session cookie is SameSite=Lax with the absolute lifetime');
$ok((int) ini_get('session.gc_maxlifetime') === $httpsOn, 'server-side session files live as long as the absolute lifetime');
$fn = file_get_contents("$root/functions.php");
$gen = substr($fn, strpos($fn, 'function generateUserSessionKey'), 2400);
$ok(substr_count($gen, "'samesite' => 'Strict'") === 2 && !str_contains($gen, "'samesite' => 'None'") && substr_count($gen, "'expires' => 0") === 2, 'the vault-key cookie is SameSite=Strict and lasts for the browser session');
$si = file_get_contents("$root/includes/session_init.php");
$ok(str_contains($si, 'secSessionIniApply(') && !str_contains($si, '2592000'), 'session_init.php uses the shared settings and has no 30-day minimum left');
$ok(str_contains(file_get_contents("$root/login.php"), 'secSessionIniApply('), 'login.php starts its session with the same settings');
$ok(str_contains(file_get_contents("$root/admin/post/settings_security.php"), 'max(60, min(129600'), 'the Settings > Security form accepts 60..129600 minutes');

// ------------------------------------------------------------------ tracking, expiry, revocation
$newSession = function (string $id, int $uid, array $extra = []) use ($db): void {
    session_id($id);
    $_SESSION = array_merge(['logged' => true, 'user_id' => $uid], $extra);
    $_SERVER['HTTP_USER_AGENT'] = 'SecTest/1.0';
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
};
$t0 = 1_800_000_000;

$newSession('secsess_a', $u1);
secSessionStart($db, $u1);
$row = $rows("SELECT * FROM user_sessions WHERE session_user_id=$u1")[0] ?? null;
$ok($row !== null && $row['session_hash'] === hash('sha256', 'secsess_a') && strlen($row['session_hash']) === 64, 'a session row stores the sha256 of the session id, not the id');
$ok($row['session_ip'] === '198.51.100.7' && str_contains((string) $row['session_user_agent'], 'SecTest') && $row['session_revoked_at'] === null, 'with the IP address and user agent');
$ok(!str_contains(json_encode($row), 'secsess_a'), 'the raw session id appears nowhere in the row');
$rowIdA = (int) $_SESSION['sec_row'];
$_SESSION['sec_created'] = $t0; $_SESSION['sec_last'] = $t0;

$ok(secSessionEnforce($db, $t0 + 60) === true, 'an active session passes');
$ok($_SESSION['sec_last'] === $t0 + 60, 'and its idle clock moves forward');
$ok(secSessionEnforce($db, $t0 + 60 + 8 * 3600 - 1) === true, 'just inside 8 idle hours still passes');
$_SESSION['sec_last'] = $t0 + 60;
$r = secSessionEnforce($db, $t0 + 60 + 8 * 3600 + 1);
$ok($r === false && empty($_SESSION['logged']) && !empty($_SESSION['login_notice']), 'after 8 idle hours the session ends and says why');
$ok($one("SELECT session_revoked_reason FROM user_sessions WHERE session_row_id=$rowIdA") === 'idle', 'the row is marked ended: idle');

// absolute lifetime, however active the user is
$newSession('secsess_b', $u1);
secSessionStart($db, $u1);
$_SESSION['sec_created'] = $t0; $_SESSION['sec_last'] = $t0 + 14 * 86400 - 10;
$r = secSessionEnforce($db, $t0 + 14 * 86400 + 5);
$ok($r === false && $one("SELECT session_revoked_reason FROM user_sessions WHERE session_hash='" . hash('sha256', 'secsess_b') . "'") === 'absolute', 'after 14 days a session ends even though it was active a moment ago');

// background polls do not extend the idle timer
$newSession('secsess_c', $u1);
secSessionStart($db, $u1);
$_SESSION['sec_created'] = $t0; $_SESSION['sec_last'] = $t0;
$_GET['_bg'] = '1';
secSessionEnforce($db, $t0 + 3600);
$ok($_SESSION['sec_last'] === $t0, 'a background poll (?_bg=1) does not extend the idle timer');
unset($_GET['_bg']);
$_SERVER['HTTP_ACCEPT'] = 'text/event-stream';
secSessionEnforce($db, $t0 + 3600);
$ok($_SESSION['sec_last'] === $t0, 'neither does an EventSource stream');
unset($_SERVER['HTTP_ACCEPT']);
$_SERVER['HTTP_X_RIVET_BACKGROUND'] = '1';
$ok(secSessionIsBackground(), 'nor an X-Rivet-Background request');
unset($_SERVER['HTTP_X_RIVET_BACKGROUND']);
$ok(!secSessionIsBackground(), 'an ordinary request counts as activity');
$sess_c_row = (int) $_SESSION['sec_row'];
// an old session without clocks starts counting now
$newSession('secsess_legacy', $u1);
secSessionEnforce($db, $t0);
$ok($_SESSION['sec_created'] === $t0 && !empty($_SESSION['logged']), 'a session that predates this feature gets its clocks from its first request instead of being cut off');

// list / revoke one / revoke the others
$newSession('secsess_d', $u1);
secSessionStart($db, $u1);
$d = (int) $_SESSION['sec_row'];
$newSession('secsess_e', $u2);
secSessionStart($db, $u2);
$e = (int) $_SESSION['sec_row'];
$newSession('secsess_d', $u1, ['sec_row' => $d]);
$list = secSessionList($db, $u1);
$ids = array_map(fn($x) => (int) $x['session_row_id'], $list);
$ok(in_array($d, $ids, true) && !in_array($e, $ids, true), 'the list shows the user\'s own live sessions only');
$cur = array_values(array_filter($list, fn($x) => (int) $x['session_row_id'] === $d))[0];
$ok($cur['is_current'] === true, 'and marks the current browser');
$ok(!in_array($rowIdA, $ids, true), 'an ended session is not listed');
$ok(secSessionRevoke($db, $u2, $sess_c_row, 'test') === false, 'one user cannot revoke another user\'s session');
$ok(secSessionRevoke($db, $u1, $sess_c_row, 'revoked_by_user') === true, 'a user can revoke one of their own sessions');
$newSession('secsess_c', $u1, ['sec_row' => $sess_c_row, 'sec_created' => $t0, 'sec_last' => $t0 + 100]);
$ok(secSessionEnforce($db, $t0 + 200) === false && empty($_SESSION['logged']), 'the revoked session is refused on its next request');
$ok(!empty($_SESSION['login_notice']), 'and told it was signed out elsewhere');

$newSession('secsess_f', $u1); secSessionStart($db, $u1); $f = (int) $_SESSION['sec_row'];
$n = secSessionRevokeAll($db, $u1, $d, 'sign_out_everywhere');
$ok($n >= 1 && $one("SELECT session_revoked_at FROM user_sessions WHERE session_row_id=$f") !== null, 'sign out everywhere revokes the other sessions');
$ok($one("SELECT session_revoked_at FROM user_sessions WHERE session_row_id=$d") === null, 'but keeps the one it was told to keep');
$ok($one("SELECT session_revoked_at FROM user_sessions WHERE session_row_id=$e") === null, 'and never touches another user\'s sessions');
secSessionRevokeAll($db, $u1, null, 'sign_out_everywhere');
$ok($one("SELECT session_revoked_at FROM user_sessions WHERE session_row_id=$d") !== null, 'with no exception, every session of the user goes, this browser included');

// session id rotation keeps the row
$newSession('secsess_g', $u1); secSessionStart($db, $u1); $g = (int) $_SESSION['sec_row'];
session_id('secsess_g_rotated');
$_SESSION['sec_created'] = $t0; $_SESSION['sec_last'] = $t0;
secSessionEnforce($db, $t0 + 10);
$ok($one("SELECT session_hash FROM user_sessions WHERE session_row_id=$g") === hash('sha256', 'secsess_g_rotated') && $_SESSION['sec_row'] === $g, 'when the session id is rotated the row follows it');

// password change
$newSession('secsess_h', $u1); secSessionStart($db, $u1); $h = (int) $_SESSION['sec_row'];
$newSession('secsess_i', $u1); secSessionStart($db, $u1); $i = (int) $_SESSION['sec_row'];
$q("INSERT INTO remember_tokens SET remember_token_user_id=$u1, remember_token_token='" . str_repeat('a', 64) . "'");
$q("INSERT INTO remember_tokens SET remember_token_user_id=$u2, remember_token_token='" . str_repeat('b', 64) . "'");
$ended = secSessionsOnPasswordChange($db, $u1, $i);
$ok($ended >= 1 && $one("SELECT session_revoked_at FROM user_sessions WHERE session_row_id=$h") !== null && $one("SELECT session_revoked_at FROM user_sessions WHERE session_row_id=$i") === null, 'a password change revokes every other session and keeps the browser that made it');
$ok((int) $one("SELECT COUNT(*) FROM remember_tokens WHERE remember_token_user_id=$u1") === 0 && (int) $one("SELECT COUNT(*) FROM remember_tokens WHERE remember_token_user_id=$u2") === 1, 'and removes that user\'s remember-me tokens only');
$ended = secSessionsOnPasswordChange($db, $u1, null);
$ok($one("SELECT session_revoked_at FROM user_sessions WHERE session_row_id=$i") !== null, 'an administrator-set password revokes all of that user\'s sessions');

// housekeeping
$q("UPDATE user_sessions SET session_last_seen_at = NOW() - INTERVAL 40 DAY WHERE session_row_id=$h");
secSessionPurge($db);
$ok($one("SELECT COUNT(*) FROM user_sessions WHERE session_row_id=$h") === '0', 'the nightly purge forgets rows idle for over 30 days');

// ------------------------------------------------------------------ wiring
$ac = file_get_contents("$root/includes/auth_check.php");
$ok(str_contains($ac, 'secSessionEnforce($mysqli)') && str_contains($ac, 'secRememberMeMaySkipMfa('), 'auth_check.php enforces the limits and applies the remember-me rule');
$ok(str_contains(file_get_contents("$root/post/logout.php"), 'user_sessions SET session_revoked_at'), 'logging out marks the session row ended');
$ok(str_contains(file_get_contents("$root/cron/cron.php"), 'secSessionPurge($mysqli)'), 'the cron purges old session rows');
$ok(str_contains(file_get_contents("$root/agent/js/ticket_collision_detection.js"), "_bg: '1'"), 'the ticket collision poll is marked as a background request');
$ok(str_contains(file_get_contents("$root/agent/user/user_security.php"), 'name="sign_out_everywhere"') && str_contains(file_get_contents("$root/agent/user/user_security.php"), 'name="revoke_session"'), 'the Account > Security page lists sessions with revoke and sign out everywhere');

// ------------------------------------------------------------------ remember-me against the real auth_check (child process)
$q("DELETE FROM user_roles WHERE role_id IN (95,96)");
$q("INSERT INTO user_roles SET role_id=95, role_name='Sec Sess Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO user_roles SET role_id=96, role_name='Sec Sess Plain', role_is_admin=0, role_type=1");
$q("UPDATE users SET user_role_id=95, user_token='" . $esc(encryptSetting('JBSWY3DPEHPK3PXP')) . "' WHERE user_id=$u1");
$q("UPDATE users SET user_role_id=96, user_token='" . $esc(encryptSetting('JBSWY3DPEHPK3PXP')) . "' WHERE user_id=$u2");
$cookie = bin2hex(random_bytes(16));
$tok = fn(int $uid) => $q("INSERT INTO remember_tokens SET remember_token_user_id=$uid, remember_token_token='" . hash('sha256', $cookie) . "'");
$child = function (int $uid) use ($root, $cookie, $q, $tok): string {
    $q("DELETE FROM remember_tokens");
    $tok($uid);
    $code = '$_SERVER["DOCUMENT_ROOT"]=' . var_export($root, true) . ';$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["REQUEST_URI"]="/agent/x.php";$_COOKIE["rememberme"]=' . var_export($cookie, true) . ';'
        . 'ob_start();require ' . var_export("$root/config.php", true) . ';require ' . var_export("$root/functions.php", true) . ';ob_end_clean();$_SESSION=[];'
        . 'ob_start();require ' . var_export("$root/includes/auth_check.php", true) . ';ob_end_clean();echo "RESTORED";';
    return implode("\n", (function () use ($code) { exec('php -r ' . escapeshellarg($code) . ' 2>&1', $o); return $o; })());
};
$out = $child($u2);
$ok(str_contains($out, 'RESTORED'), 'remember-me silently restores a plain user with MFA (unchanged)');
$out = $child($u1);
$ok(!str_contains($out, 'RESTORED'), 'remember-me does NOT restore an administrator who has MFA: they must sign in again');
secSettingSet($db, 'remember_me_skips_mfa', 1);
$out = $child($u1);
$ok(str_contains($out, 'RESTORED'), 'with "allow remember-me to skip MFA" switched on it does');
secSettingSet($db, 'remember_me_skips_mfa', 0);

// cleanup
$q("DELETE FROM remember_tokens");
$q("DELETE FROM user_sessions");
$q("DELETE FROM users WHERE user_email LIKE 'sec-sess-%'");
$q("DELETE FROM user_roles WHERE role_id IN (95,96)");
$q("DELETE FROM security_settings");
