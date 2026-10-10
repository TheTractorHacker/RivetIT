<?php
/*
 * Wave 1 security, item 1: backups.
 *   - the in-app zip refuses to build without a backup passphrase (16+ characters) and writes nothing
 *   - the settings key is written only inside the passphrase-encrypted manifest; a manifest that is not encrypted holds a fingerprint only
 *   - restore paths: a new zip, an OLD-format zip (plaintext manifest carrying the key), a fingerprint-only manifest + supplied key
 *     (setup/setup_functions.php applyManifestSettingsEncKey and deploy/lib/common.sh read_manifest_dir)
 *   - deploy/backup.sh: -iter 600000, key file separate (0600, not inside the archive), fingerprint-only manifest;
 *     deploy/restore.sh decrypts both a 600000-iteration archive and an OLD default-iteration archive
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/security_backup.php
 */
require __DIR__ . '/support/security_lib.php';
define('FROM_POST_HANDLER', true);
chdir("$root/admin");                       // post/backup.php includes ../includes/app_version.php relative to admin/
require_once "$root/admin/post/backup.php";
require_once "$root/setup/setup_functions.php";

$tmp = sys_get_temp_dir() . '/sec_backup_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
register_shutdown_function(function () use ($tmp) { sec_sh('rm -rf ' . escapeshellarg($tmp)); });

$KEY = bin2hex(random_bytes(32));
$GLOBALS['config_settings_enc_key'] = $KEY;
$config_settings_enc_key = $KEY;
$FP = backup_settings_key_fingerprint($KEY);
$PASS = 'correct horse battery staple 99';

// ------------------------------------------------------------------ refusal
$GLOBALS['config_backup_passphrase'] = '';
$ok(backup_passphrase_or_null() === null, 'no passphrase: not usable');
$before = glob("$tmp/*") ?: [];
$threw = false;
try { build_backup($db, 'manual', $tmp); } catch (BackupPassphraseRequired $e) { $threw = true; $msg = $e->getMessage(); }
$ok($threw, 'build_backup refuses without a passphrase');
$ok($threw && str_contains($msg, '16 characters'), 'the refusal message names the 16 character minimum');
$ok(count(glob("$tmp/*") ?: []) === 0, 'a refused backup leaves no zip behind');

$GLOBALS['config_backup_passphrase'] = str_repeat('a', 15);
$ok(backup_passphrase_or_null() === null, '15 characters is too short');
$threw = false; try { build_backup($db, 'manual', $tmp); } catch (BackupPassphraseRequired $e) { $threw = true; }
$ok($threw, 'a 15 character passphrase is refused');
$GLOBALS['config_backup_passphrase'] = str_repeat('a', 16);
$ok(backup_passphrase_or_null() === str_repeat('a', 16), '16 characters is accepted');

// ------------------------------------------------------------------ a real backup with a passphrase
$GLOBALS['config_backup_passphrase'] = $PASS;
$r = build_backup($db, 'manual', $tmp);
$ok(is_file($r['path']) && str_ends_with($r['name'], '.zip'), 'a backup zip is built when a passphrase is set');
$zip = new ZipArchive(); $zip->open($r['path']);
$names = []; for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
$ok(in_array('backup-manifest.json.enc', $names, true) && !in_array('backup-manifest.json', $names, true), 'the zip carries only the ENCRYPTED manifest');
$ext = "$tmp/ext"; mkdir($ext); $zip->extractTo($ext); $zip->close();
$leak = false;
foreach (glob("$ext/*") as $f) { if ($f !== "$ext/backup-manifest.json.enc" && str_contains((string) file_get_contents($f), $KEY)) { $leak = true; } }
$ok(!$leak, 'the settings key appears in no other entry of the zip (db.sql, uploads.zip, version.txt)');
$ok(!str_contains((string) file_get_contents("$ext/backup-manifest.json.enc"), $KEY), 'the key is not readable in the encrypted manifest file');
file_put_contents("$tmp/pass", $PASS);
[$c, $o] = sec_sh("openssl enc -d -aes-256-cbc -pbkdf2 -salt -in " . escapeshellarg("$ext/backup-manifest.json.enc") . " -pass file:" . escapeshellarg("$tmp/pass"));
$m = json_decode($o, true);
$ok($c === 0 && is_array($m) && ($m['settings_enc_key'] ?? '') === $KEY, 'the passphrase decrypts the manifest and it holds the settings key');
$ok(($m['settings_enc_key_fingerprint'] ?? '') === $FP && $FP !== '' && strlen($FP) === 16, 'the manifest carries the key fingerprint');
$ok(str_contains((string) file_get_contents("$ext/version.txt"), 'encrypted with the saved backup passphrase'), 'version.txt says the manifest is encrypted');

