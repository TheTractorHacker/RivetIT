<?php
/*
 * RivetCore\Crypto adoption, stages 1 and 2 (ADR-011): encryptSetting / decryptSetting and the TOTP seeds on the v3 envelope.
 *   - the key store: key file vs legacy config.php key vs nothing; permissions, missing, garbled, no silent fallback
 *   - round trips in every format (v3, ENC2, ENC, cleartext), the stage flags, the context binding
 *   - parity of failure handling with the old functions ('' on read, RuntimeException on write)
 *   - the backup fingerprint of the ring equals the formula the manifest has always used
 *   - lazy re-wrap persistence (settings row, TOTP seed, the persist callback)
 *   - rotation: add a key, old values stay readable, rewrap, retire refused while used
 *   - the key admin (generate / add / activate / retire) and the new-install helper
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/crypto_settings.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_crypto.php";

use ITFlow\Crypto\KeyAdmin;
use ITFlow\Crypto\KeyStore;
use ITFlow\Crypto\RewrapService;
use ITFlow\Crypto\SettingsCrypto;
use RivetCore\Crypto\KeyFile;
use RivetCore\Crypto\KeyGenerator;

// a clean slate: values left in the settings row by an earlier run were sealed under another key file
foreach (\ITFlow\Crypto\ColumnRegistry::all() as $cs) { if ($cs->table === 'settings') { $q("UPDATE settings SET `{$cs->column}` = NULL WHERE company_id = 1"); } }
$LEGACY = (string) $GLOBALS['config_settings_enc_key'];
$dir = sys_get_temp_dir() . '/crypto-settings-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$kf = "$dir/keys.json";
$use = function (?string $path, $v3 = null, $totp = null) {
    $GLOBALS['config_keyfile'] = $path ?? '';
    if ($v3 === null) { unset($GLOBALS['config_crypto_v3_settings']); } else { $GLOBALS['config_crypto_v3_settings'] = $v3; }
    if ($totp === null) { unset($GLOBALS['config_crypto_v3_totp']); } else { $GLOBALS['config_crypto_v3_totp'] = $totp; }
    SettingsCrypto::reset();
};
$setKey = function (string $k) { $GLOBALS['config_settings_enc_key'] = $k; $GLOBALS['config_settings_enc_key'] = $k; SettingsCrypto::reset(); };
$cbc = function (string $plain) use ($LEGACY): string {   // what the pre-GCM writer stored
    $iv = random_bytes(16);
    return 'ENC:' . base64_encode($iv . openssl_encrypt($plain, 'aes-128-cbc', substr(hash('sha256', $LEGACY, true), 0, 16), OPENSSL_RAW_DATA, $iv));
};
$logSeen = sys_get_temp_dir() . '/crypto-settings-errlog-' . bin2hex(random_bytes(3));
ini_set('error_log', $logSeen);

// ------------------------------------------------------------------ no key file: everything is as before
$use(null);
$e = encryptSetting('plain-secret');
$ok(str_starts_with($e, 'ENC2:') && decryptSetting($e) === 'plain-secret', 'without a key file new values are ENC2 (nothing changes on an existing install)');
$ok(KeyStore::load()->source === 'legacy' && !SettingsCrypto::v3Enabled() && !SettingsCrypto::totpV3Enabled(), 'source is the legacy config.php key, both stages off');
$ok(decryptSetting($cbc('old-cbc')) === 'old-cbc' && decryptSetting('cleartext-legacy') === 'cleartext-legacy', 'ENC: (CBC) and cleartext still read');
$ok(decryptSetting(encryptSetting('0')) === '0', 'a secret that is literally "0" survives');
$ok(!file_exists($kf), 'nothing created a key file');

// ------------------------------------------------------------------ generating the key file from the legacy key
$r = KeyAdmin::generate($kf);
$ok($r['origin'] === 'legacy' && $r['kid'] === 'k1' && (fileperms($kf) & 0777) === 0640, 'generate: the legacy key becomes kid k1, mode 0640');
$ok($r['fingerprint'] === substr(hash('sha256', 'rivetit-settings-key-fingerprint|v1|' . $LEGACY), 0, 16), 'the ring fingerprint equals the backup manifest fingerprint formula, unchanged');
$threw = false; try { KeyAdmin::generate($kf); } catch (\RuntimeException $e2) { $threw = true; }
$ok($threw, 'generate refuses to overwrite an existing key file');
$use($kf);
$ok(KeyStore::load()->source === 'file' && SettingsCrypto::v3Enabled() && SettingsCrypto::totpV3Enabled(), 'with the key file the stages switch on');
$old = $e;   // ENC2 written before
$v = encryptSetting('v3-secret');
$ok(str_starts_with($v, 'v3:k1:') && decryptSetting($v) === 'v3-secret', 'new values are v3 under kid k1');
$ok(decryptSetting($old) === 'plain-secret' && decryptSetting($cbc('c2')) === 'c2' && decryptSetting('cleartext-legacy') === 'cleartext-legacy', 'ENC2, ENC and cleartext still read through the vault');
$ok(decryptSetting($v, 'other.context') === '', 'a value does not open under another context (AAD)');
$tampered = substr($v, 0, -3) . (substr($v, -3) === 'AAA' ? 'BBB' : 'AAA');
$ok(decryptSetting($tampered) === '', 'a tampered v3 value reads as empty, not garbage');
$ok(decryptSetting(encryptSetting('x', 'settings.vault_canonical_key'), 'settings.vault_canonical_key') === 'x' && decryptSetting(encryptSetting('x', 'settings.vault_canonical_key')) === '', 'a named context opens only under that name');
$ok(encryptSetting('') === '' && decryptSetting('') === '', 'empty stays empty');

// the old call sites' contract: a write with no key throws RuntimeException, a read that cannot open gives ''
$use($kf, true);
$setKey('');
$use('', true);   // flag forced on, no file, no legacy key: no ring at all
$threw = false; try { encryptSetting('x'); } catch (\RuntimeException $e3) { $threw = true; }
$ok($threw, 'no key at all: encryptSetting throws RuntimeException (as before)');
$ok(decryptSetting($old) === '' && decryptSetting($v) === '' && decryptSetting('still-cleartext') === 'still-cleartext', 'no key at all: encrypted values read as empty, cleartext is handed back (as before)');
$setKey($LEGACY);
$use($kf);

// ------------------------------------------------------------------ the key file: permissions, garbled, no silent fallback
chmod($kf, 0644);
SettingsCrypto::reset();
$st = KeyStore::load();
$ok($st->source === 'file' && $st->warnings !== [] && decryptSetting($v) === 'v3-secret', 'a world-readable key file is used, with a warning');
chmod($kf, 0666);
SettingsCrypto::reset();
$st = KeyStore::load();
$ok($st->source === 'file-error' && !$st->usable(), 'a world-writable key file is refused');
$threw = false; try { encryptSetting('x'); } catch (\RuntimeException $e4) { $threw = true; }
$ok($threw, 'with an unusable key file writes fail closed (no fall back to the config.php key)');
$ok(decryptSetting($v) === '' && decryptSetting($old) === 'plain-secret', 'v3 values read as empty, ENC2 still opens through the legacy key');
chmod($kf, 0640);
$good = file_get_contents($kf);
file_put_contents($kf, '{not json');
SettingsCrypto::reset();
$ok(KeyStore::load()->source === 'file-error' && decryptSetting($v) === '', 'a garbled key file is an error state, not a fallback');
file_put_contents($kf, json_encode(['version' => 1, 'active' => 'k1', 'keys' => ['k1' => ['key' => 'abcd']]]));
SettingsCrypto::reset();
$ok(KeyStore::load()->source === 'file-error', 'a weak or short key is refused');
file_put_contents($kf, $good); chmod($kf, 0640);
SettingsCrypto::reset();
$ok(KeyStore::load()->usable() && decryptSetting($v) === 'v3-secret', 'restoring the file restores the values');
$use($dir . '/missing.json');
$ok(KeyStore::load()->source === 'legacy' && !SettingsCrypto::v3Enabled() && decryptSetting($v) === 'v3-secret', 'a missing key file falls back to the legacy key as k1 (v3 values under k1 still open)');
$use($kf);

// ------------------------------------------------------------------ stage flags
$use($kf, false);
$ok(str_starts_with(encryptSetting('flag-off'), 'ENC2:') && decryptSetting($v) === 'v3-secret', 'stage flag off: writes are ENC2 again, v3 values stay readable');
$use($kf, true, false);
$ok(str_starts_with(encryptSetting('a'), 'v3:') && decryptSetting(secUserTotpStore('JBSWY3DPEHPK3PXP', 5)) === 'JBSWY3DPEHPK3PXP', 'TOTP stage off: seeds are written in the settings form');
$use($kf);

// ------------------------------------------------------------------ TOTP seeds
$seed = 'JBSWY3DPEHPK3PXP';
$t = secUserTotpStore($seed, 42);
$ok(str_starts_with($t, 'v3:k1:') && secUserTotpSecret($t, 42) === $seed, 'a TOTP seed is v3 and bound to its user');
$ok(secUserTotpSecret($t, 43) === '', 'another user cannot open it');
$ok(decryptSetting($t) === '', 'and it is not a settings-purpose value');
$ok(secUserTotpSecret($seed, 42) === $seed && secUserTotpSecret(encryptSetting($seed), 42) === $seed && secUserTotpSecret($cbc($seed), 42) === $seed, 'cleartext, settings-purpose v3 and ENC: seeds all read');
$ok(secUserTotpSecret(null, 1) === '' && secUserTotpSecret('', 1) === '', 'no seed reads as empty');
$q("DELETE FROM users WHERE user_name LIKE 'crypto-%'");
$q("INSERT INTO users SET user_name='crypto-t1', user_email='crypto-t1@example.test', user_password='x', user_role_id=1, user_token='" . $esc($seed) . "'");
$u1 = (int) $one("SELECT user_id FROM users WHERE user_email='crypto-t1@example.test'");
secUserTotpRewrap($db, $u1, $seed);
$now = $one("SELECT user_token FROM users WHERE user_id=$u1");
$ok(str_starts_with($now, 'v3:k1:') && secUserTotpSecret($now, $u1) === $seed, 'lazy re-wrap: a cleartext seed is stored v3 after a successful read');
secUserTotpRewrap($db, $u1, $now);
$ok($one("SELECT user_token FROM users WHERE user_id=$u1") === $now, 'and a current seed is left byte-identical');
$gen = encryptSetting($seed);
$q("UPDATE users SET user_token='" . $esc($gen) . "' WHERE user_id=$u1");
secUserTotpRewrap($db, $u1, $gen);
$ok(secUserTotpSecret($one("SELECT user_token FROM users WHERE user_id=$u1"), $u1) === $seed && $one("SELECT user_token FROM users WHERE user_id=$u1") !== $gen, 'lazy re-wrap moves a settings-purpose v3 seed to the totp purpose');

// ------------------------------------------------------------------ lazy re-wrap of the settings row
$cols = ['config_smtp_password' => 'smtp-pw', 'config_azure_client_secret' => 'azure-sec', 'config_outlook_cal_client_secret' => 'cal-sec', 'config_comet_admin_pass' => 'comet-pw', 'config_login_key_secret' => 'KEY123'];
$use(null);   // seed as an old install would
$sets = [];
foreach ($cols as $c => $p) { $sets[] = "$c='" . $esc($c === 'config_comet_admin_pass' ? $cbc($p) : encryptSetting($p)) . "'"; }
$q("UPDATE settings SET " . implode(',', $sets) . " WHERE company_id=1");
$marker = glob(sys_get_temp_dir() . '/rivetit_rewrap_skip_*'); foreach ($marker ?: [] as $m) { @unlink($m); }
$use($kf);
$row = $rows("SELECT * FROM settings WHERE company_id=1")[0];
secLazyRewrapSettings($db, $row);
$after = $rows("SELECT * FROM settings WHERE company_id=1")[0];
$all = true; $back = true;
foreach ($cols as $c => $p) { $all = $all && str_starts_with($after[$c], 'v3:k1:'); $back = $back && decryptSetting($after[$c]) === $p; }
$ok($all && $back, 'lazy re-wrap: a settings read moves ENC2 and ENC secret columns to v3, and they read back');
$snap = $after;
secLazyRewrapSettings($db, $after);
$ok($rows("SELECT * FROM settings WHERE company_id=1")[0] === $snap, 'and a second read changes nothing');
// persist callback of decryptSetting
$legacyVal = (function () use ($use, $kf) { $use(null); $x = encryptSetting('cb-secret'); $use($kf); return $x; })();
$persisted = [];
$pl = decryptSetting($legacyVal, SettingsCrypto::GENERIC, function (string $new, string $prev) use (&$persisted) { $persisted[] = [$new, $prev]; });
$ok($pl === 'cb-secret' && count($persisted) === 1 && str_starts_with($persisted[0][0], 'v3:k1:') && $persisted[0][1] === $legacyVal, 'the persist callback gets (v3 value, value as read)');
$persisted = [];
decryptSetting($persisted === [] ? encryptSetting('cur') : '', SettingsCrypto::GENERIC, function () use (&$persisted) { $persisted[] = 1; });
$ok($persisted === [], 'no callback for a value that is already current');
$use($kf, false);
$persisted = [];
decryptSetting($legacyVal, SettingsCrypto::GENERIC, function () use (&$persisted) { $persisted[] = 1; });
$ok($persisted === [], 'with the stage off nothing is persisted as v3');
$use($kf);

// ------------------------------------------------------------------ rotation
$a = KeyAdmin::addKey(true);
$ok($a['active'] && strlen($a['fingerprint']) === 16, 'add-key returns a kid and a fingerprint only');
SettingsCrypto::reset();
$st = KeyAdmin::status();
$ok(count($st['keys']) === 2 && $st['active'] === $a['kid'], 'status lists both keys, the new one active');
$n = encryptSetting('after-rotation');
$ok(str_starts_with($n, 'v3:' . $a['kid'] . ':') && decryptSetting($v) === 'v3-secret' && decryptSetting($old) === 'plain-secret', 'new writes use the new key; old v3 and ENC2 values still open');
$svc = new RewrapService($db, 1);
$inv = $svc->inventory();
$plan = $svc->plan($inv);
$ok(!$plan->isComplete() && ($inv['totals']['v3:k1'] ?? 0) > 0, 'the inventory counts values still on the old key');
$usage = ['k1' => (int) ($inv['totals']['v3:k1'] ?? 0)];
$threw = false; try { KeyAdmin::retire('k1', $usage, false); } catch (\RuntimeException $e5) { $threw = true; }
$ok($threw, 'retire is refused while stored values use the key');
$threw = false; try { KeyAdmin::retire($a['kid'], [], false); } catch (\RuntimeException $e6) { $threw = true; }
$ok($threw, 'the active key cannot be retired');
for ($i = 0; $i < 3; $i++) { $reps = $svc->run(); }
$inv = $svc->inventory();
if (getenv('CRYPTO_DEBUG')) { fwrite(STDERR, json_encode($inv) . json_encode($svc->plan($inv)->toArray()) . "\n"); }
$ok(($inv['totals']['v3:k1'] ?? 0) === 0 && ($inv['totals']['v3:' . $a['kid']] ?? 0) > 0 && $svc->plan($inv)->isComplete(), 'after the rewrap everything is on the new key and the plan is complete');
$bak = glob($kf . '.bak-*');
$ok(count($bak) === 1 && (fileperms($bak[0]) & 0777) === 0640, 'a numbered backup copy of the key file was taken, same mode');
$r = KeyAdmin::retire('k1', ['k1' => 0], false);
SettingsCrypto::reset();
$ok($r['retired'] && count(KeyAdmin::status()['keys']) === 1, 'retire works once nothing uses the key');
$ok(decryptSetting($n) === 'after-rotation', 'values on the remaining key still open');
$ok(KeyAdmin::status()['rotation_due'] === false, 'a fresh key raises no rotation warning');
// the rotation warning after 12 months
$ring = KeyStore::load()->ring;
$akid = (string) $ring->activeKid();
$aged = \RivetCore\Crypto\KeyRing::fromKeys([$akid => $ring->activeKey()], $akid, [$akid => (new DateTimeImmutable('-13 months'))->format('c')]);
KeyFile::write($kf, $aged, true);
SettingsCrypto::reset();
$ok(KeyAdmin::status()['rotation_due'] === true, 'an active key older than 12 months raises the rotation warning');

// ------------------------------------------------------------------ install helper
$dir2 = sys_get_temp_dir() . '/crypto-install-' . bin2hex(random_bytes(3));
$GLOBALS['config_keyfile'] = "$dir2/sub/keys.json"; SettingsCrypto::reset();
$r = KeyAdmin::createForInstall(bin2hex(random_bytes(32)));
$ok($r['status'] === 'created' && is_file("$dir2/sub/keys.json") && (fileperms("$dir2/sub/keys.json") & 0777) === 0640, 'createForInstall makes the directory and a 0640 key file');
$r2 = KeyAdmin::createForInstall(bin2hex(random_bytes(32)));
$ok($r2['status'] === 'exists', 'and never overwrites');
$GLOBALS['config_keyfile'] = '/proc/nope/keys.json'; SettingsCrypto::reset();
$r3 = KeyAdmin::createForInstall(bin2hex(random_bytes(32)));
$ok($r3['status'] === 'failed' && str_contains($r3['message'], 'keys_cli.php'), 'an unwritable location is reported with the command to run, not thrown');

// ------------------------------------------------------------------ logs carry no values
$use($kf);
decryptSetting('v3:k1:' . base64_encode(str_repeat('z', 40)));
$log = (string) @file_get_contents($logSeen);
$ok(!str_contains($log, 'zzzz') && !str_contains($log, $LEGACY), 'a failed read is logged without the value or any key');

$use(null);
foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); } @rmdir($dir);
foreach (glob("$dir2/sub/*") ?: [] as $f) { @unlink($f); } @rmdir("$dir2/sub"); @rmdir($dir2);
@unlink($logSeen);
$q("DELETE FROM users WHERE user_name LIKE 'crypto-%'");
