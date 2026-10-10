<?php
/*
 * Wave 1 security end to end over HTTP (php -S, scratch database, real cookies): the sign-in page, the MFA step with a recovery code,
 * session cookies and the Active sessions row, idle expiry, revocation, remember-me for an admin, the MFA enforcement gate,
 * enrolment with recovery codes shown once, a password change (policy, Argon2id, sessions revoked), and the Sign-in policy settings page.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/security_login_http.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_policy.php";
require_once "$root/plugins/totp/totp.php";

foreach (['user_sessions', 'remember_tokens', 'user_recovery_codes', 'security_settings'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM logs WHERE log_type IN ('Login','User Account')");
$q("DELETE FROM audit_events");
$q("DELETE FROM users WHERE user_email LIKE 'sec-http-%'");
$q("DELETE FROM user_roles WHERE role_id IN (85,86)");
$q("INSERT INTO user_roles SET role_id=85, role_name='Sec HTTP Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO user_roles SET role_id=86, role_name='Sec HTTP Tech', role_is_admin=0, role_type=1");
$q("UPDATE settings SET config_client_portal_enable=0, config_login_key_required=0, config_login_session_lifetime=20160 WHERE company_id=1");

$PW = 'Admin-Horse-Battery-77';
$master = randomString();
$seedA = 'JBSWY3DPEHPK3PXP';
$seedT = 'KRSXG5CTMVRXEZLU';
$mkuser = function (string $tag, int $role, ?string $seed) use ($q, $esc, $one, $PW, $master): int {
    $hash = password_hash($PW, PASSWORD_BCRYPT);   // an OLD-format hash on purpose: the sign-in must upgrade it
    $cipher = setupFirstUserSpecificKey($PW, $master);
    $tok = $seed === null ? '' : encryptSetting($seed);
    $q("INSERT INTO users SET user_name='Http $tag', user_email='sec-http-$tag@example.test', user_password='" . $esc($hash) . "', user_specific_encryption_ciphertext='" . $esc($cipher) . "', user_type=1, user_status=1, user_role_id=$role, user_token='" . $esc($tok) . "'");
    $id = (int) $one("SELECT user_id FROM users WHERE user_email='sec-http-$tag@example.test'");
    $q("INSERT INTO user_settings SET user_id=$id ON DUPLICATE KEY UPDATE user_id=user_id");
    return $id;
};
$admin = $mkuser('admin', 85, $seedA);
$tech  = $mkuser('tech', 86, $seedT);
$plain = $mkuser('plain', 86, null);

$sdir = sys_get_temp_dir() . '/sec_http_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);

/** A browser: its own cookie jar. */
class Browser {
    public string $jar; public array $last = [0, '', '']; public string $base;
    public function __construct(string $base) { $this->base = $base; $this->jar = tempnam(sys_get_temp_dir(), 'secjar_'); register_shutdown_function(fn() => @unlink($this->jar)); }
    public function go(string $method, string $path, array $post = [], array $headers = [], bool $noCookies = false): array {
        $ch = curl_init($this->base . $path);
        $o = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers];
        if (!$noCookies) { $o[CURLOPT_COOKIEJAR] = $this->jar; $o[CURLOPT_COOKIEFILE] = $this->jar; }
        if ($method === 'POST') { $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
        curl_setopt_array($ch, $o);
        $raw = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return $this->last = [$code, substr((string) $raw, $hs), substr((string) $raw, 0, $hs)];
    }
    public function location(): string { return preg_match('/^location:\s*(\S+)/mi', $this->last[2], $m) ? $m[1] : ''; }
    public function cookie(string $name): ?string { foreach (file($this->jar) ?: [] as $l) { $l = rtrim($l, "\r\n"); if ($l === '' ) continue; $l = preg_replace('/^#HttpOnly_/', '', $l); if ($l[0] === '#') continue; $p = explode("\t", $l); if (($p[5] ?? '') === $name) return $p[6] ?? ''; } return null; }
}
$login = function (Browser $b, string $tag, string $pw = null) use ($PW) {
    return $b->go('POST', '/login.php', ['email' => "sec-http-$tag@example.test", 'password' => $pw ?? $PW, 'login' => '1']);
};
$mfaToken = fn(string $body) => preg_match('/name="pending_mfa_token"\s+value="([0-9a-f]+)"/', $body, $m) ? $m[1] : '';
// A TOTP code is single use (replay file per secret). Each sign-in here needs a fresh one, so clear the replay record first.
$code = function (string $seed) { @unlink(sys_get_temp_dir() . '/rivetit_totp_replay/' . hash('sha256', $seed)); return TokenAuth6238::getTokenCode($seed); };