// ------------------------------------------------------------------ manifest builder without a passphrase: fingerprint only
$pm = build_backup_manifest($db, 'sec_plain', null);
$raw = (string) file_get_contents($pm['path']); @unlink($pm['path']);
$pj = json_decode($raw, true);
$ok($pm['name'] === 'backup-manifest.json' && !str_contains($raw, $KEY) && !isset($pj['settings_enc_key']) && ($pj['settings_enc_key_fingerprint'] ?? '') === $FP, 'an unencrypted manifest holds the fingerprint and never the key');

// ------------------------------------------------------------------ restore: setup / admin zip restore helper (PHP side)
$mkcfg = function () use ($tmp): string { $p = $tmp . '/config_' . bin2hex(random_bytes(3)) . '.php'; file_put_contents($p, "<?php\n\$config_settings_enc_key = 'oldoldold';\n"); return $p; };
$mkdir = function (string $manifestName, string $manifestBody) use ($tmp): string { $d = $tmp . '/m_' . bin2hex(random_bytes(3)); mkdir($d); file_put_contents("$d/$manifestName", $manifestBody); return $d; };

// new zip: encrypted manifest + passphrase
$cfg = $mkcfg();
$res = applyManifestSettingsEncKey($ext, $PASS, $cfg);
$ok($res['status'] === 'applied' && str_contains((string) file_get_contents($cfg), $KEY), 'restore of a NEW zip with the passphrase applies the key');
$cfg = $mkcfg();
$ok(applyManifestSettingsEncKey($ext, null, $cfg)['status'] === 'passphrase_needed', 'restore of a new zip without the passphrase asks for it');
$ok(applyManifestSettingsEncKey($ext, 'wrong wrong wrong wrong', $cfg)['status'] === 'decrypt_failed', 'restore with a wrong passphrase fails cleanly');

// OLD-format zip: plaintext manifest with the key in it (what every pre-change backup without a passphrase looks like)
$old = $mkdir('backup-manifest.json', json_encode(['schema_version' => 1, 'db_name' => 'x', 'installation_id' => 'y', 'settings_enc_key' => $KEY, 'backup_timestamp' => '20260101000000']));
$cfg = $mkcfg();
$res = applyManifestSettingsEncKey($old, null, $cfg);
$ok($res['status'] === 'applied' && str_contains((string) file_get_contents($cfg), $KEY), 'restore of an OLD-format zip (plaintext manifest with the key) still works');
// OLD-format encrypted manifest (default openssl pbkdf2 iterations, key inside, no fingerprint)
$oldEncDir = $tmp . '/oldenc'; mkdir($oldEncDir);
file_put_contents("$oldEncDir/plain.json", json_encode(['schema_version' => 1, 'settings_enc_key' => $KEY]));
sec_sh("openssl enc -aes-256-cbc -pbkdf2 -salt -in " . escapeshellarg("$oldEncDir/plain.json") . " -out " . escapeshellarg("$oldEncDir/backup-manifest.json.enc") . " -pass file:" . escapeshellarg("$tmp/pass"));
unlink("$oldEncDir/plain.json");
$cfg = $mkcfg();
$ok(applyManifestSettingsEncKey($oldEncDir, $PASS, $cfg)['status'] === 'applied', 'restore of an OLD-format encrypted manifest still works');

