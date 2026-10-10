<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * deploy/lib/offsite.sh + deploy/lib/backup_extras.sh: config parsing, retention by file-name timestamp, the "never delete the last
 * archive" guard, and each method (local, rclone, aws s3, sftp) against FAKE command-line tools that store into a temp directory.
 * No network, no database, no root. Run: php tests/recovery_offsite.php
 */
$root = realpath(__DIR__ . '/..');
$fails = 0; $n = 0;
$ok = function (bool $c, string $l) use (&$fails, &$n) { $n++; echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$tmp = sys_get_temp_dir() . '/recovery-offsite-' . bin2hex(random_bytes(4));
mkdir($tmp . '/bin', 0700, true); mkdir($tmp . '/remote', 0700, true); mkdir($tmp . '/src', 0700, true);
$sh = function (string $script, array $env = []) use ($root, $tmp): array {
    $pre = "set -uo pipefail; warn() { echo \"WARN: \$*\" >&2; }; success() { :; }; info() { :; }; SCRIPT_DIR=" . escapeshellarg($root . '/deploy') . "; source " . escapeshellarg($root . '/deploy/lib/offsite.sh') . "\n";
    $e = ''; foreach ($env as $k => $v) { $e .= $k . '=' . escapeshellarg($v) . ' '; }
    $out = []; exec($e . 'PATH=' . escapeshellarg($tmp . '/bin') . ':$PATH bash -c ' . escapeshellarg($pre . $script) . ' 2>&1', $out, $rc);
    return [implode("\n", $out), $rc];
};
$name = fn (string $ts) => "backup-db-{$ts}.tar.gz.enc";

// syntax of everything shipped
foreach (['deploy/backup.sh', 'deploy/restore_drill.sh', 'deploy/lib/offsite.sh', 'deploy/lib/backup_extras.sh', 'deploy/lib/common.sh'] as $f) {
    exec('bash -n ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc); $ok($rc === 0, "bash -n $f");
}
[$help] = [shell_exec('bash ' . escapeshellarg("$root/deploy/backup.sh") . ' --help 2>&1')];
$ok(str_contains((string) $help, '--offsite') && str_contains((string) $help, 'offsite-retention-days'), 'backup.sh --help documents --offsite');

// names, expiry
[$out] = $sh('offsite_name_epoch ' . escapeshellarg($name('20260101T000000Z')));
$ok(trim($out) === (string) gmmktime(0, 0, 0, 1, 1, 2026), 'file-name timestamp parsed to epoch');
[$out] = $sh('offsite_name_epoch backup-db-latest.tar.gz.enc');
$ok(trim($out) === '', 'a name without a timestamp yields nothing');
$cut = gmmktime(0, 0, 0, 3, 1, 2026);
$names = implode("\n", [$name('20260101T000000Z'), $name('20260101T000000Z') . '.sha256', $name('20260301T000000Z'), $name('20260401T000000Z') . '.sha256', 'notes.txt', 'backup-db-20250101T000000Z.sql', '../x', 'backup-db-nodate.tar.gz.enc']);
[$out] = $sh('offsite_expired_names ' . $cut . ' <<< ' . escapeshellarg($names));
$ok(trim($out) === $name('20260101T000000Z') . "\n" . $name('20260101T000000Z') . '.sha256', 'only old backup archives and their checksum files expire; strangers and undated names never do');

// config parsing
$cfg = $tmp . '/offsite.conf';
$write = function (string $body, int $mode = 0600) use ($cfg) { file_put_contents($cfg, $body); chmod($cfg, $mode); };
$write("# c\nOFFSITE_METHOD=rclone # trailing\nOFFSITE_RCLONE_REMOTE=\"b2:bucket/dir\"\nOFFSITE_RETENTION_DAYS=30\nEVIL=\$(touch $tmp/pwned)\nOFFSITE_SFTP_PORT=2222\n");
[$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg) . ' && echo "$OFFSITE_METHOD|$OFFSITE_RCLONE_REMOTE|$OFFSITE_RETENTION_DAYS|$OFFSITE_SFTP_PORT"');
$ok($rc === 0 && str_contains($out, 'rclone|b2:bucket/dir|30|2222'), 'config parsed (quotes and comments handled)');
$ok(str_contains($out, 'ignoring unknown key EVIL') && !file_exists($tmp . '/pwned'), 'an unknown key is ignored and nothing in the file is ever executed');
$write("OFFSITE_METHOD=rclone\n", 0666);
[$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg));
$ok($rc !== 0 && str_contains($out, 'writable'), 'a group/world-writable config is refused');
foreach (["OFFSITE_METHOD=rclone\n" => 'OFFSITE_RCLONE_REMOTE', "OFFSITE_METHOD=s3\nOFFSITE_S3_URI=bucket\n" => 'OFFSITE_S3_URI', "OFFSITE_METHOD=sftp\nOFFSITE_SFTP_TARGET=host\n" => 'OFFSITE_SFTP_TARGET', "OFFSITE_METHOD=nope\n" => 'OFFSITE_METHOD', "OFFSITE_METHOD=local\nOFFSITE_LOCAL_DIR=rel\n" => 'OFFSITE_LOCAL_DIR'] as $body => $needle) {
    $write($body);
    [$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg));
    $ok($rc !== 0 && str_contains($out, $needle), "config missing/invalid $needle is refused");
}

