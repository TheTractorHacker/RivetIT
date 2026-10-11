<?php
/*
 * RivetCore\Crypto adoption, stage 3: the rewrap tool on a seeded scratch database with a legacy value in EVERY registered column family.
 *   - dry run writes nothing; a real run moves ENC2/ENC to v3 under the active key, wraps cleartext only where allowed, is idempotent
 *   - resumable (state file), compare-and-set, never truncates into a column that is too narrow, one audit row per column and run
 *   - TOTP seeds go to the totp purpose bound to the user; the vault canonical key keeps its own context
 *   - every value reads back through the app's own readers after the run
 *   - DB update 2.6.160 widening: idempotent, data preserved
 *   - scripts/keys_cli.php and scripts/rewrap_cli.php end to end
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/crypto_rewrap.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_crypto.php";

use ITFlow\Crypto\ColumnRegistry;
use ITFlow\Crypto\KeyAdmin;
use ITFlow\Crypto\KeyStore;
use ITFlow\Crypto\MysqliRewrapSource;
use ITFlow\Crypto\ColumnSpec;
use ITFlow\Crypto\RewrapService;
use ITFlow\Crypto\SchemaWidening;
use ITFlow\Crypto\SettingsCrypto;
use RivetCore\Crypto\RewrapOptions;

$dir = sys_get_temp_dir() . '/crypto-rewrap-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$kf = "$dir/keys.json";
$use = function (?string $path) { $GLOBALS['config_keyfile'] = $path ?? ''; unset($GLOBALS['config_crypto_v3_settings'], $GLOBALS['config_crypto_v3_totp']); SettingsCrypto::reset(); };
$cbc = function (string $plain): string {
    $iv = random_bytes(16);
    return 'ENC:' . base64_encode($iv . openssl_encrypt($plain, 'aes-128-cbc', substr(hash('sha256', $GLOBALS['config_settings_enc_key'], true), 0, 16), OPENSSL_RAW_DATA, $iv));
};

// ------------------------------------------------------------------ seed: a legacy value in every column family
$use(null);
foreach (ColumnRegistry::all() as $cs) { if ($cs->table === 'settings') { $q("UPDATE settings SET `{$cs->column}` = NULL WHERE company_id = 1"); } }
$q("DELETE FROM users WHERE user_name LIKE 'crw-%'");
$q("INSERT INTO users SET user_name='crw-a', user_email='crw-a@example.test', user_password='x', user_role_id=1");
$q("INSERT INTO users SET user_name='crw-b', user_email='crw-b@example.test', user_password='x', user_role_id=1");
$ua = (int) $one("SELECT user_id FROM users WHERE user_email='crw-a@example.test'");
$ub = (int) $one("SELECT user_id FROM users WHERE user_email='crw-b@example.test'");
$q("DELETE FROM software_keys WHERE software_key_software_id = 899"); $q("DELETE FROM software WHERE software_id = 899");
$q("INSERT INTO software SET software_id = 899, software_name = 'crw-sw'");
$expect = [];     // "table.column#id" => [plaintext, expected state: 'moved' | 'wrapped' | 'left']
$seeded = 0;
foreach (ColumnRegistry::all() as $cs) {
    if ($cs->table === 'users') { continue; }
    if (!MysqliRewrapSource::exists($db, $cs)) { continue; }
    $plain = 'secret-' . $cs->table . '-' . $cs->column;
    $val = $cs->kind === 'canonical' ? 'abcdEFGH12345678' : $plain;
    $stored = $cs->column === 'config_whitelabel_key' || str_contains($cs->column, 'refresh') ? $cbc($val) : encryptSetting($val, $cs->kind === 'canonical' ? SettingsCrypto::VAULT_CANONICAL_KEY : SettingsCrypto::GENERIC);
    $pkv = $cs->table === 'settings' ? 1 : ($cs->table === 'recovery_settings' ? "'drill_db_pass'" : ($cs->numericPk ? (900 + $seeded) : "'rw-" . (900 + $seeded) . "'"));
    if ($cs->table === 'settings') {
        $q("UPDATE settings SET `{$cs->column}` = '" . $esc($stored) . "' WHERE company_id = 1");
    } elseif ($cs->table === 'endpoint_agent_settings') {
        $q("UPDATE endpoint_agent_settings SET `{$cs->column}` = '" . $esc($stored) . "' WHERE id = 1");
        $pkv = 1;
    } elseif ($cs->table === 'recovery_settings') {
        $q("INSERT INTO recovery_settings (setting_key, setting_value) VALUES ('drill_db_pass', '" . $esc($stored) . "') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    } else {
        $q("DELETE FROM `{$cs->table}` WHERE `{$cs->pk}` = $pkv");
        $extra = $cs->table === 'software_keys' ? ', software_key_software_id = 899' : ($cs->pk2 !== null ? ", `{$cs->pk2}` = 7" : '');
        $ok($q("INSERT INTO `{$cs->table}` SET `{$cs->pk}` = $pkv, `{$cs->column}` = '" . $esc($stored) . "'$extra") !== false, "seed {$cs->name()}");
    }
    $expect[$cs->name()] = [$cs, $pkv, $val];
    $seeded++;
}
$q("UPDATE users SET user_token='" . $esc(encryptSetting('SEEDAAAA')) . "' WHERE user_id=$ua");
$q("UPDATE users SET user_token='SEEDBBBB' WHERE user_id=$ub");   // cleartext
// cleartext in columns of each policy
$q("UPDATE settings SET config_slack_bot_token='xoxb-clear' WHERE company_id=1");                  // wrapPlaintext column
$q("UPDATE settings SET config_oidc_client_secret='oidc-clear' WHERE company_id=1");               // a column with raw readers: left alone
$ok($seeded >= 30, "seeded $seeded registered columns with a legacy value");

// ------------------------------------------------------------------ the registry covers every column the app seals
$regNames = array_map(fn ($c) => $c->name(), ColumnRegistry::all());
$uncovered = [];
foreach (array_merge(secStragglerColumns(), function_exists('secDeferredColumns') ? secDeferredColumns() : []) as $table => [$pk, $cols]) {
    foreach ($cols as $c) { if (!in_array("$table.$c", $regNames, true)) { $uncovered[] = "$table.$c"; } }
}
$ok($uncovered === [], 'every secStragglerColumns()/secDeferredColumns() column is in the rewrap registry' . ($uncovered ? ': ' . implode(', ', $uncovered) : ''));
$ok(in_array('rmm_job_extra.secret_params_enc', $regNames, true) && in_array('rmm_custom_field_values.value_enc', $regNames, true) && in_array('endpoint_agent_settings.signing_private_key_enc', $regNames, true) && in_array('endpoint_agent_settings.mesh_login_key_enc', $regNames, true), 'the RMM module columns (signing key, Mesh login key, job secrets, secret custom fields) are in the registry');
$ok(in_array('settings.config_rmm_extstore_write_url', $regNames, true) && in_array('settings.config_rmm_extstore_read_url', $regNames, true) && in_array('settings.config_rmm_extstore_token', $regNames, true), 'the RMM external metric store settings are in the registry');
// Schema-wide: a column that looks like it holds an encryptSetting() value (name ends in _enc, or is a named secret/token/key column of the settings row or an integrations table) must be in
// the registry or in the short list of columns that use another envelope on purpose. A NEW secret column added to the schema without a registry line fails here, and a rotation would
// have silently skipped it.
$otherEnvelope = [   // table.column => why it is not in the registry
    'credential_versions.version_previous_username_enc' => 'credential vault (VaultV3)', 'credential_versions.version_previous_password_enc' => 'credential vault (VaultV3)',
    'settings.config_backup_s3_access_key' => 'an access key ID is an identifier, not a secret (the secret key is registered)',
    'users.user_passkey_enc_ciphertext' => 'passkey wrap, derived from the user\'s password (not encryptSetting)', 'users.user_passkey_enc_iv' => 'passkey wrap IV (not a secret value)',
];
$scan = $rows("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('varchar','text','mediumtext','longtext','tinytext','char')
    AND (COLUMN_NAME LIKE '%\\_enc' OR (TABLE_NAME = 'settings' AND (COLUMN_NAME LIKE 'config\\_%secret' OR COLUMN_NAME LIKE 'config\\_%password' OR COLUMN_NAME LIKE 'config\\_%\\_token' OR COLUMN_NAME LIKE 'config\\_%\\_key' OR COLUMN_NAME LIKE 'config\\_%passphrase' OR COLUMN_NAME LIKE 'config\\_%\\_pass')))");
$missing = [];
foreach ($scan as $r) {
    $n = $r['t'] . '.' . $r['c'];
    if (!in_array($n, $regNames, true) && !isset($otherEnvelope[$n])) { $missing[] = $n; }
}
$ok($missing === [], 'no secret-looking column is missing from the rewrap registry (schema scan of *_enc and settings secret/password/token/key columns)' . ($missing ? ': ' . implode(', ', $missing) : ''));
// the guard itself works: a registry without a known column is detected by the same comparison
$without = array_values(array_diff($regNames, ['rmm_job_extra.secret_params_enc']));
$ok(!in_array('rmm_job_extra.secret_params_enc', $without, true) && array_filter($scan, fn ($r) => $r['t'] . '.' . $r['c'] === 'rmm_job_extra.secret_params_enc') !== [], 'the schema scan sees rmm_job_extra.secret_params_enc, so removing it from the registry would fail the check above');

// ------------------------------------------------------------------ key file, stage on
KeyAdmin::generate($kf);
$use($kf);
KeyAdmin::addKey(true);          // the active key is NOT k1, so every legacy value has somewhere to go and k1 values must move too
SettingsCrypto::reset();
$active = (string) KeyStore::load()->ring->activeKid();
$svc = new RewrapService($db, 1, "$dir/state");
$snap = function () use ($db): string {
    $h = '';
    foreach (ColumnRegistry::all() as $cs) {
        if (!MysqliRewrapSource::exists($db, $cs)) { continue; }
        $r = $db->query("SELECT `{$cs->pk}`, `{$cs->column}` FROM `{$cs->table}` ORDER BY `{$cs->pk}`");
        while ($r && ($x = $r->fetch_row())) { $h .= md5(implode('|', $x)); }
    }
    return md5($h);
};
$before = $snap();
$dry = $svc->run(null, true);
$changedDry = array_sum(array_map(fn ($r) => $r->rewrapped, $dry));
$ok($changedDry >= $seeded && $snap() === $before, "dry run reports $changedDry value(s) to move and writes nothing");
$ok(!is_dir("$dir/state") || (glob("$dir/state/*.json") ?: []) === [], 'a dry run saves no progress');
$auditDry = (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='crypto.rewrap' AND action='dry_run'");
$ok($auditDry >= 1, 'a dry run is audited');

// resumable: stop after one batch of one item, then continue
$part = $svc->run('software_keys', false, 1, 1);
$q("DELETE FROM software_keys WHERE software_key_id BETWEEN 950 AND 954");
foreach ([950, 951, 952] as $i) { $q("INSERT INTO software_keys SET software_key_software_id=899, software_key_id=$i, software_key='" . $esc(encryptSetting("lic-$i")) . "'"); }
$part = $svc->run('software_keys', false, 1, 1);
$ok(count($part) === 1 && !$part[0]->completed && $part[0]->cursor !== null, 'stopping after one batch leaves a cursor');
$ok((glob("$dir/state/*.json") ?: []) !== [], 'progress is saved in a state file');
$rest = $svc->run('software_keys', false, 1);
$ok($rest[0]->completed && $rest[0]->failed === 0, 'a second run resumes and completes');

$real = $svc->run();
$bad = 0; foreach ($real as $r) { $bad += $r->failed + $r->conflicts; }
$ok($bad === 0, 'the full run has no failures or conflicts');
$again = $svc->run();
$ok(array_sum(array_map(fn ($r) => $r->rewrapped, $again)) === 0, 'a second full run changes nothing (idempotent)');

// every value reads back, on the active key, through the app's own readers
$allRead = true; $allActive = true; $detail = '';
foreach ($expect as $name => [$cs, $pkv, $plain]) {
    $col = $cs->column;
    $v = (string) $one("SELECT `$col` FROM `{$cs->table}` WHERE `{$cs->pk}` = " . (is_string($pkv) ? $pkv : (int) $pkv) . ($cs->pk2 !== null ? " AND `{$cs->pk2}` = 7" : ''));
    $got = decryptSetting($v, $cs->kind === 'canonical' ? SettingsCrypto::VAULT_CANONICAL_KEY : SettingsCrypto::GENERIC);
    if ($cs->name() === 'settings.config_slack_bot_token' || $cs->name() === 'settings.config_oidc_client_secret') { continue; }
    if ($got !== $plain) { $allRead = false; $detail .= " read:$name"; }
    if (!str_starts_with($v, 'v3:' . $active . ':')) { $allActive = false; $detail .= " active:$name"; }
}
$ok($allRead, 'every registered column reads back through decryptSetting' . $detail);
$ok($allActive, 'every registered column is now v3 under the active key' . $detail);
$ok(str_starts_with((string) $one("SELECT config_slack_bot_token FROM settings WHERE company_id=1"), 'v3:' . $active . ':') && decryptSetting((string) $one("SELECT config_slack_bot_token FROM settings WHERE company_id=1")) === 'xoxb-clear', 'a cleartext straggler column (slack token) is wrapped');
$ok($one("SELECT config_oidc_client_secret FROM settings WHERE company_id=1") === 'oidc-clear', 'cleartext in a column that still has raw readers is left alone');
$ta = (string) $one("SELECT user_token FROM users WHERE user_id=$ua");
$tb = (string) $one("SELECT user_token FROM users WHERE user_id=$ub");
$ok(str_starts_with($ta, 'v3:' . $active . ':') && secUserTotpSecret($ta, $ua) === 'SEEDAAAA' && secUserTotpSecret($ta, $ub) === '' && decryptSetting($ta) === '', 'TOTP seeds move to the totp purpose, bound to the user');
$ok(str_starts_with($tb, 'v3:') && secUserTotpSecret($tb, $ub) === 'SEEDBBBB', 'a cleartext TOTP seed is wrapped');
$auditRows = $rows("SELECT summary, metadata_json FROM audit_events WHERE event_type='crypto.rewrap'");
$leak = false; foreach ($auditRows as $a) { if (str_contains($a['summary'] . $a['metadata_json'], 'secret-') || str_contains($a['metadata_json'], 'v3:')) { $leak = true; } }
$ok(count($auditRows) >= $seeded && !$leak, 'one audit row per column and run, counts and ids only');

// ------------------------------------------------------------------ compare-and-set and the capacity guard
$spec = new ColumnSpec('software_keys', 'software_key_id', 'software_key', 'settings', true);
$src = new MysqliRewrapSource($db, $spec);
$cur = (string) $one("SELECT software_key FROM software_keys WHERE software_key_id=950");
$ok($src->replace('950', 'stale-expectation', 'x') === false && $one("SELECT software_key FROM software_keys WHERE software_key_id=950") === $cur, 'replace is compare-and-set: a stale expectation loses');
$q("DROP TABLE IF EXISTS crypto_narrow_tmp");
$q("CREATE TABLE crypto_narrow_tmp (id int NOT NULL, v varchar(20) DEFAULT NULL, PRIMARY KEY (id))");
$q("INSERT INTO crypto_narrow_tmp VALUES (1, 'short')");
$nsrc = new MysqliRewrapSource($db, new ColumnSpec('crypto_narrow_tmp', 'id', 'v', 'custom', false, true, fn ($i) => 'rec:' . $i));
$ok($nsrc->replace('1', 'short', str_repeat('y', 60)) === false && $one("SELECT v FROM crypto_narrow_tmp") === 'short', 'a value that does not fit the column is refused, never truncated');
$q("DROP TABLE crypto_narrow_tmp");
$off = (function () use ($db, $use) { $use(null); $s = new RewrapService($db, 1); try { $s->assertReady(); return false; } catch (\RuntimeException) { return true; } })();
$ok($off, 'the rewrap refuses to run while the v3 stage is off');
$use($kf);

// ------------------------------------------------------------------ schema widening (DB update 2.6.160)
$q("ALTER TABLE settings MODIFY config_smtp_password varchar(200) DEFAULT NULL");
$q("UPDATE settings SET config_smtp_password='keep-me' WHERE company_id=1");
$did = SchemaWidening::run($db);
$ok(($did['settings.config_smtp_password'] ?? '') === 'TEXT' && $one("SELECT config_smtp_password FROM settings WHERE company_id=1") === 'keep-me', 'widening turns a narrow nullable varchar into TEXT and keeps its data');
$ok(SchemaWidening::run($db) === [], 'and a second run changes nothing');
$q("UPDATE settings SET config_smtp_password = '" . $esc(encryptSetting('keep-me')) . "' WHERE company_id=1");
$types = $rows("SELECT column_name c, data_type t, character_maximum_length l FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='credentials' AND column_name IN ('credential_password','credential_username')");
$tm = array_column($types, 'l', 'c');
$ok((int) ($tm['credential_password'] ?? 0) >= 2048 && (int) ($tm['credential_username'] ?? 0) >= 1024, 'the vault columns are wide enough for the v3 field format');

// ------------------------------------------------------------------ the scripts
[$c1, $o1] = sec_sh('SCRATCH_KEYFILE=' . escapeshellarg($kf) . ' ' . PHP_BINARY . ' ' . escapeshellarg("$root/scripts/keys_cli.php") . ' status --json');
$j = json_decode($o1, true);
$ok($c1 === 0 && is_array($j) && count($j['keys']) === 2 && !str_contains($o1, bin2hex(random_bytes(1)) . 'x') && !preg_match('/"key"\s*:/', $o1), 'keys_cli status --json lists the keys and carries no key material');
[$c2, $o2] = sec_sh('SCRATCH_KEYFILE=' . escapeshellarg($kf) . ' ' . PHP_BINARY . ' ' . escapeshellarg("$root/scripts/rewrap_cli.php") . ' --status');
$ok($c2 === 0 && str_contains($o2, 'Nothing to do'), 'rewrap_cli --status reports nothing to do after the run');
[$c3, $o3] = sec_sh(PHP_BINARY . ' ' . escapeshellarg("$root/scripts/rewrap_cli.php") . ' --dry-run');
$ok($c3 === 2 && str_contains($o3, 'Refused'), 'rewrap_cli refuses without a key file (stage off), exit status 2');
[$c4, $o4] = sec_sh('SCRATCH_KEYFILE=' . escapeshellarg($kf) . ' ' . PHP_BINARY . ' ' . escapeshellarg("$root/scripts/keys_cli.php") . ' retire ' . escapeshellarg($active));
$ok($c4 === 1 && str_contains($o4, 'active key cannot be retired'), 'keys_cli retire refuses the active key');

$q("DELETE FROM software_keys WHERE software_key_id BETWEEN 900 AND 960");
foreach ($expect as [$cs, $pkv]) { if (!in_array($cs->table, ['settings', 'endpoint_agent_settings', 'recovery_settings'], true)) { $q("DELETE FROM `{$cs->table}` WHERE `{$cs->pk}` = $pkv"); } }
$q("DELETE FROM users WHERE user_name LIKE 'crw-%'");
$q("DELETE FROM rmm_job_extra WHERE job_id LIKE 'rw-%'"); $q("DELETE FROM rmm_custom_field_values WHERE scope_id = 7 AND field_id >= 900");
$q("DELETE FROM software WHERE software_id = 899");
foreach (ColumnRegistry::all() as $cs) { if ($cs->table === 'settings') { $q("UPDATE settings SET `{$cs->column}` = NULL WHERE company_id = 1"); } }
$q("UPDATE endpoint_agent_settings SET signing_private_key_enc = '', mesh_login_key_enc = '' WHERE id = 1");
$q("DELETE FROM recovery_settings WHERE setting_key = 'drill_db_pass'");
$use(null);
foreach (glob("$dir/state/*") ?: [] as $f) { @unlink($f); } @rmdir("$dir/state");
foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); } @rmdir($dir);