// ============================================================ password sign-in, MFA step, recovery code
$b = new Browser($base);
[$c, $body] = $b->go('GET', '/login.php');
$ok($c === 200 && str_contains($body, 'name="login"'), 'the sign-in page renders');
[$c, $body] = $login($b, 'tech');
$tok = $mfaToken($body);
$ok($c === 200 && $tok !== '' && str_contains($body, "name='recovery_code'") && str_contains($body, 'Use a recovery code'), 'a user with MFA gets the code step, with a recovery code option');
$ok(str_starts_with($one("SELECT user_password FROM users WHERE user_id=$tech"), '$argon2id$'), 'the correct password upgraded the old bcrypt hash to Argon2id');
$ok(password_verify($PW, $one("SELECT user_password FROM users WHERE user_id=$tech")), 'and the same password still works');
[$c, $body] = $b->go('POST', '/login.php', ['mfa_login' => '1', 'pending_mfa_token' => $tok, 'current_code' => '111111', 'recovery_code' => '']);
$ok($c !== 302 && str_contains($body, 'valid 2FA code or recovery code'), 'a wrong authenticator code is refused');
$tok = $mfaToken($body);   // every attempt issues a fresh pending token
[$c, $body] = $b->go('POST', '/login.php', ['mfa_login' => '1', 'pending_mfa_token' => $tok, 'current_code' => '', 'recovery_code' => 'abcde-fghjk']);
$ok($c !== 302 && str_contains($body, 'valid 2FA code or recovery code'), 'a wrong recovery code is refused');
$tok = $mfaToken($body);

// make recovery codes for tech and sign in with one
$codes = secRecoveryCodesGenerate($db, $tech);
[$c, $body] = $b->go('POST', '/login.php', ['mfa_login' => '1', 'pending_mfa_token' => $tok, 'current_code' => '', 'recovery_code' => strtoupper($codes[0])]);
$ok($c === 302 && str_contains($b->location(), 'agent/'), 'a recovery code signs the user in');
$ok(secRecoveryCodesRemaining($db, $tech) === 9, 'and is used up');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='auth.recovery_code_used' AND actor_user_id=$tech") === 1 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_type='Login' AND log_action='MFA Recovery Code' AND log_user_id=$tech") === 1, 'the use is audited (audit event and sign-in log)');

$sessId = $b->cookie('PHPSESSID');
$ok($sessId !== null && strlen($sessId) > 20, 'a session cookie was issued');
$row = $rows("SELECT * FROM user_sessions WHERE session_user_id=$tech")[0] ?? null;
$ok($row && $row['session_hash'] === hash('sha256', $sessId) && $row['session_revoked_at'] === null, 'the Active sessions row holds the hash of the session id');
[$c, $body] = $b->go('GET', '/agent/user/user_security.php');
$ok($c === 200 && str_contains($body, 'Active sessions') && str_contains($body, 'This browser') && str_contains($body, 'Recovery codes') && str_contains($body, '9 left'), 'Account > Security shows the session list and the recovery code count');
$vault = $b->cookie('user_encryption_session_key');
$ok($vault !== null && $vault !== '', 'the vault-key cookie was issued at sign-in');
$jar = file_get_contents($b->jar);
$ok(preg_match('/user_encryption_session_key\t/', $jar) === 1, 'present in the jar');
[$c, $body] = $b->go('GET', '/agent/user/user_security.php');
$ok($c === 200 && !str_contains($body, $codes[1]), 'recovery codes are never shown again on the page');

