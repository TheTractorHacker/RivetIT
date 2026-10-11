<?php
/*
 * RivetCore\Crypto adoption, stage 4: the credential vault on the v3 format, behind settings.config_vault_v3_enabled.
 *   - flag off: nothing changes (legacy V2 wrap, legacy fields, reveal works); prepare / enable refuse without their prerequisites
 *   - rewrap --vault: legacy V2-era credentials (username, password, OTP, versions) become v3 bound to credential:<id>:<field>
 *   - login: a legacy wrap is verified and replaced by a vw3 wrap, a vw3 wrap opens, a wrong password opens nothing
 *   - password change rewraps the data key; an administrator's reset makes a fresh wrap from the session key
 *   - reveal (function and the real credential_reveal.php endpoint over HTTP), share link creation, writes finalised to v3
 *   - the mobile token carries the data key; a user not yet migrated and a legacy session keep working
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/crypto_vault.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_policy.php";
require_once "$root/includes/vault_reveal.php";
ob_start();

use ITFlow\Crypto\KeyAdmin;
use ITFlow\Crypto\RewrapService;
use ITFlow\Crypto\SettingsCrypto;
use ITFlow\Crypto\VaultV3;
use RivetCore\Crypto\VaultCipher;
use RivetCore\Crypto\VaultKeyWrap;

$GLOBALS['config_vault_argon_memory_kib'] = 19456;   // the smallest parameters Core accepts: fast tests, same code path
$GLOBALS['config_vault_argon_ops'] = 2;
$dir = sys_get_temp_dir() . '/crypto-vault-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$kf = "$dir/keys.json";
$GLOBALS['config_keyfile'] = '';
SettingsCrypto::reset();

$q("DELETE FROM audit_events WHERE event_type LIKE 'vault.%'");
foreach ([711, 712] as $id) { $q("DELETE FROM users WHERE user_id=$id"); }
$q("DELETE FROM credential_versions WHERE version_credential_id BETWEEN 7100 AND 7199");
$q("DELETE FROM credentials WHERE credential_id BETWEEN 7100 AND 7199");
$q("DELETE FROM shared_items WHERE item_related_id BETWEEN 7100 AND 7199");
$q("DELETE FROM api_tokens WHERE token_user_id=711");
$q("DELETE FROM user_roles WHERE role_id=97"); $q("INSERT INTO user_roles SET role_id=97, role_name='Crypto Vault Admin', role_is_admin=1, role_type=1");
$q("DELETE FROM modules WHERE module_name='module_credential'"); $q("INSERT INTO modules SET module_id=71, module_name='module_credential'");
$q("UPDATE settings SET config_vault_v3_enabled=0, config_vault_dek_wrap=NULL, config_vault_canonical_key=NULL WHERE company_id=1");
$pw = 'Vault-Pass-Phrase-77!';
$master = randomString();
$mkuser = function (int $id, string $tag, string $wrap) use ($q, $esc, $pw) {
    $q("INSERT INTO users SET user_id=$id, user_name='CV $tag', user_email='crypto-vault-$tag@example.test', user_password='" . $esc(secPasswordHash($pw)) . "', user_type=1, user_status=1, user_role_id=97, user_specific_encryption_ciphertext='" . $esc($wrap) . "'");
    $q("INSERT INTO user_settings SET user_id=$id ON DUPLICATE KEY UPDATE user_id=user_id");
};
$mkuser(711, 'one', setupFirstUserSpecificKey($pw, $master));
$mkuser(712, 'two', setupFirstUserSpecificKey($pw, $master));
$q("INSERT INTO clients SET client_id=8101, client_name='CV Dept' ON DUPLICATE KEY UPDATE client_name='CV Dept'");

// legacy credentials, exactly as the pre-v3 app wrote them
$mk = function (int $id, string $name, string $user, string $pass, ?string $otp = null) use ($q, $esc, $master) {
    $u = $esc(encryptCredentialEntryWithKey($user, $master));
    $p = $esc(encryptCredentialEntryWithKey($pass, $master));
    $o = $otp === null ? 'NULL' : "'" . $esc(str_starts_with($otp, 'plain:') ? substr($otp, 6) : 'enc:' . encryptCredentialEntryWithKey($otp, $master)) . "'";
    $q("INSERT INTO credentials SET credential_id=$id, credential_name='$name', credential_client_id=8101, credential_username='$u', credential_password='$p', credential_otp_secret=$o");
};
$mk(7101, 'cv-a', 'alice@example.test', 'S3cret-AAA!', 'JBSWY3DPEHPK3PXP');
$mk(7102, 'cv-b', 'bob@example.test', 'S3cret-BBB!', 'plain:KRSXG5CTMVRXEZLU');
$mk(7103, 'cv-c', 'carol@example.test', 'S3cret-CCC!');
$q("INSERT INTO credential_versions SET version_id=7150, version_credential_id=7101, version_changed_by=711, version_changed_by_name='x', version_previous_username_enc='" . $esc(encryptCredentialEntryWithKey('old-alice', $master)) . "', version_previous_password_enc='" . $esc(encryptCredentialEntryWithKey('old-pass', $master)) . "'");

// the session of a signed-in user, as generateUserSessionKey() lays it out
$sk = randomString(); $siv = randomString();
$legacySession = ['user_encryption_session_ciphertext' => openssl_encrypt($master, 'aes-128-cbc', $sk, 0, $siv), 'user_encryption_session_iv' => $siv, 'vault_stepup_at' => time()];
$_COOKIE['user_encryption_session_key'] = $sk;
$_SESSION = $legacySession;
$ACTOR = ['id' => 711, 'name' => 'CV one', 'is_admin' => true, 'perm' => 3, 'has_password' => true];
$reveal = fn(int $id, string $field = 'password') => vaultReveal($db, $_SESSION, $ACTOR, ['credential_id' => $id, 'field' => $field, 'mode' => 'reveal']);

// ------------------------------------------------------------------ flag off, no key file: nothing changes
$ok(!VaultV3::enabled($db), 'the flag is off by default');
$r = $reveal(7101);
$ok($r['status'] === 200 && $r['body']['value'] === 'S3cret-AAA!', 'flag off: a legacy credential reveals as always');
$keys = VaultV3::resolveLogin($db, 711, (string) $one("SELECT user_specific_encryption_ciphertext FROM users WHERE user_id=711"), $pw);
$ok($keys['master'] === $master && $keys['dek'] === null && str_starts_with((string) $one("SELECT user_specific_encryption_ciphertext FROM users WHERE user_id=711"), 'V2:'), 'flag off: login unwraps the legacy master key and leaves the V2 wrap alone');
$ok(vaultLoginMasterKey($db, 711, $one("SELECT user_specific_encryption_ciphertext FROM users WHERE user_id=711"), 'wrong-password') === false || vaultLoginMasterKey($db, 711, $one("SELECT user_specific_encryption_ciphertext FROM users WHERE user_id=711"), 'wrong-password') === '' , 'a wrong password gives no master key');

// ------------------------------------------------------------------ prerequisites
$p = VaultV3::prepare($db);
$ok($p['status'] === 'refused', 'prepare refuses without the key file');
KeyAdmin::generate($kf);
$GLOBALS['config_keyfile'] = $kf; SettingsCrypto::reset(); VaultV3::reset();
$p = VaultV3::prepare($db);
$ok($p['status'] === 'refused' && str_contains($p['message'], 'canonical'), 'prepare refuses without the canonical vault key');
$threw = false; try { VaultV3::setFlag($db, true); } catch (\RuntimeException $e) { $threw = true; }
$ok($threw && !VaultV3::enabled($db), 'the flag cannot be switched on before the data key exists');
setCanonicalVaultKey($db, $master);
$ok(str_starts_with((string) $one("SELECT config_vault_canonical_key FROM settings WHERE company_id=1"), 'v3:') && getCanonicalVaultKey($db) === $master, 'the canonical key is stored v3 with its own context and reads back');
$p = VaultV3::prepare($db);
$ok($p['status'] === 'created' && VaultV3::instanceDek($db) !== null && strlen(VaultV3::instanceDek($db)) === 32, 'prepare creates the 32 byte data key, wrapped by the key file');
$ok(VaultV3::prepare($db)['status'] === 'exists', 'and is idempotent');
$stored = (string) $one("SELECT config_vault_dek_wrap FROM settings WHERE company_id=1");
$ok(str_starts_with($stored, 'v3:') && !str_contains($stored, base64_encode(VaultV3::instanceDek($db))), 'the stored wrap does not contain the key');
$ok(!VaultV3::enabled($db), 'preparing does not switch the flag on');

// ------------------------------------------------------------------ the conversion (legacy -> v3)
$svc = new RewrapService($db, 711, "$dir/state");
$GLOBALS['config_settings_enc_key'] = $GLOBALS['config_settings_enc_key'];
$wrong = (function () use ($db, $svc) {   // a canonical key that is not the one the credentials were written with
    $orig = getCanonicalVaultKey($db);
    setCanonicalVaultKey($db, 'WrongWrongWrong1');
    try { VaultV3::addRewrapJobs(new RewrapService($db, 1), $db); $r = false; } catch (\RuntimeException $e) { $r = str_contains($e->getMessage(), 'canonical'); }
    setCanonicalVaultKey($db, $orig);
    return $r;
})();
$ok($wrong, 'a canonical key that opens none of the stored passwords stops the bulk run before it changes anything');
$svc2 = new RewrapService($db, 711, "$dir/state");
VaultV3::addRewrapJobs($svc2, $db);
$dry = $svc2->run('credentials', true);
$n = 0; foreach ($dry as $r) { $n += $r->rewrapped; }
$ok($n === 8 && str_starts_with((string) $one("SELECT credential_password FROM credentials WHERE credential_id=7101"), 'v3:') === false, "dry run: $n field(s) would convert (3 usernames... 3 passwords, 2 OTP), nothing written");
foreach ([1, 2] as $i) { $reps = $svc2->run('credentials'); }
$svc2->run('credential_versions');
$bad = 0; foreach ($reps as $r) { $bad += $r->failed + $r->conflicts; }
$ok($bad === 0, 'the conversion run is clean');
$dek = VaultV3::instanceDek($db);
$c = new VaultCipher($dek);
$pwv = (string) $one("SELECT credential_password FROM credentials WHERE credential_id=7101");
$ok(str_starts_with($pwv, 'v3:') && $c->open($pwv, 7101, 'password') === 'S3cret-AAA!', 'a legacy password is now v3 and opens with credential:7101:password');
$ok($c->open((string) $one("SELECT credential_username FROM credentials WHERE credential_id=7101"), 7101, 'username') === 'alice@example.test', 'the username too');
$ok($c->open((string) $one("SELECT credential_otp_secret FROM credentials WHERE credential_id=7101"), 7101, 'otp') === 'JBSWY3DPEHPK3PXP' && $c->open((string) $one("SELECT credential_otp_secret FROM credentials WHERE credential_id=7102"), 7102, 'otp') === 'KRSXG5CTMVRXEZLU', 'OTP secrets, both the enc: form and the unprefixed legacy form');
$ok($c->open((string) $one("SELECT version_previous_password_enc FROM credential_versions WHERE version_id=7150"), 'version-7150', 'previous_password') === 'old-pass', 'version snapshots are converted under their own context');
$failedMove = false;
try { $c->open($pwv, 7102, 'password'); } catch (\Throwable $e) { $failedMove = true; }
$ok($failedMove, 'a v3 field moved to another credential does not open (AAD)');
$ok($one("SELECT credential_password FROM credentials WHERE credential_id=7103") !== '' && VaultCipher::isV3((string) $one("SELECT credential_password FROM credentials WHERE credential_id=7103")) && $one("SELECT credential_otp_secret FROM credentials WHERE credential_id=7103") === null, 'a credential without OTP stays without OTP');
$again = $svc2->run('credentials');
$ok(array_sum(array_map(fn ($r) => $r->rewrapped, $again)) === 0, 'a second conversion run changes nothing');

// ------------------------------------------------------------------ flag on: login, sessions, reveal
VaultV3::setFlag($db, true);
$ok(VaultV3::enabled($db), 'the flag is on');
$stored711 = (string) $one("SELECT user_specific_encryption_ciphertext FROM users WHERE user_id=711");
$k = VaultV3::resolveLogin($db, 711, $stored711, $pw);
$w = (string) $one("SELECT user_specific_encryption_ciphertext FROM users WHERE user_id=711");
$ok($k['master'] === $master && $k['dek'] === $dek && str_starts_with($w, 'vw3:'), 'login with a legacy wrap: master and data key returned, the wrap is replaced by a vw3 wrap');
$k2 = VaultV3::resolveLogin($db, 711, $w, $pw);
$ok($k2['dek'] === $dek && $k2['master'] === $master, 'login with the vw3 wrap opens it (the legacy key comes from the canonical row)');
$k3 = VaultV3::resolveLogin($db, 711, $w, 'wrong-password');
$ok($k3['dek'] === null && $k3['master'] === null, 'a wrong password opens nothing');
$ok(VaultKeyWrap::isWrap($w) && !str_contains($w, base64_encode($dek)) && vaultLoginMasterKey($db, 711, $w, $pw) === $master, 'vaultLoginMasterKey is the same on a vw3 wrap');
$ok(decryptUserSpecificKey($w, $pw) === false, 'the legacy unwrap function never "succeeds" on a vw3 wrap');
$q("UPDATE settings SET config_vault_canonical_key=NULL WHERE company_id=1");
$k4 = VaultV3::resolveLogin($db, 712, (string) $one("SELECT user_specific_encryption_ciphertext FROM users WHERE user_id=712"), $pw);
$ok(str_starts_with((string) $one("SELECT user_specific_encryption_ciphertext FROM users WHERE user_id=712"), 'V2:') && $k4['master'] === $master, 'without the canonical key the legacy wrap is NOT replaced (the legacy key must stay recoverable)');
setCanonicalVaultKey($db, $master);

// reveal: a session holding both keys, a legacy-only session, a wrong data key
$_SESSION = $legacySession; VaultV3::storeSessionDek($dek, $sk);
$sessionBoth = $_SESSION;
$r = $reveal(7101);
$ok($r['status'] === 200 && $r['body']['value'] === 'S3cret-AAA!', 'flag on: a v3 credential reveals with the data key in the session');
$ok($reveal(7101, 'username')['body']['value'] === 'alice@example.test', 'and its username');
$_SESSION = $legacySession;
$r = $reveal(7101);
$ok($r['status'] === 409 && $r['body']['error'] === 'vault_locked', 'a session without the data key reports a locked vault, never an empty value');
$_SESSION = $legacySession; VaultV3::storeSessionDek(random_bytes(32), $sk);
$r = $reveal(7101);
$ok($r['status'] === 409 && $r['body']['error'] === 'decrypt_failed', 'a wrong data key reports a decrypt failure');
$_SESSION = $sessionBoth;
$ok(decryptCredentialEntry($pwv, 7101, 'password') === 'S3cret-AAA!' && decryptCredentialEntry($pwv, 7102, 'password') === false && decryptCredentialEntry($pwv) === false, 'decryptCredentialEntry needs the right id and field for a v3 value');
$ok(decryptOtpSecret((string) $one("SELECT credential_otp_secret FROM credentials WHERE credential_id=7101"), 7101) === 'JBSWY3DPEHPK3PXP', 'decryptOtpSecret opens a v3 OTP secret');

// writes are finalised to v3
$legacyUser = encryptCredentialEntry('new-user@example.test');
$legacyPass = encryptCredentialEntry('New-Pass-1!');
$q("INSERT INTO credentials SET credential_id=7110, credential_name='cv-new', credential_client_id=8101, credential_username='" . $esc($legacyUser) . "', credential_password='" . $esc($legacyPass) . "', credential_otp_secret='" . $esc(encryptOtpSecret('NEWSEEDNEWSEED22')) . "'");
$cnt = VaultV3::finalizeCredential($db, 7110);
$ok($cnt === 3 && decryptCredentialEntry((string) $one("SELECT credential_password FROM credentials WHERE credential_id=7110"), 7110, 'password') === 'New-Pass-1!' && decryptOtpSecret((string) $one("SELECT credential_otp_secret FROM credentials WHERE credential_id=7110"), 7110) === 'NEWSEEDNEWSEED22', 'a credential written in the legacy format is finalised to v3 after the write (username, password, OTP)');
$ok(VaultV3::finalizeCredential($db, 7110) === 0, 'and finalising again changes nothing');
$_SESSION = $legacySession;
$ok(VaultV3::finalizeCredential($db, 7103) === 0, 'without the data key in the session nothing is converted');
$_SESSION = $sessionBoth;

// password change
$new = 'Brand-New-Passphrase-88?';
$wrapNew = VaultV3::wrapForPasswordChange($db, 711, $new, $pw);
$ok($wrapNew !== null && VaultV3::wrap()->unwrap($wrapNew, $new, 'user:711') === $dek, 'a password change rewraps the same data key under the new password');
$threw = false; try { VaultV3::wrap()->unwrap($wrapNew, $pw, 'user:711'); } catch (\Throwable $e) { $threw = true; }
$ok($threw, 'the old password no longer opens it');
$viaFn = encryptUserSpecificKey($new, 711, $pw);
$ok(str_starts_with($viaFn, 'vw3:') && VaultV3::wrap()->unwrap($viaFn, $new, 'user:711') === $dek, 'encryptUserSpecificKey keeps a vw3 wrap for a user who has one');
$adm = encryptUserSpecificKey($new, 711, null);
$ok(str_starts_with($adm, 'vw3:') && VaultV3::wrap()->unwrap($adm, $new, 'user:711') === $dek, 'an administrator reset (no old password) makes the wrap from the session data key');
$ok(str_starts_with(encryptUserSpecificKey($new, 712, $pw), 'V2:'), 'a user who is not migrated yet still gets the legacy wrap');

// the mobile token
$raw = bin2hex(random_bytes(32));
$tw = VaultV3::wrapForToken($dek, $raw);
$ok(VaultV3::unwrapFromToken($tw, $raw) === $dek && VaultV3::unwrapFromToken($tw, bin2hex(random_bytes(32))) === null && VaultV3::unwrapFromToken(null, $raw) === null, 'the token wraps the data key; another token cannot open it');

// ------------------------------------------------------------------ the real endpoints over HTTP
$sdir = sys_get_temp_dir() . '/crypto_vault_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
putenv('SCRATCH_KEYFILE=' . $kf);
$base = sec_start_server($sdir);
$csrf = 'csrftok12345678';
$sessData = ['logged' => true, 'user_id' => 711, 'csrf_token' => $csrf, 'user_encryption_session_ciphertext' => $legacySession['user_encryption_session_ciphertext'], 'user_encryption_session_iv' => $siv, 'vault_stepup_at' => time(), 'sec_created' => time(), 'sec_last' => time()];
$_SESSION = []; VaultV3::storeSessionDek($dek, $sk);
$sessBoth = $sessData + ['vault_dek_ciphertext' => $_SESSION['vault_dek_ciphertext'], 'vault_dek_iv' => $_SESSION['vault_dek_iv']];
$sid = sec_forge_session($sdir, $sessBoth);
$sidLegacy = sec_forge_session($sdir, $sessData);
$ck = ['user_encryption_session_key' => $sk];
[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sid, ['credential_id' => 7101, 'field' => 'password', 'mode' => 'reveal', 'csrf_token' => $csrf], $ck);
$ok($c === 200 && (json_decode($body, true)['value'] ?? '') === 'S3cret-AAA!', 'credential_reveal.php returns a v3 password over HTTP');
[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sid, ['credential_id' => 7110, 'field' => 'username', 'mode' => 'copy', 'csrf_token' => $csrf], $ck);
$ok($c === 200 && (json_decode($body, true)['value'] ?? '') === 'new-user@example.test', 'and a finalised new credential');
[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sidLegacy, ['credential_id' => 7101, 'field' => 'password', 'mode' => 'reveal', 'csrf_token' => $csrf], $ck);
$ok($c === 409 && (json_decode($body, true)['error'] ?? '') === 'vault_locked', 'a session without the data key is told the vault is locked');
[$c, $body] = sec_web($base, 'GET', '/agent/ajax.php?share_generate_link=1&csrf_token=' . $csrf . '&client_id=8101&type=Credential&id=7101&contact_email=share%40example.test&note=n&views=1&expires=24%20HOUR', $sid, [], $ck);
if (getenv("CRYPTO_DEBUG")) { fwrite(STDERR, "SHARE $c: " . substr($body, 0, 300) . "\n"); }
$share = $rows("SELECT * FROM shared_items WHERE item_related_id=7101 AND item_type='Credential' ORDER BY item_id DESC LIMIT 1")[0] ?? null;
$ok($c === 200 && $share && (string) $share['item_encrypted_credential'] !== '', 'share link creation works for a v3 credential');
$ek = preg_match('/#ek=([A-Za-z0-9_-]{16})/', (string) json_decode($body, true), $mm) ? $mm[1] : '';   // the endpoint answers with the URL; the key is only in its fragment
$iv = substr((string) $share['item_encrypted_credential'], 0, 16);
$shared = $ek !== '' ? openssl_decrypt(substr((string) $share['item_encrypted_credential'], 16), 'aes-128-cbc', $ek, 0, $iv) : false;
$ok($shared === 'S3cret-AAA!', 'the share link still carries its own key in the fragment and decrypts to the credential');

// mobile sign-in: the token gets the data key
$ch = curl_init("$base/api/v1/auth");
$new711 = $new;
$q("UPDATE users SET user_password='" . $esc(secPasswordHash($new711)) . "', user_specific_encryption_ciphertext='" . $esc($adm) . "' WHERE user_id=711");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode(['username' => 'crypto-vault-one@example.test', 'password' => $new711, 'device_name' => 'crypto-test']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
$resp = json_decode((string) curl_exec($ch), true); curl_close($ch);
$tok = (string) ($resp['token'] ?? '');
$trow = $rows("SELECT token_enc_dek, token_enc_master_key FROM api_tokens WHERE token_hash='" . hash('sha256', $tok) . "'")[0] ?? [];
$ok($tok !== '' && !empty($trow['token_enc_master_key']) && VaultV3::unwrapFromToken($trow['token_enc_dek'] ?? '', $tok) === $dek, 'mobile sign-in: the token keeps the legacy master key wrap and also carries the data key');

// ------------------------------------------------------------------ flag off again: legacy readers still work for sessions that hold the keys
VaultV3::setFlag($db, false);
$ok(!VaultV3::enabled($db), 'the flag can be switched off again');
$_SESSION = $sessionBoth;
$ok($reveal(7101)['body']['value'] === 'S3cret-AAA!', 'v3 fields already written stay readable for a session that holds the data key');

// cleanup
$q("DELETE FROM credential_versions WHERE version_credential_id BETWEEN 7100 AND 7199");
$q("DELETE FROM credentials WHERE credential_id BETWEEN 7100 AND 7199");
$q("DELETE FROM shared_items WHERE item_related_id BETWEEN 7100 AND 7199");
$q("DELETE FROM api_tokens WHERE token_user_id=711");
$q("DELETE FROM users WHERE user_id IN (711,712)");
$q("DELETE FROM user_settings WHERE user_id IN (711,712)");
$q("UPDATE settings SET config_vault_v3_enabled=0, config_vault_dek_wrap=NULL, config_vault_canonical_key=NULL WHERE company_id=1");
foreach (glob("$dir/state/*") ?: [] as $f) { @unlink($f); } @rmdir("$dir/state");
foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); } @rmdir($dir);
