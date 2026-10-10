<?php
/*
 * Wave 1 security, items 3 and 4 (storage half): secrets that used to be plaintext.
 *   - encryptSetting / decryptSetting round trips, the legacy forms (ENC: CBC, unprefixed plaintext) still read
 *   - secWrapIfPlain never double-wraps; secUserTotpSecret reads both forms
 *   - secRewrapColumn wraps plaintext rows only, is idempotent, never wraps without a key, skips a value that would not fit
 *   - the lazy re-wrap on a settings read
 *   - DB update 2.6.153 (real updater): wraps every named straggler column, leaves wrapped rows byte-identical on a second run,
 *     and does nothing to the data when the settings key is empty
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/security_crypto.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_crypto.php";

$KEY = $GLOBALS['config_settings_enc_key'];
$ok($KEY !== '' && $KEY !== null, 'the scratch config has a settings key');

// ------------------------------------------------------------------ cipher round trips and legacy forms
$enc = encryptSetting('xoxb-example-token');
$ok(str_starts_with($enc, 'ENC2:') && $enc !== 'xoxb-example-token', 'encryptSetting writes the ENC2 (AES-256-GCM) form');
$ok(decryptSetting($enc) === 'xoxb-example-token', 'ENC2 round trip');
$ok(decryptSetting(encryptSetting('0')) === '0', 'a secret that is literally "0" survives');
$ok(decryptSetting('legacy-plaintext') === 'legacy-plaintext', 'unprefixed legacy plaintext is still readable');
// legacy ENC: = aes-128-cbc, key = first 16 bytes of sha256(key), iv(16) . ct, base64
$iv = random_bytes(16);
$ct = openssl_encrypt('old-cbc-secret', 'aes-128-cbc', substr(hash('sha256', $KEY, true), 0, 16), OPENSSL_RAW_DATA, $iv);
$legacy = 'ENC:' . base64_encode($iv . $ct);
$ok(decryptSetting($legacy) === 'old-cbc-secret', 'legacy ENC: (CBC) value is still readable');
$tampered = substr($enc, 0, -4) . 'AAAA';
$ok(decryptSetting($tampered) === '', 'a tampered ENC2 value decrypts to nothing, not garbage');
$ok(secIsWrapped($enc) && secIsWrapped($legacy) && !secIsWrapped('plain') && !secIsWrapped(null) && !secIsWrapped(''), 'secIsWrapped recognises both prefixes only');
$ok(secWrapIfPlain($enc) === $enc && secWrapIfPlain($legacy) === $legacy && secWrapIfPlain('') === '', 'secWrapIfPlain never double-wraps and leaves empty alone');
$w = secWrapIfPlain('fresh');
$ok(secIsWrapped($w) && decryptSetting($w) === 'fresh', 'secWrapIfPlain wraps a plaintext value');

// TOTP seed in both forms
$seed = 'JBSWY3DPEHPK3PXP';
$ok(secUserTotpSecret($seed) === $seed, 'a legacy plaintext TOTP seed reads as itself');
$ok(secUserTotpSecret(secUserTotpStore($seed)) === $seed && secUserTotpStore($seed) !== $seed, 'a stored (wrapped) TOTP seed reads back');
$ok(secUserTotpSecret(null) === '' && secUserTotpSecret('') === '', 'no seed reads as empty');

// ------------------------------------------------------------------ re-wrap columns
$q("DELETE FROM users WHERE user_email LIKE 'sec-crypto-%'");
$q("INSERT INTO users SET user_name='C One', user_email='sec-crypto-1@example.test', user_password='x', user_token='$seed', user_role_id=1");
$q("INSERT INTO users SET user_name='C Two', user_email='sec-crypto-2@example.test', user_password='x', user_token='" . $esc(secUserTotpStore('ALREADYWRAPPED234')) . "', user_role_id=1");
$u1 = (int) $one("SELECT user_id FROM users WHERE user_email='sec-crypto-1@example.test'");
$u2 = (int) $one("SELECT user_id FROM users WHERE user_email='sec-crypto-2@example.test'");
$wrappedBefore = $one("SELECT user_token FROM users WHERE user_id=$u2");

$r = secRewrapColumn($db, 'users', 'user_id', 'user_token');
$ok($r['wrapped'] >= 1 && $r['skipped_too_long'] === 0, 'secRewrapColumn wraps the plaintext TOTP seed');
$ok(secIsWrapped($one("SELECT user_token FROM users WHERE user_id=$u1")) && secUserTotpSecret($one("SELECT user_token FROM users WHERE user_id=$u1")) === $seed, 'the row now holds a wrapped seed that decrypts to the original');
$ok($one("SELECT user_token FROM users WHERE user_id=$u2") === $wrappedBefore, 'an already wrapped row is left byte-identical');
$again = secRewrapColumn($db, 'users', 'user_id', 'user_token');
$ok($again['wrapped'] === 0, 'a second run wraps nothing (idempotent)');
$ok(secRewrapColumn($db, 'users', 'user_id', 'no_such_column')['missing'] === true, 'a missing column is reported, not fatal');

// lazy per-user TOTP rewrap
$q("UPDATE users SET user_token='$seed' WHERE user_id=$u1");
secUserTotpRewrap($db, $u1, $seed);
$ok(secIsWrapped($one("SELECT user_token FROM users WHERE user_id=$u1")), 'secUserTotpRewrap wraps one legacy seed after a successful read');
$tok = $one("SELECT user_token FROM users WHERE user_id=$u1");
secUserTotpRewrap($db, $u1, $tok);
$ok($one("SELECT user_token FROM users WHERE user_id=$u1") === $tok, 'secUserTotpRewrap leaves a wrapped seed alone');

// every named straggler column: the settings row, software.software_key, software_keys.software_key
$plain = [
    'config_slack_bot_token' => 'xoxb-1234567890-abc', 'config_login_key_secret' => 'MYSECRET', 'config_whitelabel_key' => 'wl-key-123',
    'config_smtp_password' => 'smtp-pw', 'config_azure_client_secret' => 'azure-secret', 'config_mail_oauth_client_secret' => 'oauth-secret',
    'config_mail_oauth_refresh_token' => 'refresh-token', 'config_mail_oauth_access_token' => 'access-token',
];
$set = []; foreach ($plain as $c => $v) { $set[] = "$c='" . $esc($v) . "'"; }
$q("UPDATE settings SET " . implode(',', $set) . " WHERE company_id=1");
$q("DELETE FROM software WHERE software_name='sec-crypto'");
$q("INSERT INTO software SET software_name='sec-crypto', software_key='LICENSE-AAAA-BBBB', software_client_id=0");
$swId = (int) $db->insert_id;
foreach (secStragglerColumns() as $table => [$pk, $cols]) {
    foreach ($cols as $c) { secRewrapColumn($db, $table, $pk, $c); }
}
$row = $rows("SELECT * FROM settings WHERE company_id=1")[0];
$allWrapped = true; $allBack = true;
foreach ($plain as $c => $v) { $allWrapped = $allWrapped && secIsWrapped($row[$c]); $allBack = $allBack && decryptSetting($row[$c]) === $v; }
$ok($allWrapped, 'every named settings straggler column is wrapped (' . implode(', ', array_keys($plain)) . ')');
$ok($allBack, 'and each decrypts back to its original value');
$sk = $one("SELECT software_key FROM software WHERE software_id=$swId");
$ok(secIsWrapped($sk) && decryptSetting($sk) === 'LICENSE-AAAA-BBBB', 'software.software_key is wrapped and readable');
$ok(stripos((string) $one("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='software' AND COLUMN_NAME='software_key'"), 'text') !== false, 'software.software_key is a TEXT column (a wrapped key does not fit varchar(200))');
$long = str_repeat('K', 400);
$q("UPDATE software SET software_key='$long' WHERE software_id=$swId");
secRewrapColumn($db, 'software', 'software_id', 'software_key');
$ok(decryptSetting($one("SELECT software_key FROM software WHERE software_id=$swId")) === $long, 'a 400 character license key wraps and reads back whole');

// a varchar column too small for the wrapped value: skipped, not truncated
$q("UPDATE settings SET config_login_key_secret='" . str_repeat('s', 200) . "' WHERE company_id=1");   // varchar(255): wrapped is ~ 5+4*ceil(228/3)=309 chars
$r = secRewrapColumn($db, 'settings', 'company_id', 'config_login_key_secret');
$ok($r['skipped_too_long'] === 1 && $one("SELECT config_login_key_secret FROM settings WHERE company_id=1") === str_repeat('s', 200), 'a value whose wrapped form would not fit is left untouched, not truncated');
$q("UPDATE settings SET config_login_key_secret='" . $esc(encryptSetting('MYSECRET')) . "' WHERE company_id=1");

// ------------------------------------------------------------------ no key, no wrapping
$q("UPDATE settings SET config_slack_bot_token='xoxb-keyless-token' WHERE company_id=1");
$GLOBALS['config_settings_enc_key'] = '';
$config_settings_enc_key = '';
$ok(!secSettingsKeyAvailable(), 'secSettingsKeyAvailable is false with an empty key');
$r = secRewrapColumn($db, 'settings', 'company_id', 'config_slack_bot_token');
$ok($r['wrapped'] === 0 && $one("SELECT config_slack_bot_token FROM settings WHERE company_id=1") === 'xoxb-keyless-token', 'with an empty settings key nothing is wrapped');
secLazyRewrapSettings($db, ['config_slack_bot_token' => 'xoxb-keyless-token']);
$ok($one("SELECT config_slack_bot_token FROM settings WHERE company_id=1") === 'xoxb-keyless-token', 'the lazy re-wrap also does nothing without a key');
$threw = false; try { encryptSetting('x'); } catch (\RuntimeException $e) { $threw = true; }
$ok($threw, 'encryptSetting still refuses to write a secret with no key (fail closed)');
$GLOBALS['config_settings_enc_key'] = $KEY;
$config_settings_enc_key = $KEY;

// ------------------------------------------------------------------ lazy re-wrap on a settings read
$q("UPDATE settings SET config_slack_bot_token='xoxb-lazy-token', config_mail_oauth_access_token='lazy-access' WHERE company_id=1");
$settingsRow = $rows("SELECT * FROM settings WHERE company_id=1")[0];
secLazyRewrapSettings($db, $settingsRow);
$after = $rows("SELECT config_slack_bot_token, config_mail_oauth_access_token FROM settings WHERE company_id=1")[0];
$ok(secIsWrapped($after['config_slack_bot_token']) && decryptSetting($after['config_slack_bot_token']) === 'xoxb-lazy-token', 'a settings read wraps a plaintext Slack token it finds');
$ok(secIsWrapped($after['config_mail_oauth_access_token']) && decryptSetting($after['config_mail_oauth_access_token']) === 'lazy-access', 'and a plaintext OAuth token written by a cron job');

// ------------------------------------------------------------------ DB update 2.6.153 through the real updater
$cli = 'cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db';
$resetVersion = function (string $v) use ($q) { $q("UPDATE settings SET config_current_database_version='$v' WHERE company_id=1"); };
// plant plaintext in every column the step names, then run the step
$q("UPDATE settings SET " . implode(',', $set) . " WHERE company_id=1");
$q("UPDATE software SET software_key='LICENSE-CCCC' WHERE software_id=$swId");
$q("UPDATE users SET user_token='$seed' WHERE user_id=$u1");
$resetVersion('2.6.152');
[$c, $out] = sec_sh($cli);
$ok($c === 0 && version_compare((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1"), '2.6.153', '>='), 'the updater ran the 2.6.153 step from 2.6.152 (later steps follow)');
$row = $rows("SELECT * FROM settings WHERE company_id=1")[0];
$allWrapped = true; foreach ($plain as $c2 => $v) { $allWrapped = $allWrapped && secIsWrapped($row[$c2]) && decryptSetting($row[$c2]) === $v; }
$ok($allWrapped, 'DB update 2.6.153 wrapped every straggler settings column');
$ok(decryptSetting($one("SELECT software_key FROM software WHERE software_id=$swId")) === 'LICENSE-CCCC' && secIsWrapped($one("SELECT software_key FROM software WHERE software_id=$swId")), 'DB update 2.6.153 wrapped the license key');
$ok(secUserTotpSecret($one("SELECT user_token FROM users WHERE user_id=$u1")) === $seed && secIsWrapped($one("SELECT user_token FROM users WHERE user_id=$u1")), 'DB update 2.6.153 wrapped the TOTP seed');
$snap = $rows("SELECT config_slack_bot_token, config_login_key_secret, config_smtp_password FROM settings WHERE company_id=1")[0];
$snapUser = $one("SELECT user_token FROM users WHERE user_id=$u1");
$resetVersion('2.6.152');
sec_sh($cli);
$ok($rows("SELECT config_slack_bot_token, config_login_key_secret, config_smtp_password FROM settings WHERE company_id=1")[0] === $snap && $one("SELECT user_token FROM users WHERE user_id=$u1") === $snapUser, 'running the step again leaves wrapped rows byte-identical (idempotent)');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('security_settings','user_recovery_codes','user_sessions')") === 3, 'the step created security_settings, user_recovery_codes and user_sessions');
$ok((int) $one("SELECT config_login_session_lifetime FROM settings WHERE company_id=1") >= 60, 'the session lifetime is not left below the new floor');

// the step with no key: data untouched, version still advances, a notice is printed
$q("UPDATE settings SET config_slack_bot_token='xoxb-nokey-token' WHERE company_id=1");
$q("UPDATE users SET user_token='$seed' WHERE user_id=$u1");
$resetVersion('2.6.152');
$cfgPath = "$root/config.php";
$cfgOrig = file_get_contents($cfgPath);
file_put_contents($cfgPath, $cfgOrig . "\n\$config_settings_enc_key = '';\n");
[$c, $out] = sec_sh($cli);
file_put_contents($cfgPath, $cfgOrig);
$ok($c === 0 && str_contains($out, 'NOT re-wrapped'), 'with an empty key the step prints that it did not re-wrap');
$ok($one("SELECT config_slack_bot_token FROM settings WHERE company_id=1") === 'xoxb-nokey-token' && $one("SELECT user_token FROM users WHERE user_id=$u1") === $seed, 'with an empty key the stored secrets are untouched');

// the mail queue cron stores refreshed OAuth tokens wrapped (it used to write them in plaintext)
$mq = (string) file_get_contents("$root/cron/mail_queue.php");
$a = (int) strpos($mq, 'function persistMailOauthTokens'); $fn = substr($mq, $a, (int) strpos($mq, 'function refreshMailOauthAccessToken') - $a);
$ok(substr_count($fn, 'encryptSetting($') === 2 && !preg_match('/mysqli_real_escape_string\(\$mysqli, \$(access|refresh)_token\)/', $fn), 'cron/mail_queue.php persistMailOauthTokens() wraps both the access and the refresh token');

// cleanup
$q("DELETE FROM software WHERE software_name='sec-crypto'");
$q("DELETE FROM users WHERE user_email LIKE 'sec-crypto-%'");
