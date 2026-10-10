<?php
/*
 * Wave 1 integration: the recovery restore drill against backups made by the SECURITY-hardened backup code, end to end.
 *
 *   in-app zip   admin/post/backup.php build_backup() (passphrase required, key only inside backup-manifest.json.enc, table-snapshot.json
 *                added by the recovery hook) -> RestoreDrill::run()
 *   encrypted    deploy/backup.sh do_backup() (-iter 600000, fingerprint-only manifest, the key in a separate .settings-key file)
 *                -> deploy/restore_drill.sh (decrypts with the passphrase file, finds the .settings-key file next to the archive)
 *                -> cron/restore_drill.php, which restores into a drill_% scratch database and records the result.
 *
 * Scratch database only (same harness as the security suites); the drill account is a second user that may only touch drill_% databases:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... [RIVETIT_TEST_DB_SOCKET=/path/sock] \
 *   RIVETIT_TEST_DRILL_USER=... RIVETIT_TEST_DRILL_PASS=... php tests/recovery_integration.php
 * RIVETIT_TEST_DB_SOCKET (when the scratch server is not the default one) is handed to mysqldump and the drill (see below).
 *
 * deploy/restore_drill.sh and deploy/backup.sh insist on running as root. The test runs them as the current user by (a) sourcing backup.sh and
 * calling its do_backup()/backup_post_steps() with chown stubbed out, and (b) running a copy of deploy/ whose single `require_root` line is
 * neutralised. Everything else is the real script text.
 */
require_once __DIR__ . '/support/security_lib.php';
define('FROM_POST_HANDLER', true);
chdir("$root/admin");                       // post/backup.php includes ../includes/app_version.php relative to admin/
require_once "$root/admin/post/backup.php";

$drillUser = getenv('RIVETIT_TEST_DRILL_USER'); $drillPass = getenv('RIVETIT_TEST_DRILL_PASS');
if (!$drillUser) { echo "SKIP  needs RIVETIT_TEST_DRILL_USER / RIVETIT_TEST_DRILL_PASS (the drill_% scoped account)\n"; exit(0); }
$sock = getenv('RIVETIT_TEST_DB_SOCKET') ?: null;

$tmp = sys_get_temp_dir() . '/recovery-int-' . bin2hex(random_bytes(4));
mkdir($tmp . '/zips', 0700, true); mkdir($tmp . '/enc', 0700, true);
$PASS = 'integration-passphrase-0123456789';
file_put_contents("$tmp/pass", $PASS); chmod("$tmp/pass", 0600);
$KEY = (string) $GLOBALS['config_settings_enc_key'];
$ok($KEY !== '', 'the scratch config has a settings key');
$FP = backup_settings_key_fingerprint($KEY);
$drillDbs = function () use ($drillUser, $drillPass, $sock) {
    $d = new mysqli('localhost', $drillUser, $drillPass, null, 3306, $sock); $r = $d->query("SHOW DATABASES LIKE 'drill\\_%'"); $o = []; while ($r && ($x = $r->fetch_row())) { $o[] = $x[0]; } return $o;
};
$checkOf = function (array $checks, string $id) { foreach ($checks as $c) { if ($c['id'] === $id) { return $c['status']; } } return 'missing'; };

// Recognisable data: 30 clients and a real, wrapped secret (so "sample secret decrypts" has something to decrypt).
$q("SET SESSION sql_mode = ''");
$q("DELETE FROM clients");
for ($i = 1; $i <= 30; $i++) { $q("INSERT INTO clients (client_name, client_currency_code, client_created_at) VALUES ('Int Client $i', 'USD', NOW())"); }
$q("UPDATE settings SET config_smtp_password = '" . $esc(encryptSetting('integration-smtp-secret')) . "' WHERE company_id = 1");
$q("DELETE FROM recovery_settings"); $q("DELETE FROM restore_drill_log"); $q("DELETE FROM backup_runs");
$q("INSERT INTO recovery_settings (setting_key, setting_value) VALUES ('drill_db_user', '" . $esc($drillUser) . "'), ('drill_db_pass', '" . $esc($drillPass) . "'), ('drill_enabled', '1')");

// ---------------------------------------------------------------- in-app zip: refuses without a passphrase, then builds with one
$GLOBALS['config_backup_passphrase'] = '';
$threw = false; try { build_backup($db, 'manual', "$tmp/zips"); } catch (BackupPassphraseRequired $e) { $threw = true; }
$ok($threw && (glob("$tmp/zips/*.zip") ?: []) === [], 'build_backup() without a passphrase refuses and leaves no zip behind');
$GLOBALS['config_backup_passphrase'] = $PASS;
$res = build_backup($db, 'auto', "$tmp/zips");
$z = new ZipArchive(); $z->open($res['path']);
$names = []; for ($i = 0; $i < $z->numFiles; $i++) { $names[] = $z->getNameIndex($i); }
$z->close();
$ok(in_array('backup-manifest.json.enc', $names) && !in_array('backup-manifest.json', $names) && in_array('table-snapshot.json', $names), 'the integrated build_backup() zip has the encrypted manifest AND the recovery table snapshot');
$run = $rows("SELECT * FROM backup_runs ORDER BY run_id DESC LIMIT 1")[0] ?? [];
$ok(($run['run_ok'] ?? '') === '1' && ($run['run_kind'] ?? '') === 'app_auto' && ($run['run_file'] ?? '') === $res['name'], 'the status hook recorded the run');