// local method + retention + guard
$loc = $tmp . '/local'; $now = time();
$ts = fn (int $daysAgo) => gmdate('Ymd\THis\Z', $now - $daysAgo * 86400);
$mk = function (string $dir, int $daysAgo, string $body = 'data') use ($name, $ts) { @mkdir($dir, 0700, true); $f = "$dir/" . $name($ts($daysAgo)); file_put_contents($f, $body); file_put_contents("$f.sha256", hash('sha256', $body) . '  ' . basename($f) . "\n"); return $f; };
$new = $mk($tmp . '/src', 0, 'fresh-archive');
$write("OFFSITE_METHOD=local\nOFFSITE_LOCAL_DIR=$loc\nOFFSITE_RETENTION_DAYS=10\n");
[$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg) . ' && offsite_push ' . escapeshellarg($new) . ' ' . escapeshellarg("$new.sha256"));
$ok($rc === 0 && str_starts_with(trim($out), 'ok: local') && is_file("$loc/" . basename($new)) && is_file("$loc/" . basename($new) . '.sha256') && hash_file('sha256', "$loc/" . basename($new)) === hash_file('sha256', $new), 'local push copies archive + checksum and verifies sizes');
foreach ([20, 12, 3] as $d) { $mk($loc, $d); }
file_put_contents("$loc/notes.txt", 'keep me'); file_put_contents("$loc/backup-db-2020.sql", 'keep me too');
[$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg) . ' && offsite_apply_retention 10');
$left = array_map('basename', glob("$loc/*"));
$ok($rc === 0 && !in_array($name($ts(20)), $left) && !in_array($name($ts(12)) . '.sha256', $left) && in_array($name($ts(3)), $left) && in_array(basename($new), $left)
    && in_array('notes.txt', $left) && in_array('backup-db-2020.sql', $left), 'retention removes archives and checksums older than N days, keeps newer ones and anything it does not own');
// every archive "expired" (clock skew / outage): the newest survives
foreach (glob("$loc/backup-*") as $f) { unlink($f); }
foreach ([40, 35, 31] as $d) { $mk($loc, $d); }
[$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg) . ' && offsite_apply_retention 10');
$left = array_map('basename', glob("$loc/backup-*"));
$ok($left === [$name($ts(31)), $name($ts(31)) . '.sha256'] && str_contains($out, 'keeping the newest'), 'when every archive is past retention the newest is kept (never empty the destination)');
// failed copy is reported
$write("OFFSITE_METHOD=local\nOFFSITE_LOCAL_DIR=/proc/nope/x\n");
[$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg) . ' && offsite_push ' . escapeshellarg($new) . ' ' . escapeshellarg("$new.sha256"));
$ok($rc !== 0 && str_starts_with(trim($out), 'failed:'), 'an unwritable destination is reported as failed');
// delete refuses anything that is not a backup file name
[$out, $rc] = $sh('OFFSITE_METHOD=local; OFFSITE_LOCAL_DIR=' . escapeshellarg($loc) . '; offsite_delete notes.txt; echo "rc=$?"; offsite_delete "../etc/passwd"; echo "rc=$?"');
$ok(substr_count($out, 'rc=1') === 2 && is_file("$loc/notes.txt"), 'offsite_delete only ever deletes backup-*.tar.gz.enc[.sha256] names');