// cookie attributes: use a fresh sign-in and read the raw Set-Cookie lines
$b2 = new Browser($base);
[$c, $body, $hdr] = $login($b2, 'plain');
$sc = implode("\n", preg_grep('/^set-cookie:/i', explode("\r\n", $hdr)));
$ok($c === 302 && stripos($sc, 'PHPSESSID=') !== false, 'a user without MFA signs straight in');
$ok(preg_match('/PHPSESSID=[^;]+;[^\n]*HttpOnly/i', $sc) === 1 && preg_match('/PHPSESSID=[^;]+;[^\n]*SameSite=Lax/i', $sc) === 1, 'the session cookie is HttpOnly and SameSite=Lax');
$ok(preg_match('/PHPSESSID=[^;]+;[^\n]*(expires|max-age)=/i', $sc) === 1, 'with a lifetime (the absolute session length)');
$ok(preg_match('/user_encryption_session_key=[^;]+;[^\n]*SameSite=Strict/i', $sc) === 1 && preg_match('/user_encryption_session_key=[^;]+;[^\n]*HttpOnly/i', $sc) === 1, 'the vault-key cookie is HttpOnly and SameSite=Strict');
$vaultLine = implode('', preg_grep('/user_encryption_session_key=/i', explode("\n", $sc)));
$ok(stripos($vaultLine, 'expires=') === false && stripos($vaultLine, 'max-age=') === false, 'and has no expiry: it ends with the browser session');
$ok(str_starts_with($one("SELECT user_password FROM users WHERE user_id=$plain"), '$argon2id$'), 'the plain user\'s hash was upgraded too');

// ============================================================ idle expiry, revocation
$sessFile = "$sdir/sess_" . $b2->cookie('PHPSESSID');
$ok(is_file($sessFile) && str_contains(file_get_contents($sessFile), 'sec_created'), 'the session file carries the idle / absolute clocks');
[$c] = $b2->go('GET', '/agent/user/user_security.php');
$ok($c === 200, 'the session is valid');
secSettingSet($db, 'session_idle_minutes', 30);
$data = file_get_contents($sessFile);
file_put_contents($sessFile, preg_replace('/sec_last\|i:\d+;/', 'sec_last|i:' . (time() - 31 * 60) . ';', $data));
[$c] = $b2->go('GET', '/agent/user/user_security.php');
$ok($c === 302 && str_contains($b2->location(), 'login.php'), 'after the idle timeout the next request goes to the sign-in page');
[$c, $body] = $b2->go('GET', '/login.php');
$ok(str_contains($body, 'inactivity'), 'which says why');
$ok($one("SELECT session_revoked_reason FROM user_sessions WHERE session_user_id=$plain ORDER BY session_row_id LIMIT 1") === 'idle', 'the session row is marked ended: idle');
secSettingSet($db, 'session_idle_minutes', 480);

$b3 = new Browser($base);
$login($b3, 'plain');
[$c] = $b3->go('GET', '/agent/user/user_security.php');
$ok($c === 200, 'a second sign-in works');
$q("UPDATE user_sessions SET session_revoked_at=NOW(), session_revoked_reason='revoked_by_user' WHERE session_user_id=$plain AND session_revoked_at IS NULL");
[$c] = $b3->go('GET', '/agent/user/user_security.php');
$ok($c === 302 && str_contains($b3->location(), 'login.php'), 'a revoked session is signed out on its next request');

// revoke another browser from the profile page, then sign out everywhere
$bx = new Browser($base); $login($bx, 'plain');
$by = new Browser($base); $login($by, 'plain');
[$c, $body] = $bx->go('GET', '/agent/user/user_security.php');
preg_match_all('/name="session_row_id" value="(\d+)"/', $body, $m);
$ok(count($m[1]) === 1, 'the list offers a Sign out button for the other browser only');
preg_match('/name="csrf_token" value="([^"]+)"/', $body, $mc);
$csrfX = $mc[1];
$bx->go('POST', '/agent/user/post.php', ['csrf_token' => $csrfX, 'session_row_id' => $m[1][0], 'revoke_session' => '1'], ['Referer: ' . $base . '/agent/user/user_security.php']);
[$c] = $by->go('GET', '/agent/user/user_security.php');
$ok($c === 302 && str_contains($by->location(), 'login.php'), 'the browser revoked from the profile page is signed out');
[$c] = $bx->go('GET', '/agent/user/user_security.php');
$ok($c === 200, 'while the browser that did it stays signed in');
$bx->go('POST', '/agent/user/post.php', ['csrf_token' => $csrfX, 'sign_out_everywhere' => '1'], ['Referer: ' . $base . '/agent/user/user_security.php']);
[$c] = $bx->go('GET', '/agent/user/user_security.php');
$ok($c === 302 && str_contains($bx->location(), 'login.php'), 'sign out everywhere ends this browser too');