// fingerprint-only manifest: the operator supplies the key
$fpDir = $mkdir('backup-manifest.json', json_encode(['schema_version' => 2, 'settings_enc_key_fingerprint' => $FP]));
$cfg = $mkcfg();
$res = applyManifestSettingsEncKey($fpDir, null, $cfg);
$ok($res['status'] === 'no_key' && str_contains($res['message'], $FP) && str_contains((string) file_get_contents($cfg), 'oldoldold'), 'fingerprint-only manifest without a key: config.php left alone, the message names the fingerprint');
$res = applyManifestSettingsEncKey($fpDir, null, $cfg, $KEY);
$ok($res['status'] === 'applied' && str_contains((string) file_get_contents($cfg), $KEY), 'fingerprint-only manifest + the right key supplied: applied');
$cfg = $mkcfg();
$res = applyManifestSettingsEncKey($fpDir, null, $cfg, bin2hex(random_bytes(32)));
$ok($res['status'] === 'key_mismatch' && str_contains((string) file_get_contents($cfg), 'oldoldold'), 'a supplied key that does not match the fingerprint is refused');
$ok(applyManifestSettingsEncKey($fpDir, null, $cfg, 'not hex!')['status'] === 'bad_supplied_key', 'a supplied key that is not hex is refused');

// ------------------------------------------------------------------ shell: deploy/lib/common.sh read_manifest_dir
$rm = function (string $dir, string $extra = '') use ($root, $tmp): array {
    $cmd = 'bash -c ' . escapeshellarg('source ' . escapeshellarg("$root/deploy/lib/common.sh") . "; $extra read_manifest_dir " . escapeshellarg($dir) . " " . escapeshellarg("$tmp/pass") . '; echo "KEY=[$MANIFEST_SETTINGS_ENC_KEY] FP=[$MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT]"');
    return sec_sh($cmd);
};
[$c, $o] = $rm($ext);
$ok($c === 0 && str_contains($o, "KEY=[$KEY]") && str_contains($o, "FP=[$FP]"), 'shell: a new zip with the passphrase yields the key and fingerprint');
[$c, $o] = $rm($old);
$ok($c === 0 && str_contains($o, "KEY=[$KEY]"), 'shell: an OLD-format plaintext manifest still yields the key');
[$c, $o] = $rm($fpDir);
$ok($c === 0 && str_contains($o, 'KEY=[]') && str_contains($o, $FP) && str_contains($o, '--settings-key-file'), 'shell: fingerprint-only manifest: no key, warns and names --settings-key-file');
file_put_contents("$tmp/sk", "# RivetIT settings-encryption key\n# fingerprint: $FP\n$KEY\n"); chmod("$tmp/sk", 0600);
[$c, $o] = $rm($fpDir, 'SETTINGS_KEY_FILE=' . escapeshellarg("$tmp/sk") . ';');
$ok($c === 0 && str_contains($o, "KEY=[$KEY]"), 'shell: --settings-key-file supplies the key for a fingerprint-only manifest');
file_put_contents("$tmp/sk_bad", "# x\n" . bin2hex(random_bytes(32)) . "\n"); chmod("$tmp/sk_bad", 0600);
[$c, $o] = $rm($fpDir, 'SETTINGS_KEY_FILE=' . escapeshellarg("$tmp/sk_bad") . ';');
$ok($c !== 0 && str_contains($o, 'does not match this backup'), 'shell: a key file that does not match the fingerprint is refused');