// fake tools -------------------------------------------------------------------------------------------------------------------
$R = $tmp . '/remote';
file_put_contents($tmp . '/bin/rclone', <<<'BASH'
#!/usr/bin/env bash
# fake rclone: remote "r:path" lives in $FAKE_ROOT/path
while [[ "${1:-}" == --config ]]; do shift 2; done
cmd="$1"; shift
loc() { local p="${1#*:}"; echo "$FAKE_ROOT/$p"; }
case "$cmd" in
  copyto) mkdir -p "$(dirname "$(loc "$2")")"; cp "$1" "$(loc "$2")" ;;
  lsf) fmt=""; inc=""; while [[ "$1" == --* ]]; do case "$1" in --format) fmt="$2"; shift;; --include) inc="$2"; shift;; esac; shift; done
       d="$(loc "$1")"; for f in "$d"*; do [[ -f "$f" ]] || continue; b="$(basename "$f")"; [[ -n "$inc" && "$b" != "$inc" ]] && continue; if [[ "$fmt" == s ]]; then stat -c %s "$f"; else echo "$b"; fi; done ;;
  deletefile) rm -f "$(loc "$1")" ;;
  *) echo "fake rclone: unsupported $cmd" >&2; exit 9 ;;
esac
BASH);
file_put_contents($tmp . '/bin/aws', <<<'BASH'
#!/usr/bin/env bash
# fake aws: s3://bucket/key -> $FAKE_ROOT/bucket/key ; records the env + endpoint it was given
while [[ "${1:-}" == --endpoint-url || "${1:-}" == --profile ]]; do echo "$1=$2" >> "$FAKE_ROOT/.aws-args"; shift 2; done
[[ -n "${AWS_SHARED_CREDENTIALS_FILE:-}" ]] && echo "creds=$AWS_SHARED_CREDENTIALS_FILE" >> "$FAKE_ROOT/.aws-args"
[[ "$1" == s3 ]] || exit 9; shift; cmd="$1"; shift
while [[ "${1:-}" == --only-show-errors ]]; do shift; done
loc() { echo "$FAKE_ROOT/${1#s3://}"; }
case "$cmd" in
  cp) mkdir -p "$(dirname "$(loc "$2")")"; cp "$1" "$(loc "$2")" ;;
  rm) rm -f "$(loc "$1")" ;;
  ls) p="$(loc "$1")"; if [[ -d "$p" ]]; then for f in "$p"*; do [[ -f "$f" ]] && echo "2026-01-01 00:00:00 $(stat -c %s "$f") $(basename "$f")"; done; elif [[ -f "$p" ]]; then echo "2026-01-01 00:00:00 $(stat -c %s "$p") $(basename "$p")"; fi ;;
  *) exit 9 ;;