$opts = ['trigger' => 'script', 'user' => $drillUser, 'pass' => $drillPass, 'socket' => $sock, 'live_user' => getenv('RIVETIT_TEST_DB_USER'),
    'backup_dir' => "$tmp/zips", 'work_dir' => $tmp, 'uploads_sample' => 4];
$r = (new \ITFlow\Recovery\RestoreDrill($db, $root, $opts))->run();
if ($r['status'] !== 'pass') { foreach ($r['checks'] as $c) { echo "      [{$c['status']}] {$c['label']}: {$c['detail']}\n"; } }
$ok($r['status'] === 'pass' && $r['backup_kind'] === 'zip', 'DRILL on the integrated build_backup() zip passes: ' . $r['message']);
$ok($checkOf($r['checks'], 'key') === 'pass' && $checkOf($r['checks'], 'secret') === 'pass' && $checkOf($r['checks'], 'row_counts') === 'pass' && $checkOf($r['checks'], 'sha_db') === 'pass',
    'key (read from the encrypted manifest), sample secret, row counts and checksums all pass');
$r = (new \ITFlow\Recovery\RestoreDrill($db, $root, ['passphrase' => 'wrong-passphrase-wrong'] + $opts))->run();
$ok($r['status'] === 'fail' && $checkOf($r['checks'], 'key') === 'fail', 'with the wrong passphrase the drill cannot read the manifest and fails (the passphrase is part of recoverability)');
$ok($drillDbs() === [], 'no drill_% database is left behind');

// ---------------------------------------------------------------- deploy/backup.sh archive -> deploy/restore_drill.sh
// The test server is not the default MariaDB: this machine's client config (/etc/mysql/my.cnf) pins the default socket and beats
// MYSQL_UNIX_PORT, so mysqldump gets a thin wrapper that adds --socket, and the drill account's socket goes into the scratch config.php.
$cfgPath = "$root/config.php"; $cfgOrig = (string) file_get_contents($cfgPath);
register_shutdown_function(function () use ($cfgPath, $cfgOrig) { file_put_contents($cfgPath, $cfgOrig); });
mkdir("$tmp/bin");
if ($sock) {
    file_put_contents("$tmp/bin/mysqldump", "#!/bin/sh\nfirst=\"\$1\"; shift\nexec /usr/bin/mysqldump \"\$first\" --socket=" . escapeshellarg($sock) . " \"\$@\"\n");
    chmod("$tmp/bin/mysqldump", 0755);
    file_put_contents($cfgPath, $cfgOrig . "\n\$config_drill_db_socket = " . var_export($sock, true) . ";\n");
}
$env = 'export PATH=' . escapeshellarg("$tmp/bin") . ':"$PATH" PHPRC=' . escapeshellarg((string) getenv('PHPRC')) . ';';
$bk = 'bash -c ' . escapeshellarg('set -euo pipefail; ' . $env
    . ' source ' . escapeshellarg("$root/deploy/backup.sh") . ';'
    . ' chown() { :; };'                                   // backup.sh makes its archive root:root; the test is not root
    . ' LOG_FILE=' . escapeshellarg("$tmp/backup.log") . '; APP_DIR=' . escapeshellarg($root) . '; DEST=' . escapeshellarg("$tmp/enc") . '; PASSPHRASE_FILE=' . escapeshellarg("$tmp/pass") . ';'
    . ' RETENTION_DAYS=14; OFFSITE_ENABLED=0; read_app_config "$APP_DIR"; register_exit_hook backup_exit_hook; do_backup; backup_post_steps');
[$c, $out] = sec_sh($bk);
$arc = glob("$tmp/enc/backup-*.tar.gz.enc")[0] ?? '';
if ($c !== 0) { echo substr($out, -1500) . "\n"; }
$ok($c === 0 && $arc !== '', 'deploy/backup.sh (do_backup + post steps) produced an encrypted archive');
$keyFile = $arc !== '' ? preg_replace('/\.tar\.gz\.enc$/', '.settings-key', $arc) : '';
$ok($keyFile !== '' && is_file($keyFile) && is_file("$arc.sha256") && (fileperms($keyFile) & 0777) === 0600, 'a separate 0600 .settings-key file and a .sha256 file sit next to the archive');
$ok(str_contains((string) shell_exec('openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -in ' . escapeshellarg($arc) . ' -pass file:' . escapeshellarg("$tmp/pass") . ' 2>/dev/null | tar -tzf - 2>/dev/null'), 'table-snapshot.json'), 'the archive decrypts with -iter 600000 and carries the table snapshot');
$ok(!str_contains((string) shell_exec('openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -in ' . escapeshellarg($arc) . ' -pass file:' . escapeshellarg("$tmp/pass") . ' 2>/dev/null | tar -xzOf - backup-manifest.json 2>/dev/null'), $KEY), 'the key is not inside the archive manifest');