// ------------------------------------------------------------------ deploy/backup.sh: static properties + key file
$bsh = (string) file_get_contents("$root/deploy/backup.sh");
$ok(str_contains($bsh, 'BACKUP_PBKDF2_ITER=600000') && str_contains($bsh, '-pbkdf2 -iter "${BACKUP_PBKDF2_ITER}"'), 'backup.sh encrypts with -iter 600000');
$ok(!preg_match('/"settings_enc_key"\s*=>/', $bsh), 'backup.sh no longer writes settings_enc_key into the manifest');
$sh = function (string $script) use ($root): array {
    return sec_sh('bash -c ' . escapeshellarg('set -euo pipefail; source ' . escapeshellarg("$root/deploy/backup.sh") . '; ' . $script));
};
$kf = "$tmp/backup-x-20260101T000000Z.settings-key";
[$c, $o] = $sh('SETTINGS_ENC_KEY=' . escapeshellarg($KEY) . '; write_settings_key_file ' . escapeshellarg($kf));
$ok($c === 0 && is_file($kf) && (fileperms($kf) & 0777) === 0600, 'backup.sh writes the settings key to a separate file with mode 0600');
$kc = (string) file_get_contents($kf);
$ok(str_contains($kc, $KEY) && str_contains($kc, "fingerprint: $FP"), 'the key file holds the key and its fingerprint');
// ...and restore.sh's reader accepts exactly that file
$rk = sec_sh('bash -c ' . escapeshellarg('source ' . escapeshellarg("$root/deploy/lib/common.sh") . '; load_settings_key_file ' . escapeshellarg($kf) . ' ' . escapeshellarg($FP) . '; echo "KEY=[$MANIFEST_SETTINGS_ENC_KEY]"'));
$ok($rk[0] === 0 && str_contains($rk[1], "KEY=[$KEY]"), 'restore reads the key file backup.sh wrote and checks the fingerprint');

// ------------------------------------------------------------------ deploy/restore.sh: decrypt a new (600000) and an OLD (default iterations) archive
$build = function (string $iterArgs, string $name) use ($tmp, $PASS): string {
    $d = "$tmp/pl_$name"; mkdir($d); file_put_contents("$d/backup-x.sql", "-- dump\n"); file_put_contents("$d/backup-manifest.json", '{"schema_version":2}');
    sec_sh("tar -czf " . escapeshellarg("$tmp/$name.tar.gz") . " -C " . escapeshellarg($d) . " backup-x.sql backup-manifest.json");
    sec_sh("openssl enc -aes-256-cbc -pbkdf2 $iterArgs -salt -in " . escapeshellarg("$tmp/$name.tar.gz") . " -out " . escapeshellarg("$tmp/$name.tar.gz.enc") . " -pass file:" . escapeshellarg("$tmp/pass"));
    return "$tmp/$name.tar.gz.enc";
};
$newArc = $build('-iter 600000', 'newarc');
$oldArc = $build('', 'oldarc');
$dec = function (string $arc, string $passFile) use ($root): array {
    return sec_sh('bash -c ' . escapeshellarg('set -euo pipefail; source ' . escapeshellarg("$root/deploy/restore.sh") . '; BACKUP_FILE=' . escapeshellarg($arc) . '; PASSPHRASE_FILE=' . escapeshellarg($passFile) . '; decrypt_and_extract; echo "ITER=[$DECRYPTED_WITH] SQL=[$(basename "$SQL_FILE")]"'));
};
[$c, $o] = $dec($newArc, "$tmp/pass");
$ok($c === 0 && str_contains($o, 'ITER=[600000]') && str_contains($o, 'SQL=[backup-x.sql]'), 'restore.sh decrypts an archive made with -iter 600000');
[$c, $o] = $dec($oldArc, "$tmp/pass");
$ok($c === 0 && str_contains($o, 'ITER=[default]') && str_contains($o, 'SQL=[backup-x.sql]'), 'restore.sh falls back and decrypts an OLD-format archive (default iterations)');
file_put_contents("$tmp/wrongpass", 'not the passphrase at all'); chmod("$tmp/wrongpass", 0600);
[$c, $o] = $dec($newArc, "$tmp/wrongpass");
$ok($c !== 0 && str_contains($o, 'Decryption failed'), 'restore.sh with a wrong passphrase fails with a clear message');
$ok(str_contains((string) file_get_contents("$root/deploy/restore.sh"), '--settings-key-file'), 'restore.sh accepts --settings-key-file');
