<?php
/*
 * The Keys panel (Administration > Security > Encryption keys) and its actions over HTTP, real pages with forged sessions:
 *   - renders for an administrator with kids, fingerprints, rewrap status; never any key material
 *   - rewrap (dry run and real), add key, make active, retire: CSRF checked, administrators only, audited
 *   - a non-administrator and a request without the CSRF token change nothing
 *   - without a key file the panel shows the one-time step instead of buttons that cannot work
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/crypto_panel.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_crypto.php";

use ITFlow\Crypto\KeyAdmin;
use ITFlow\Crypto\KeyStore;
use ITFlow\Crypto\SettingsCrypto;

$dir = sys_get_temp_dir() . '/crypto-panel-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$kf = "$dir/keys.json";
$GLOBALS['config_keyfile'] = '';
SettingsCrypto::reset();
foreach (\ITFlow\Crypto\ColumnRegistry::all() as $cs) { if ($cs->table === 'settings') { $q("UPDATE settings SET `{$cs->column}` = NULL WHERE company_id = 1"); } }
$q("DELETE FROM audit_events WHERE event_type LIKE 'crypto.%'");
$q("DELETE FROM users WHERE user_id IN (721,722)");
$q("DELETE FROM user_roles WHERE role_id IN (97,98)");
$q("INSERT INTO user_roles SET role_id=97, role_name='Crypto Panel Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO user_roles SET role_id=98, role_name='Crypto Panel Tech', role_is_admin=0, role_type=1");
foreach ([721 => [97, 'padmin'], 722 => [98, 'ptech']] as $id => [$role, $tag]) {
    $q("INSERT INTO users SET user_id=$id, user_name='Panel $tag', user_email='crypto-panel-$tag@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role");
    $q("INSERT INTO user_settings SET user_id=$id ON DUPLICATE KEY UPDATE user_id=user_id");
}
// an ENC2 value outside the settings row (the page view moves the settings row lazily; this one waits for the rewrap)
$q("DELETE FROM webhooks WHERE webhook_id=7210");
$q("INSERT INTO webhooks SET webhook_id=7210, webhook_name='crypto-panel', webhook_url='https://example.test/hook', webhook_secret='" . $esc(encryptSetting('panel-smtp')) . "'");
$hooksecret = fn () => (string) $one("SELECT webhook_secret FROM webhooks WHERE webhook_id=7210");

$sdir = sys_get_temp_dir() . '/crypto_panel_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir, $dir) {
    foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir);
    foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); } @rmdir($dir);
});
putenv('SCRATCH_KEYFILE=' . $kf);
$base = sec_start_server($sdir);
$csrf = 'csrftok12345678';
$sess = fn (int $uid) => sec_forge_session($sdir, ['logged' => true, 'user_id' => $uid, 'csrf_token' => $csrf, 'sec_created' => time(), 'sec_last' => time()]);
$admin = $sess(721); $tech = $sess(722);
$ref = ['Referer: ' . $base . '/admin/settings_security.php'];
$post = fn (string $sid, array $f) => sec_web($base, 'POST', '/admin/post.php', $sid, $f, [], $ref);

// no key file yet
[$c, $body] = sec_web($base, 'GET', '/admin/settings_security.php', $admin);
$ok($c === 200 && str_contains($body, 'Encryption keys') && str_contains($body, 'config.php key only') && str_contains($body, 'keys_cli.php generate'), 'no key file: the panel says so and shows the one-time step');
$ok(!str_contains($body, 'name="keys_action" value="add_key"') && !str_contains($body, 'value="rewrap"'), 'and offers no buttons that cannot work');

KeyAdmin::generate($kf);
SettingsCrypto::reset();
$GLOBALS['config_keyfile'] = $kf; SettingsCrypto::reset();
$k1 = KeyStore::load()->ring->activeKid();
[$c, $body] = sec_web($base, 'GET', '/admin/settings_security.php', $admin);
$ok($c === 200 && str_contains($body, '>' . $k1 . '<') && str_contains($body, 'key file') && str_contains($body, 'value="rewrap_dry"') && str_contains($body, 'value="rewrap"'), 'with a key file: the kid, the source and the rewrap buttons');
$ok(str_contains($body, 'add_key') , 'and the web user may rotate (the test key file is writable)');
$hex = bin2hex(KeyStore::load()->ring->key($k1));
$ok(!str_contains($body, $hex) && !str_contains($body, base64_encode(KeyStore::load()->ring->key($k1))) && !str_contains($body, $GLOBALS['config_settings_enc_key']), 'the page contains no key material');
$ok(str_contains($body, '1 value(s) are not on the active key yet') || str_contains($body, 'value(s) are not on the active key yet'), 'it counts the value still on the older form');

// actions
[$c] = $post($admin, ['keys_action' => 'add_key']);
$ok(count(KeyAdmin::status()['keys']) === 1, 'add key without the CSRF token changes nothing');
[$c] = $post($tech, ['keys_action' => 'add_key', 'csrf_token' => $csrf]);
SettingsCrypto::reset();
$ok(count(KeyAdmin::status()['keys']) === 1, 'a non-administrator cannot add a key');
[$c] = $post($admin, ['keys_action' => 'rewrap_dry', 'csrf_token' => $csrf]);
$ok(!str_starts_with($hooksecret(), 'v3:') && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='crypto.rewrap_requested' AND action='dry_run'") >= 1, 'a dry run writes nothing and is audited');
[$c] = $post($admin, ['keys_action' => 'rewrap', 'csrf_token' => $csrf]);
$ok(str_starts_with($hooksecret(), 'v3:' . $k1 . ':') && decryptSetting($hooksecret()) === 'panel-smtp', 'the rewrap moves the value to v3');
[$c] = $post($admin, ['keys_action' => 'add_key', 'csrf_token' => $csrf]);
SettingsCrypto::reset();
$st = KeyAdmin::status();
$ok(count($st['keys']) === 2 && $st['active'] !== $k1, 'add key adds a second key and makes it active');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='crypto.key_added' AND actor_user_id=721") === 1, 'and is audited with the actor');
$audit = json_encode($rows("SELECT summary, metadata_json FROM audit_events WHERE event_type LIKE 'crypto.%'"));
$ok(!str_contains($audit, $hex) && !str_contains($audit, 'panel-smtp'), 'the audit rows carry no key material or secret');
[$c] = $post($admin, ['keys_action' => 'retire', 'csrf_token' => $csrf, 'kid' => $k1]);
SettingsCrypto::reset();
$ok(count(KeyAdmin::status()['keys']) === 2, 'retire is refused while the old key is still in use');
[$c] = $post($admin, ['keys_action' => 'rewrap', 'csrf_token' => $csrf]);
[$c] = $post($admin, ['keys_action' => 'rewrap', 'csrf_token' => $csrf]);
[$c] = $post($admin, ['keys_action' => 'retire', 'csrf_token' => $csrf, 'kid' => $k1]);
SettingsCrypto::reset();
$ok(count(KeyAdmin::status()['keys']) === 1 && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='crypto.key_retired'") === 1, 'after the rewrap the old key can be retired');
$ok(decryptSetting($hooksecret()) === 'panel-smtp', 'and the value still reads');
[$c] = $post($admin, ['keys_action' => 'set_active', 'csrf_token' => $csrf, 'kid' => 'k-does-not-exist']);
$ok(KeyAdmin::status()['active'] === KeyStore::load()->ring->activeKid(), 'an unknown key id changes nothing');

$q("DELETE FROM webhooks WHERE webhook_id=7210");
$q("UPDATE settings SET config_smtp_password=NULL WHERE company_id=1");
$q("DELETE FROM users WHERE user_id IN (721,722)");
$q("DELETE FROM user_settings WHERE user_id IN (721,722)");