esac
BASH);
file_put_contents($tmp . '/bin/sftp', <<<'BASH'
#!/usr/bin/env bash
# fake sftp: reads a batch on stdin, remote paths live in $FAKE_ROOT; records the options it was given
echo "$*" >> "$FAKE_ROOT/.sftp-args"
while IFS= read -r line; do
  echo "sftp> $line"
  eval "set -- $line"
  case "$1" in
    put) mkdir -p "$(dirname "$FAKE_ROOT$3")"; cp "$2" "$FAKE_ROOT$3" ;;
    rename) mv "$FAKE_ROOT$2" "$FAKE_ROOT$3" ;;
    rm) rm -f "$FAKE_ROOT$2" ;;
    ls) if [[ "$2" == -1 ]]; then for f in "$FAKE_ROOT$3"/*; do [[ -f "$f" ]] && echo "$3/$(basename "$f")"; done; else f="$FAKE_ROOT$3"; [[ -f "$f" ]] && echo "-rw------- 1 u g $(stat -c %s "$f") Jan  1 00:00 $3"; fi ;;
  esac
done
BASH);
foreach (['rclone', 'aws', 'sftp'] as $t) { chmod($tmp . "/bin/$t", 0755); }

$methods = [
    'rclone' => ["OFFSITE_METHOD=rclone\nOFFSITE_RCLONE_REMOTE=r:bk/dir\nOFFSITE_RCLONE_CONFIG=/etc/x.conf\nOFFSITE_RETENTION_DAYS=10\n", "$R/bk/dir"],
    's3'     => ["OFFSITE_METHOD=s3\nOFFSITE_S3_URI=s3://bucket/pre/\nOFFSITE_S3_ENDPOINT=https://s3.example.test\nOFFSITE_S3_CREDENTIALS_FILE=/etc/aws-creds\nOFFSITE_RETENTION_DAYS=10\n", "$R/bucket/pre"],
    'sftp'   => ["OFFSITE_METHOD=sftp\nOFFSITE_SFTP_TARGET=bk@host.example.test:/srv/rivet\nOFFSITE_SFTP_KEY=/etc/k\nOFFSITE_SFTP_PORT=2222\nOFFSITE_SFTP_KNOWN_HOSTS=/etc/kh\nOFFSITE_RETENTION_DAYS=10\n", "$R/srv/rivet"],
];
foreach ($methods as $m => [$body, $dir]) {
    $write($body);
    $env = ['FAKE_ROOT' => $R];
    [$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg) . ' && offsite_push ' . escapeshellarg($new) . ' ' . escapeshellarg("$new.sha256"), $env);
    $ok($rc === 0 && str_starts_with(trim($out), "ok: $m") && is_file("$dir/" . basename($new)) && is_file("$dir/" . basename($new) . '.sha256') && !glob("$dir/.*.partial"), "$m: push stores archive + checksum, verified by size, no partial file left ($out)");
    foreach ([25, 15, 4] as $d) { $mk($dir, $d); }
    file_put_contents("$dir/readme.txt", 'x');
    [$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg) . ' && offsite_apply_retention 10', $env);
    $left = array_map('basename', glob("$dir/*"));
    $ok($rc === 0 && !in_array($name($ts(25)), $left) && !in_array($name($ts(15)) . '.sha256', $left) && in_array($name($ts(4)), $left) && in_array(basename($new), $left) && in_array('readme.txt', $left), "$m: retention applied to the off-site copy");
}
$ok(str_contains((string) file_get_contents("$R/.aws-args"), '--endpoint-url=https://s3.example.test') && str_contains((string) file_get_contents("$R/.aws-args"), 'creds=/etc/aws-creds'), 's3: endpoint and credentials file are passed to the aws CLI');
$sf = (string) file_get_contents("$R/.sftp-args");
$ok(str_contains($sf, '-P 2222') && str_contains($sf, '-i /etc/k') && str_contains($sf, 'BatchMode=yes') && str_contains($sf, 'StrictHostKeyChecking=yes') && str_contains($sf, 'UserKnownHostsFile=/etc/kh') && str_contains($sf, 'bk@host.example.test'), 'sftp: key, port, strict host key checking and known_hosts are used');

// size mismatch at the destination is a failed copy
file_put_contents($tmp . '/bin/rclone', "#!/usr/bin/env bash\ncase \"\${1:-}\" in copyto) exit 0;; lsf) echo 1;; esac\n"); chmod($tmp . '/bin/rclone', 0755);
$write($methods['rclone'][0]);
[$out, $rc] = $sh('offsite_load_config ' . escapeshellarg($cfg) . ' && offsite_push ' . escapeshellarg($new) . ' ' . escapeshellarg("$new.sha256"), ['FAKE_ROOT' => $R]);
$ok($rc !== 0 && str_contains($out, 'failed:') && str_contains($out, 'expected'), 'a destination that reports the wrong size is a failed copy');

// checksum + cleanup helpers from backup_extras.sh
$enc = $tmp . '/src/' . $name($ts(0));
$esc = escapeshellarg($root . '/deploy/lib/backup_extras.sh');
[$out, $rc] = $sh("DEST=" . escapeshellarg($tmp . '/src') . "; source $esc; h=\$(backup_write_checksum " . escapeshellarg($enc) . "); echo \"\$h\"; (cd " . escapeshellarg($tmp . '/src') . " && sha256sum -c " . escapeshellarg(basename($enc) . '.sha256') . ")");
$ok($rc === 0 && str_contains($out, hash_file('sha256', $enc)) && str_contains($out, ': OK'), 'checksum file verifies with sha256sum -c');
file_put_contents($tmp . '/src/backup-db-20200101T000000Z.tar.gz.enc.sha256', "x  y\n");
[$out, $rc] = $sh("DEST=" . escapeshellarg($tmp . '/src') . "; source $esc; backup_cleanup_checksums");
$ok(!file_exists($tmp . '/src/backup-db-20200101T000000Z.tar.gz.enc.sha256') && is_file($enc . '.sha256'), 'checksum files of already-pruned archives are removed, live ones kept');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails === 0 ? "ALL $n PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