// a copy of deploy/ with the root requirement removed (the only change)
$dep = "$tmp/deploy"; exec('cp -a ' . escapeshellarg("$root/deploy") . ' ' . escapeshellarg($dep));
$script = (string) file_get_contents("$dep/restore_drill.sh");
$ok(substr_count($script, "\nrequire_root \"\$@\"\n") === 1, 'restore_drill.sh requires root (one require_root line)');
file_put_contents("$dep/restore_drill.sh", str_replace("\nrequire_root \"\$@\"\n", "\n: # test: not root\n", $script));
$drill = fn (array $extra = [], string $pass = '') => sec_sh($env . ' DRILL_LOG_FILE=' . escapeshellarg("$tmp/drill.log") . ' bash ' . escapeshellarg("$dep/restore_drill.sh")
    . ' --app-dir=' . escapeshellarg($root) . ' --passphrase-file=' . escapeshellarg($pass ?: "$tmp/pass") . ' --dest=' . escapeshellarg("$tmp/enc") . ' ' . implode(' ', $extra));
$q("DELETE FROM restore_drill_log");
[$c, $out] = $drill();
if ($c !== 0) { echo substr($out, -2500) . "\n"; }
$ok($c === 0 && str_contains($out, 'restore_drill: pass'), 'deploy/restore_drill.sh decrypts the 600000-iteration archive and the drill passes');
$ok(preg_match('/\bPASS\s+Settings key for this backup\s+the supplied settings-key file matches this archive/', $out) === 1, 'it picked up the .settings-key file next to the archive and matched it to the manifest fingerprint');
$row = $rows("SELECT * FROM restore_drill_log ORDER BY drill_id DESC LIMIT 1")[0] ?? [];
$ok(($row['drill_status'] ?? '') === 'pass' && ($row['drill_backup_kind'] ?? '') === 'enc' && str_ends_with((string) ($row['drill_backup_file'] ?? ''), '.tar.gz.enc'), 'the result is in restore_drill_log (kind enc)');
$ok($drillDbs() === [], 'no drill_% database is left behind');

[$c, $out] = $drill(['--settings-key-file=' . escapeshellarg($tmp . '/enc/missing.settings-key')]);
$ok($c !== 0, 'a --settings-key-file that does not exist is refused');
file_put_contents("$tmp/wrong.settings-key", "# RivetIT settings-encryption key\nthe-wrong-key\n");
[$c, $out] = $drill(['--settings-key-file=' . escapeshellarg("$tmp/wrong.settings-key")]);
$ok($c === 1 && preg_match('/FAIL\s+Settings key for this backup\s+the supplied settings-key file does NOT match/', $out) === 1, 'a .settings-key file from another installation fails the drill');
file_put_contents("$tmp/badpass", "not-the-passphrase\n"); chmod("$tmp/badpass", 0600);
[$c, $out] = $drill([], "$tmp/badpass");
$ok($c === 1 && str_contains($out, 'could not be decrypted'), 'a wrong passphrase file is recorded as an unrestorable archive (exit 1)');
$ok(($rows("SELECT drill_status FROM restore_drill_log ORDER BY drill_id DESC LIMIT 1")[0]['drill_status'] ?? '') === 'fail', '... and logged as a failed drill');

// an archive from before the iteration bump (openssl's default count) is still drilled
$old = "$tmp/enc/backup-" . $db->query('SELECT DATABASE()')->fetch_row()[0] . '-20200101T000000Z.tar.gz.enc';
$plain = "$tmp/old.tar.gz";
sec_sh('openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -in ' . escapeshellarg($arc) . ' -out ' . escapeshellarg($plain) . ' -pass file:' . escapeshellarg("$tmp/pass"));
sec_sh('openssl enc -aes-256-cbc -pbkdf2 -salt -in ' . escapeshellarg($plain) . ' -out ' . escapeshellarg($old) . ' -pass file:' . escapeshellarg("$tmp/pass"));
[$c, $out] = $drill(['--backup=' . escapeshellarg($old)]);
$ok($c === 0 && str_contains($out, 'restore_drill: pass'), 'an archive written with the OLD default iteration count is still decrypted and drilled');

// cleanup
$q("DELETE FROM recovery_settings"); $q("DELETE FROM restore_drill_log"); $q("DELETE FROM backup_runs"); $q("DELETE FROM clients");
$q("UPDATE settings SET config_smtp_password = NULL WHERE company_id = 1");
exec('rm -rf ' . escapeshellarg($tmp));