// ============================================================ password change: policy, hash, sessions
$bp = new Browser($base); $login($bp, 'plain');
$other = new Browser($base); $login($other, 'plain');
[$c, $body] = $bp->go('GET', '/agent/user/user_security.php');
preg_match('/name="csrf_token" value="([^"]+)"/', $body, $mc);
$ref = ['Referer: ' . $base . '/agent/user/user_security.php'];
$bp->go('POST', '/agent/user/post.php', ['csrf_token' => $mc[1], 'edit_your_user_password' => '1', 'current_password' => $PW, 'new_password' => 'short-one'], $ref);
$ok(password_verify($PW, $one("SELECT user_password FROM users WHERE user_id=$plain")), 'a new password under 12 characters is refused (the old one still works)');
$bp->go('POST', '/agent/user/post.php', ['csrf_token' => $mc[1], 'edit_your_user_password' => '1', 'current_password' => $PW, 'new_password' => 'sec-http-plain@example.test'], $ref);
$ok(password_verify($PW, $one("SELECT user_password FROM users WHERE user_id=$plain")), 'and so is the email address');
$bp->go('POST', '/agent/user/post.php', ['csrf_token' => $mc[1], 'edit_your_user_password' => '1', 'current_password' => $PW, 'new_password' => 'A-Brand-New-Passphrase-42'], $ref);
$newHash = $one("SELECT user_password FROM users WHERE user_id=$plain");
$ok(password_verify('A-Brand-New-Passphrase-42', $newHash) && str_starts_with($newHash, '$argon2id$'), 'a good new password is saved as Argon2id');
[$c] = $other->go('GET', '/agent/user/user_security.php');
$ok($c === 302 && str_contains($other->location(), 'login.php'), 'the password change signed the other browser out');
$ok((int) $one("SELECT COUNT(*) FROM user_sessions WHERE session_user_id=$plain AND session_revoked_at IS NULL") === 0, 'no live session is left for the user');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='auth.password_changed' AND actor_user_id=$plain") === 1, 'the change is in the audit log');
$q("UPDATE users SET user_password='" . $esc(password_hash($PW, PASSWORD_BCRYPT)) . "' WHERE user_id=$plain");

// ============================================================ remember-me
$q("DELETE FROM user_sessions");
foreach (['admin' => $seedA, 'tech' => $seedT] as $tag => $seed) {
    $br = new Browser($base);
    [$c, $body] = $login($br, $tag);
    $tk = $mfaToken($body);
    $br->go('POST', '/login.php', ['mfa_login' => '1', 'pending_mfa_token' => $tk, 'current_code' => (string) $code($seed), 'remember_me' => '1']);
    $rm = $br->cookie('rememberme');
    $ok($rm !== null && strlen($rm) > 40, "$tag: the sign-in with \"Remember Me\" set a remember-me cookie");
    $ok((int) $one("SELECT COUNT(*) FROM remember_tokens WHERE remember_token_user_id=" . ($tag === 'admin' ? $admin : $tech)) === 1, "$tag: and stored its hash");
    // a new browser holding only the remember-me cookie
    $fresh = new Browser($base);
    file_put_contents($fresh->jar, "# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t" . (time() + 86400) . "\trememberme\t$rm\n");
    [$c] = $fresh->go('GET', '/agent/user/user_security.php');
    if ($tag === 'admin') {
        $ok($c === 302 && str_contains($fresh->location(), 'login.php'), 'admin: the remember-me cookie alone does NOT sign an administrator with MFA in');
    } else {
        $ok($c === 200, 'tech: the remember-me cookie restores a plain user (unchanged behaviour)');
    }
}
secSettingSet($db, 'remember_me_skips_mfa', 1);
$br = new Browser($base);
[$c, $body] = $login($br, 'admin');
$br->go('POST', '/login.php', ['mfa_login' => '1', 'pending_mfa_token' => $mfaToken($body), 'current_code' => (string) $code($seedA), 'remember_me' => '1']);
$rm = $br->cookie('rememberme');
$fresh = new Browser($base);
file_put_contents($fresh->jar, "# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t" . (time() + 86400) . "\trememberme\t$rm\n");
[$c] = $fresh->go('GET', '/agent/user/user_security.php');
$ok($c === 200, 'admin: with "allow remember-me to skip MFA" on, the cookie works');
secSettingSet($db, 'remember_me_skips_mfa', 0);
// at the password step, the remember-me cookie must not skip the code for an administrator
$q("DELETE FROM remember_tokens");
$br = new Browser($base);
[$c, $body] = $login($br, 'admin');
$br->go('POST', '/login.php', ['mfa_login' => '1', 'pending_mfa_token' => $mfaToken($body), 'current_code' => (string) $code($seedA), 'remember_me' => '1']);
[$c, $body] = $login($br, 'admin');
$ok($c === 200 && $mfaToken($body) !== '', 'admin: signing in with a remember-me cookie still asks for the code');
$brt = new Browser($base);
[$c, $body] = $login($brt, 'tech');
$brt->go('POST', '/login.php', ['mfa_login' => '1', 'pending_mfa_token' => $mfaToken($body), 'current_code' => (string) $code($seedT), 'remember_me' => '1']);
[$c, $body] = $login($brt, 'tech');
$ok($c === 302, 'tech: with a remember-me cookie the code is skipped (unchanged for a plain user)');

