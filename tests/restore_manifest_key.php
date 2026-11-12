<?php
declare(strict_types=1);
/*
 * IT-WEB-4 regression: restore must not let a backup manifest inject PHP into config.php.
 * Covers the in-app path (applyManifestSettingsEncKey) and the shell path (deploy/lib/common.sh).
 * Needs no database. Works only in throwaway temp dirs; never touches a real config.php.
 *   php tests/restore_manifest_key.php
 */
$root = dirname(__DIR__);
require_once $root . '/setup/setup_functions.php';
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails): void { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$base = sys_get_temp_dir() . '/restore_key_test_' . bin2hex(random_bytes(4));
mkdir($base, 0700);
register_shutdown_function(function () use ($base) { exec('rm -rf ' . escapeshellarg($base)); });

$origKey  = str_repeat('a', 64);
$goodKey  = bin2hex(random_bytes(32));
$evilKey  = "aa'; file_put_contents(__DIR__ . '/PWNED', 'x'); \$x='";
$mkConfig = function (string $dir) use ($origKey): string {
    $p = "$dir/config.php";
    file_put_contents($p, "<?php\n\$database = 'x';\n\$config_settings_enc_key = '$origKey';\n");
    return $p;
};
$mkDir = function (string $name) use ($base): string { $d = "$base/$name"; mkdir($d, 0700); return $d; };
$encrypt = function (string $dir, array $manifest, string $pass) use ($base): void {
    file_put_contents("$base/pt.json", json_encode($manifest));
    file_put_contents("$base/pw", $pass);
    exec(sprintf('openssl enc -aes-256-cbc -pbkdf2 -salt -in %s -out %s -pass file:%s 2>&1', escapeshellarg("$base/pt.json"), escapeshellarg("$dir/backup-manifest.json.enc"), escapeshellarg("$base/pw")));
};
$runs = function (string $cfg): bool { // does including config.php execute anything beyond the assignments?
    exec(sprintf('php -r %s 2>&1', escapeshellarg('include ' . var_export($cfg, true) . ';')), $o, $rc);
    return !is_file(dirname($cfg) . '/PWNED') && $rc === 0;
};

// 1. plaintext manifest with a hostile key is refused, config.php unchanged and no payload runs
$d = $mkDir('t1'); $cfg = $mkConfig($d);
file_put_contents("$d/backup-manifest.json", json_encode(['settings_enc_key' => $evilKey]));
$r = applyManifestSettingsEncKey($d, null, $cfg);
$ok($r['status'] === 'bad_key', 'hostile plaintext key rejected (status ' . $r['status'] . ')');
$ok(str_contains((string) file_get_contents($cfg), $origKey) && !str_contains((string) file_get_contents($cfg), 'PWNED'), 'config.php untouched');
$ok($runs($cfg), 'including config.php runs no payload');

// 2. key with trailing newline is refused (the $ vs \z trap)
$d = $mkDir('t2'); $cfg = $mkConfig($d);
file_put_contents("$d/backup-manifest.json", json_encode(['settings_enc_key' => $goodKey . "\n"]));
$ok(applyManifestSettingsEncKey($d, null, $cfg)['status'] === 'bad_key', 'key with trailing newline rejected');

// 3. a valid plaintext key still applies (no regression for legit backups)
$d = $mkDir('t3'); $cfg = $mkConfig($d);
file_put_contents("$d/backup-manifest.json", json_encode(['settings_enc_key' => $goodKey]));
$r = applyManifestSettingsEncKey($d, null, $cfg);
$ok($r['status'] === 'applied' && str_contains((string) file_get_contents($cfg), "= '$goodKey';"), 'valid hex key still applied');

// 4. encrypted manifest wins over a plaintext one placed next to it
$d = $mkDir('t4'); $cfg = $mkConfig($d);
$encrypt($d, ['settings_enc_key' => $goodKey], 'pp-test');
file_put_contents("$d/backup-manifest.json", json_encode(['settings_enc_key' => $evilKey]));
$r = applyManifestSettingsEncKey($d, 'pp-test', $cfg);
$ok($r['status'] === 'applied' && str_contains((string) file_get_contents($cfg), "= '$goodKey';"), 'encrypted manifest used, plaintext ignored');
$ok($runs($cfg), 'no payload ran');
$d = $mkDir('t4b'); $cfg = $mkConfig($d);
$encrypt($d, ['settings_enc_key' => $goodKey], 'pp-test');
file_put_contents("$d/backup-manifest.json", json_encode(['settings_enc_key' => $evilKey]));
$r = applyManifestSettingsEncKey($d, null, $cfg);
$ok($r['status'] === 'passphrase_needed', 'enc present + no passphrase -> passphrase_needed, plaintext not used');

// 5. shell path
$sh = function (string $dir, string $script) use ($root): string {
    $cmd = sprintf('bash -c %s 2>&1', escapeshellarg('source ' . escapeshellarg("$root/deploy/lib/common.sh") . ' >/dev/null 2>&1; ' . $script));
    return (string) shell_exec($cmd);
};
$d = $mkDir('s1'); $cfg = $mkConfig($d);
file_put_contents("$d/backup-manifest.json", json_encode(['settings_enc_key' => $evilKey]));
$out = $sh($d, 'read_manifest_dir ' . escapeshellarg($d) . '; apply_settings_enc_key ' . escapeshellarg($cfg) . ' "$MANIFEST_SETTINGS_ENC_KEY"; echo "KEYVAR=[$MANIFEST_SETTINGS_ENC_KEY]"');
$ok(str_contains($out, 'KEYVAR=[]'), 'shell: hostile manifest key dropped');
$ok(str_contains((string) file_get_contents($cfg), $origKey) && $runs($cfg), 'shell: config.php untouched, no payload');

$d = $mkDir('s2'); $cfg = $mkConfig($d);
$sh($d, 'apply_settings_enc_key ' . escapeshellarg($cfg) . ' ' . escapeshellarg($evilKey));
$ok(str_contains((string) file_get_contents($cfg), $origKey) && $runs($cfg), 'shell: apply_settings_enc_key refuses a non-hex key directly');

$d = $mkDir('s3'); $cfg = $mkConfig($d);
$encrypt($d, ['settings_enc_key' => $goodKey], 'pp-test');
file_put_contents("$d/backup-manifest.json", json_encode(['settings_enc_key' => $evilKey]));
$sh($d, 'read_manifest_dir ' . escapeshellarg($d) . ' ' . escapeshellarg("$base/pw") . '; apply_settings_enc_key ' . escapeshellarg($cfg) . ' "$MANIFEST_SETTINGS_ENC_KEY"');
$ok(str_contains((string) file_get_contents($cfg), "= '$goodKey';") && $runs($cfg), 'shell: encrypted manifest preferred over plaintext');

$d = $mkDir('s4'); $cfg = $mkConfig($d);
file_put_contents("$d/backup-manifest.json", json_encode(['settings_enc_key' => $goodKey]));
$sh($d, 'read_manifest_dir ' . escapeshellarg($d) . '; apply_settings_enc_key ' . escapeshellarg($cfg) . ' "$MANIFEST_SETTINGS_ENC_KEY"');
$ok(str_contains((string) file_get_contents($cfg), "= '$goodKey';"), 'shell: valid key still applied');

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