// ============================================================ MFA enforcement gate and enrolment
$q("DELETE FROM user_sessions"); $q("DELETE FROM remember_tokens");
secSettingSet($db, 'mfa_policy', 'all'); secSettingSet($db, 'mfa_grace_days', 0); secSettingSet($db, 'mfa_policy_since', date('Y-m-d H:i:s', time() - 86400));
$bg = new Browser($base);
[$c, $body, $hdr] = $login($bg, 'plain');
$ok($c === 302 && str_contains($bg->location(), 'mfa_enforcement.php'), 'policy all, no grace: a user without MFA lands on the enrolment page');
[$c] = $bg->go('GET', '/agent/tickets.php');
$ok($c === 302 && str_contains($bg->location(), 'mfa_enforcement.php'), 'and every other agent page sends them back to it');
[$c] = $bg->go('GET', '/agent/user/user_security.php');
$ok($c === 200, 'while the account pages stay open');
[$c, $body] = $bg->go('GET', '/agent/user/mfa_enforcement.php');
$ok($c === 200, 'the enrolment page renders');
$sf = "$sdir/sess_" . $bg->cookie('PHPSESSID');
preg_match('/mfa_token\|s:\d+:"([A-Z2-7]+)"/', (string) file_get_contents($sf), $ms);
$newSeed = $ms[1] ?? '';
$ok($newSeed !== '', 'the enrolment page created a secret in the session');
preg_match('/name="csrf_token" value="([^"]+)"/', $body, $mc);
$bg->go('POST', '/agent/user/post.php', ['csrf_token' => $mc[1], 'enable_mfa' => '1', 'verify_code' => (string) $code($newSeed)], ['Referer: ' . $base . '/agent/user/mfa_enforcement.php']);
$stored = $one("SELECT user_token FROM users WHERE user_id=$plain");
$ok(str_starts_with($stored, 'ENC2:') && decryptSetting($stored) === $newSeed, 'enrolling stores the TOTP seed wrapped, never in plaintext');
$ok(secRecoveryCodesRemaining($db, $plain) === 10, 'and creates ten recovery codes');
$ok(str_contains($bg->location(), 'user_security.php'), 'then goes to the Security page');
[$c, $body] = $bg->go('GET', '/agent/user/user_security.php');
$ok(str_contains($body, 'Save these codes now') && preg_match_all('/\b[a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5}\b/', $body) >= 10, 'where the ten codes are shown once');
[$c, $body] = $bg->go('GET', '/agent/user/user_security.php');
$ok(!str_contains($body, 'Save these codes now') && preg_match_all('/\b[a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5}\b/', $body) === 0, 'and never again');
[$c] = $bg->go('GET', '/agent/tickets.php');
$ok(!str_contains($bg->location(), 'mfa_enforcement.php'), 'an enrolled user is no longer held on the enrolment page');
// regenerate needs the password
$preCodes = $rows("SELECT code_hash FROM user_recovery_codes WHERE code_user_id=$plain");
preg_match('/name="csrf_token" value="([^"]+)"/', $body, $mc);
$bg->go('POST', '/agent/user/post.php', ['csrf_token' => $mc[1], 'regenerate_recovery_codes' => '1', 'current_password' => 'wrong-password'], $ref);
$ok($rows("SELECT code_hash FROM user_recovery_codes WHERE code_user_id=$plain") === $preCodes, 'regenerating with the wrong password changes nothing');
$bg->go('POST', '/agent/user/post.php', ['csrf_token' => $mc[1], 'regenerate_recovery_codes' => '1', 'current_password' => $PW], $ref);
$ok($rows("SELECT code_hash FROM user_recovery_codes WHERE code_user_id=$plain") !== $preCodes && secRecoveryCodesRemaining($db, $plain) === 10, 'with the right password it makes a new set');

// ============================================================ the settings page
secSettingSet($db, 'mfa_policy', 'off');
$ba = new Browser($base);
[$c, $body] = $login($ba, 'admin');
$ba->go('POST', '/login.php', ['mfa_login' => '1', 'pending_mfa_token' => $mfaToken($body), 'current_code' => (string) $code($seedA)]);
[$c, $body] = $ba->go('GET', '/admin/settings_security.php');
$ok($c === 200 && str_contains($body, 'Sign-in policy') && str_contains($body, 'name="mfa_policy"') && str_contains($body, 'name="vault_reveal_limit"') && str_contains($body, 'name="session_idle_minutes"') && str_contains($body, 'name="password_hibp_check"'), 'Admin > Settings > Security shows the Sign-in policy card');
preg_match('/name="csrf_token" value="([^"]+)"/', $body, $mc);
$ba->go('POST', '/admin/post.php', ['csrf_token' => $mc[1], 'edit_security_policy' => '1', 'mfa_policy' => 'admins', 'mfa_grace_days' => '5', 'password_min_length' => '14', 'password_hibp_check' => '1', 'session_idle_minutes' => '120', 'vault_stepup_minutes' => '5', 'vault_reveal_limit' => '10'], ['Referer: ' . $base . '/admin/settings_security.php']);
secSettingsAll($db, true);
$ok(secSetting('mfa_policy') === 'admins' && secSettingInt('mfa_grace_days') === 5 && secSettingInt('password_min_length') === 14 && secSetting('password_hibp_check') === '1' && secSettingInt('session_idle_minutes') === 120 && secSettingInt('vault_stepup_minutes') === 5 && secSettingInt('vault_reveal_limit') === 10 && secSetting('remember_me_skips_mfa') === '0', 'the form saved every setting');
$ok(secSetting('mfa_policy_since') !== '' && abs(strtotime(secSetting('mfa_policy_since')) - time()) < 86400   /* app clock is company-local time */, 'switching the policy on starts the grace period now');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='settings.security_policy_changed'") === 1, 'and the change is audited');
$ba->go('POST', '/admin/post.php', ['csrf_token' => $mc[1], 'edit_security_policy' => '1', 'mfa_policy' => 'bogus', 'mfa_grace_days' => '-3', 'password_min_length' => '2', 'session_idle_minutes' => '0', 'vault_stepup_minutes' => '99999', 'vault_reveal_limit' => '0'], ['Referer: ' . $base . '/admin/settings_security.php']);
secSettingsAll($db, true);
$ok(secSetting('mfa_policy') === 'off' && secSettingInt('mfa_grace_days') === 0 && secSettingInt('password_min_length') === 8 && secSettingInt('session_idle_minutes') === 5 && secSettingInt('vault_stepup_minutes') === 1440 && secSettingInt('vault_reveal_limit') === 1, 'out-of-range values are clamped');
// the login key secret is stored wrapped
$ba->go('POST', '/admin/post.php', ['csrf_token' => $mc[1], 'edit_security_settings' => '1', 'config_login_message' => '', 'config_login_key_required' => '0', 'config_login_key_secret' => 'MYKEY123', 'config_login_remember_me_expire' => '30', 'config_login_session_lifetime' => '480', 'config_log_retention' => '0'], ['Referer: ' . $base . '/admin/settings_security.php']);
$stored = $one("SELECT config_login_key_secret FROM settings WHERE company_id=1");
$ok(str_starts_with($stored, 'ENC2:') && decryptSetting($stored) === 'MYKEY123', 'the login key secret is saved wrapped');
$ok((int) $one("SELECT config_login_session_lifetime FROM settings WHERE company_id=1") === 480, 'and a 480 minute session lifetime is accepted (no 30-day minimum)');
[$c, $body] = $ba->go('GET', '/admin/settings_security.php');
$ok(str_contains($body, 'value="MYKEY123"'), 'the login key secret shows decrypted on the settings page');

// cleanup
foreach (['user_sessions', 'remember_tokens', 'user_recovery_codes', 'security_settings'] as $t) { $q("DELETE FROM $t"); }
$q("UPDATE settings SET config_login_key_secret='', config_login_session_lifetime=20160 WHERE company_id=1");
$q("DELETE FROM users WHERE user_email LIKE 'sec-http-%'");
$q("DELETE FROM user_settings WHERE user_id IN ($admin,$tech,$plain)");
$q("DELETE FROM user_roles WHERE role_id IN (85,86)");
