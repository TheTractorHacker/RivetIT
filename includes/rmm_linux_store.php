<?php

/*
 * Hosted Linux agent files (RivetIT 26.10.64, DB 2.6.180).
 *
 * The Add device page used to give a Linux server a command that needed a tarball to be unpacked first, unless the Linux files were recorded as GitHub links. This
 * server can now HOST them: the Linux agent for amd64 and arm64, install-linux.sh and the optional .tar.gz, in their own table (endpoint_agent_linux_files) and their
 * own folder under the agent binary directory (outside the web root, random file names, denied by .htaccess and by the edition's nginx rules). It is a separate store
 * from the Windows binaries: those are PE files that can become an agent UPDATE; these are only ever served to a person who holds a valid enrollment token.
 *
 * Files come in three ways: the local build host (includes/rmm_build_host.php), Agent updates > Publish (includes/rmm_agent_feed.php) and a manual upload on the
 * Agent binaries card. All three go through rivetLxStore(), which validates, hashes and stores. Nothing here trusts a name, a size or a hash that came from outside.
 *
 * DOWNLOAD CREDENTIAL. The install command must not carry the enrollment token to a URL. It carries, per file, a credential derived from the token row:
 *     rvdl1.<token selector>.<expiry>.<HMAC-SHA256(key = stored SHA-256 of the token secret, "rivetit-linux-dl|v1|selector|file|version|expiry")>
 * The route (api/v1/agent_linux_file.php) looks the token up by selector, refuses a revoked or expired token, recomputes the HMAC and compares in constant time. The token
 * itself never leaves the page and the command's token file, a credential works for one file at one version, and it does not use up an enrollment.
 */

require_once __DIR__ . '/rmm_agent_feed.php';

const RIVET_LX_VERSION_RE = '/^\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[0-9A-Za-z][0-9A-Za-z.-]{0,19})?\z/';
const RIVET_LX_SCRIPT_MAX = 262144;           // install-linux.sh: a small shell script
const RIVET_LX_CRED_CAP_S = 86400;            // a download credential never outlives 24 hours, whatever the token lifetime
const RIVET_LX_CRED_RE = '/^rvdl1\.([0-9a-f]{12})\.(\d{9,12})\.([0-9a-f]{64})\z/';

/** The file names this store accepts. @return array{kind:string,arch:string}|null */
function rivetLxParseName($name): ?array
{
    if (!is_string($name)) {
        return null;
    }
    if ($name === 'install-linux.sh') {
        return ['kind' => 'script', 'arch' => ''];
    }
    if (preg_match('/^rivetit-agent-linux-(amd64|arm64)\z/', $name, $m) === 1) {
        return ['kind' => 'binary', 'arch' => $m[1]];
    }
    if (preg_match('/^rivetit-agent-linux-(amd64|arm64)\.tar\.gz\z/', $name, $m) === 1) {
        return ['kind' => 'tarball', 'arch' => $m[1]];
    }

    return null;
}

/** Every name the store accepts, in the order the page lists them. @return list<string> */
function rivetLxNames(): array
{
    return ['install-linux.sh', 'rivetit-agent-linux-amd64', 'rivetit-agent-linux-arm64', 'rivetit-agent-linux-amd64.tar.gz', 'rivetit-agent-linux-arm64.tar.gz'];
}

function rivetLxReady($mysqli = null): bool
{
    static $memo = [];
    $mysqli ??= $GLOBALS['mysqli'] ?? null;
    $key = $mysqli instanceof \mysqli ? spl_object_id($mysqli) : 0;
    if (empty($memo[$key])) {
        $ok = false;
        if ($mysqli instanceof \mysqli) {
            $a = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_linux_files'");
            $ok = $a && mysqli_num_rows($a) > 0;
        }
        $memo[$key] = $ok;
    }

    return $memo[$key];
}

/** The folder of the hosted Linux files (inside the agent binary directory, created with its deny files), or null. */
function rivetLxDir(): ?string
{
    try {
        $base = rivetRmmModule()->binaryStore()->storageDir();
    } catch (\Throwable) {
        return null;
    }
    if ($base === null || $base === '' || !is_dir($base)) {
        return null;
    }
    $dir = rtrim($base, '/') . '/linux';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return null;
    }
    if (!is_file("$dir/.htaccess")) {
        @file_put_contents("$dir/.htaccess", "Require all denied\nOptions -Indexes -ExecCGI\n");
    }
    if (!is_file("$dir/index.html")) {
        @file_put_contents("$dir/index.html", '');
    }

    return $dir;
}

/** The size cap of one file of this name. */
function rivetLxMaxBytes(string $name): int
{
    $p = rivetLxParseName($name);
    if ($p !== null && $p['kind'] === 'script') {
        return RIVET_LX_SCRIPT_MAX;
    }
    try {
        return max(1048576, (int) rivetRmmModule()->binaryStore()->maxBytes());
    } catch (\Throwable) {
        return 64 * 1048576;
    }
}

/**
 * Check a candidate file against its name. null = acceptable, otherwise the reason.
 *   - binary: a 64-bit little-endian ELF executable (ET_EXEC or ET_DYN) whose e_machine is the one the name says. A Windows PE, a script or a wrong CPU is refused.
 *   - script: a SMALL text file that starts with a shell shebang; no NUL byte, no ELF/PE/gzip magic, valid UTF-8, no control characters other than tab, CR, LF.
 *   - tarball: a gzip stream (magic and deflate method); the content is not unpacked here because nothing on this server ever runs it.
 */
function rivetLxValidate(string $path, string $name, ?int $maxBytes = null): ?string
{
    $p = rivetLxParseName($name);
    if ($p === null) {
        return 'That file name is not one this server hosts.';
    }
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        return 'The file could not be read.';
    }
    $size = (int) filesize($path);
    $cap = $maxBytes ?? rivetLxMaxBytes($name);
    if ($size < 1) {
        return 'The file is empty.';
    }
    if ($size > $cap) {
        return 'The file is larger than the limit of ' . $cap . ' bytes.';
    }
    if ($p['kind'] === 'binary') {
        if ($size < 4096) {
            return 'The file is too small to be an agent program.';
        }
        $d = \RivetCore\Rmm\Binaries\BinaryInspector::detect($path);
        if (is_string($d)) {
            return $d;
        }
        if ($d['format'] !== 'elf') {
            return 'This is not a Linux program (an ELF file is required).';
        }
        if ($d['arch'] !== $p['arch']) {
            return 'The program is built for a different CPU than ' . $name . ' says (e_machine ' . (int) $d['machine'] . ').';
        }
        $h = (string) file_get_contents($path, false, null, 0, 20);
        $type = strlen($h) >= 18 ? (int) (unpack('v', substr($h, 16, 2))[1] ?? 0) : 0;
        if ($type !== 2 && $type !== 3) {
            return 'This ELF file is not an executable (relocatable objects and core dumps are refused).';
        }

        return null;
    }
    $head = (string) file_get_contents($path, false, null, 0, 4096);
    if ($p['kind'] === 'tarball') {
        return strlen($head) >= 10 && str_starts_with($head, "\x1f\x8b\x08") ? null : 'This is not a gzip file.';
    }
    // install-linux.sh
    $body = (string) file_get_contents($path);
    if (strlen($body) !== $size) {
        return 'The file could not be read.';
    }
    if (preg_match('/\A#!\s{0,3}\/(?:usr\/)?bin\/(?:env\s+)?(?:ba|da|a)?sh\b[^\n]{0,80}\n/', $body) !== 1) {
        return 'install-linux.sh must start with a shell shebang line (for example #!/usr/bin/env bash).';
    }
    if (str_contains($body, "\0") || preg_match('/[\x01-\x08\x0b\x0c\x0e-\x1f\x7f]/', $body) === 1) {
        return 'install-linux.sh contains binary data.';
    }
    if (preg_match('//u', $body) !== 1) {
        return 'install-linux.sh is not valid UTF-8 text.';
    }

    return null;
}

/**
 * Store one validated file and record it.
 *
 * @param array{activate?:bool,ring?:?string,pct?:int,notes?:string,source?:string,expected_sha256?:string} $opts
 * @return array{ok:bool,error?:string,file_id?:int,sha256?:string,size?:int,current?:bool,replay?:bool}
 */
function rivetLxStore($mysqli, string $srcPath, string $name, string $version, int $userId, array $opts = []): array
{
    if (!rivetLxReady($mysqli)) {
        return ['ok' => false, 'error' => 'Run the database update first (the hosted Linux files table is missing).'];
    }
    $p = rivetLxParseName($name);
    if ($p === null || preg_match(RIVET_LX_VERSION_RE, $version) !== 1) {
        return ['ok' => false, 'error' => 'The file name or the version is not valid.'];
    }
    $err = rivetLxValidate($srcPath, $name);
    if ($err !== null) {
        return ['ok' => false, 'error' => "$name: $err"];
    }
    $sha = (string) hash_file('sha256', $srcPath);
    $size = (int) filesize($srcPath);
    $exp = strtolower((string) ($opts['expected_sha256'] ?? ''));
    if ($exp !== '' && !hash_equals($exp, $sha)) {
        return ['ok' => false, 'error' => "$name does not match its expected SHA-256."];
    }
    $e = static fn (string $s): string => mysqli_real_escape_string($mysqli, $s);
    $q = mysqli_query($mysqli, "SELECT file_id, sha256, is_current FROM endpoint_agent_linux_files WHERE version = '" . $e($version) . "' AND name = '" . $e($name) . "'");
    $have = $q ? mysqli_fetch_assoc($q) : null;
    if ($have) {
        if (!hash_equals((string) $have['sha256'], $sha)) {
            return ['ok' => false, 'error' => "$name $version is already stored with different contents. Use a new version number."];
        }
        $id = (int) $have['file_id'];
        mysqli_query($mysqli, "UPDATE endpoint_agent_linux_files SET active = 1 WHERE file_id = $id");
        $replay = true;
    } else {
        $dir = rivetLxDir();
        if ($dir === null) {
            return ['ok' => false, 'error' => 'No writable agent binary directory is configured for this installation.'];
        }
        $storage = bin2hex(random_bytes(16));
        $tmp = "$dir/.incoming_" . bin2hex(random_bytes(8));
        if (!@copy($srcPath, $tmp)) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'The file could not be written to the agent binary directory.'];
        }
        @chmod($tmp, 0640);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($tmp, (int) fileowner($dir));
            @chgrp($tmp, (int) filegroup($dir));
        }
        if (hash_file('sha256', $tmp) !== $sha || !@rename($tmp, "$dir/$storage")) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'The file could not be stored.'];
        }
        $ring = in_array($opts['ring'] ?? null, ['pilot', 'stable'], true) ? "'" . $opts['ring'] . "'" : 'NULL';
        $pct = max(0, min(100, (int) ($opts['pct'] ?? 100)));
        $src = in_array($opts['source'] ?? '', ['build_host', 'github', 'upload'], true) ? $opts['source'] : 'upload';
        $notes = $e(mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) ($opts['notes'] ?? ''))), 0, 300));
        $ok = mysqli_query($mysqli, "INSERT INTO endpoint_agent_linux_files (version, name, kind, arch, storage_name, size_bytes, sha256, source, uploaded_by, release_ring, rollout_pct, notes, active, is_current, created_at)
            VALUES ('" . $e($version) . "', '" . $e($name) . "', '" . $p['kind'] . "', '" . $p['arch'] . "', '$storage', $size, '$sha', '$src', " . (int) $userId . ", $ring, $pct, '$notes', 1, 0, UTC_TIMESTAMP())");
        if (!$ok) {
            @unlink("$dir/$storage");

            return ['ok' => false, 'error' => 'The file could not be recorded.'];
        }
        $id = (int) mysqli_insert_id($mysqli);
        $replay = false;
    }
    // the first stored file of a name becomes current by itself (nothing to break); a later version becomes current only when asked
    $q = mysqli_query($mysqli, "SELECT COUNT(*) FROM endpoint_agent_linux_files WHERE name = '" . $e($name) . "' AND is_current = 1 AND active = 1 AND file_id <> $id");
    $otherCurrent = $q && (int) mysqli_fetch_row($q)[0] > 0;
    $current = false;
    if (!empty($opts['activate']) || !$otherCurrent) {
        $current = rivetLxSetCurrent($mysqli, $id);
    }

    return ['ok' => true, 'file_id' => $id, 'sha256' => $sha, 'size' => $size, 'current' => $current, 'replay' => $replay];
}

function rivetLxSetCurrent($mysqli, int $fileId): bool
{
    $q = mysqli_query($mysqli, 'SELECT name FROM endpoint_agent_linux_files WHERE active = 1 AND file_id = ' . $fileId);
    $r = $q ? mysqli_fetch_assoc($q) : null;
    if (!$r) {
        return false;
    }
    $n = mysqli_real_escape_string($mysqli, (string) $r['name']);
    mysqli_query($mysqli, "UPDATE endpoint_agent_linux_files SET is_current = 0 WHERE name = '$n'");
    mysqli_query($mysqli, "UPDATE endpoint_agent_linux_files SET is_current = 1 WHERE file_id = $fileId");

    return true;
}

/** Remove a stored file (record and bytes). A current file cannot be removed while it is current. Returns an error text or null. */
function rivetLxDelete($mysqli, int $fileId): ?string
{
    $q = mysqli_query($mysqli, 'SELECT * FROM endpoint_agent_linux_files WHERE file_id = ' . $fileId);
    $r = $q ? mysqli_fetch_assoc($q) : null;
    if (!$r) {
        return 'That file is not stored.';
    }
    if ((int) $r['is_current'] === 1) {
        return 'This is the current file. Make another version current first.';
    }
    mysqli_query($mysqli, 'DELETE FROM endpoint_agent_linux_files WHERE file_id = ' . $fileId);
    $path = rivetLxPath($r);
    if ($path !== null) {
        @unlink($path);
    }

    return null;
}

/** Absolute path of a stored file, or null (built only from the generated 32 hex storage name). */
function rivetLxPath(array $row): ?string
{
    $dir = rivetLxDir();
    $s = (string) ($row['storage_name'] ?? '');
    if ($dir === null || preg_match('/^[0-9a-f]{32}\z/', $s) !== 1) {
        return null;
    }

    return "$dir/$s";
}

/** The current active row of a file name, or null. */
function rivetLxCurrent($mysqli, string $name): ?array
{
    if (!rivetLxReady($mysqli) || rivetLxParseName($name) === null) {
        return null;
    }
    $q = mysqli_query($mysqli, "SELECT * FROM endpoint_agent_linux_files WHERE name = '" . mysqli_real_escape_string($mysqli, $name) . "' AND active = 1 AND is_current = 1 ORDER BY file_id DESC LIMIT 1");

    return ($q && ($r = mysqli_fetch_assoc($q))) ? $r : null;
}

/** One stored file by name and version. */
function rivetLxFind($mysqli, string $name, string $version): ?array
{
    if (!rivetLxReady($mysqli) || rivetLxParseName($name) === null || preg_match(RIVET_LX_VERSION_RE, $version) !== 1) {
        return null;
    }
    $e = static fn (string $s): string => mysqli_real_escape_string($mysqli, $s);
    $q = mysqli_query($mysqli, "SELECT * FROM endpoint_agent_linux_files WHERE name = '" . $e($name) . "' AND version = '" . $e($version) . "' AND active = 1 LIMIT 1");

    return ($q && ($r = mysqli_fetch_assoc($q))) ? $r : null;
}

/** @return list<array<string,mixed>> */
function rivetLxList($mysqli): array
{
    $out = [];
    if (!rivetLxReady($mysqli)) {
        return $out;
    }
    $q = mysqli_query($mysqli, 'SELECT f.*, u.user_name FROM endpoint_agent_linux_files f LEFT JOIN users u ON u.user_id = f.uploaded_by ORDER BY f.is_current DESC, f.created_at DESC, f.file_id DESC LIMIT 200');
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $out[] = $r;
    }

    return $out;
}

/**
 * The hosted files the Linux command for one architecture uses: the current script and the current agent. null when either is missing or its bytes are gone.
 *
 * @return array{script:array<string,mixed>,binary:array<string,mixed>,version:string}|null
 */
function rivetLxCommandFiles($mysqli, string $arch): ?array
{
    $arch = $arch === 'arm64' ? 'arm64' : 'amd64';
    $s = rivetLxCurrent($mysqli, 'install-linux.sh');
    $b = rivetLxCurrent($mysqli, 'rivetit-agent-linux-' . $arch);
    if ($s === null || $b === null) {
        return null;
    }
    foreach ([$s, $b] as $r) {
        $p = rivetLxPath($r);
        if ($p === null || !is_file($p)) {
            return null;
        }
    }

    return ['script' => $s, 'binary' => $b, 'version' => (string) $b['version']];
}

// ------------------------------------------------------------------ download credential

/** @return string credential for one file at one version, valid until $exp (unix seconds) */
function rivetLxCredential(string $selector, string $tokenHash, string $name, string $version, int $exp): string
{
    $mac = hash_hmac('sha256', implode('|', ['rivetit-linux-dl', 'v1', $selector, $name, $version, (string) $exp]), strtolower($tokenHash));

    return 'rvdl1.' . $selector . '.' . $exp . '.' . $mac;
}

/**
 * Credentials for the files of a command, derived from the enrollment token the command carries.
 *
 * @return array{script:string,binary:string,exp:int}|string credentials, or the reason there are none
 */
function rivetLxCredentialsForToken($mysqli, string $token, array $files): array|string
{
    $parts = explode('.', $token);
    if (count($parts) !== 3 || $parts[0] !== \RivetCore\Rmm\RmmProtocol::ENROLL_TOKEN_PREFIX || preg_match(\RivetCore\Rmm\RmmProtocol::ENROLL_TOKEN_SELECTOR_RE, $parts[1]) !== 1
        || preg_match(\RivetCore\Rmm\RmmProtocol::ENROLL_TOKEN_SECRET_RE, $parts[2]) !== 1) {
        return 'The enrollment token is not well formed.';
    }
    $q = mysqli_query($mysqli, "SELECT token_hash, expires_at, revoked_at FROM endpoint_agent_enrollment_tokens WHERE token_selector = '" . mysqli_real_escape_string($mysqli, $parts[1]) . "'");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row || !hash_equals((string) $row['token_hash'], hash('sha256', $parts[2])) || $row['revoked_at'] !== null) {
        return 'The enrollment token is not usable.';
    }
    $until = (int) strtotime((string) $row['expires_at'] . ' UTC');
    $exp = min($until, time() + RIVET_LX_CRED_CAP_S);
    if ($exp <= time()) {
        return 'The enrollment token has expired.';
    }

    return [
        'script' => rivetLxCredential($parts[1], (string) $row['token_hash'], (string) $files['script']['name'], (string) $files['script']['version'], $exp),
        'binary' => rivetLxCredential($parts[1], (string) $row['token_hash'], (string) $files['binary']['name'], (string) $files['binary']['version'], $exp),
        'exp' => $exp,
    ];
}

/**
 * Check a presented credential for this file and version.
 *
 * @return array{ok:bool,reason:string,selector:string,token_id:int}
 */
function rivetLxVerifyCredential($mysqli, string $cred, string $name, string $version): array
{
    $bad = static fn (string $why, string $sel = '', int $id = 0): array => ['ok' => false, 'reason' => $why, 'selector' => $sel, 'token_id' => $id];
    if (preg_match(RIVET_LX_CRED_RE, $cred, $m) !== 1) {
        return $bad('malformed');
    }
    [, $sel, $exp, $mac] = $m;
    $q = mysqli_query($mysqli, "SELECT token_id, token_hash, expires_at, revoked_at FROM endpoint_agent_enrollment_tokens WHERE token_selector = '" . mysqli_real_escape_string($mysqli, $sel) . "'");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    // always compute, so a miss costs the same as a near miss
    $key = $row ? (string) $row['token_hash'] : str_repeat('0', 64);
    $want = hash_hmac('sha256', implode('|', ['rivetit-linux-dl', 'v1', $sel, $name, $version, $exp]), strtolower($key));
    if (!$row || !hash_equals($want, $mac)) {
        return $bad('invalid', $sel);
    }
    $id = (int) $row['token_id'];
    if ($row['revoked_at'] !== null) {
        return $bad('revoked', $sel, $id);
    }
    if ((int) $exp <= time() || (int) strtotime((string) $row['expires_at'] . ' UTC') <= time()) {
        return $bad('expired', $sel, $id);
    }

    return ['ok' => true, 'reason' => 'ok', 'selector' => $sel, 'token_id' => $id];
}

// ------------------------------------------------------------------ the command

/**
 * Turn Core's Linux snippet (which tells the person to unpack the tarball first) into a command that downloads install-linux.sh and the agent FROM THIS SERVER, checks both
 * against the SHA-256 values printed in the command (pinned now), and installs. Returns null when the hosted files are not there (the caller shows the "publish the agent
 * first" message), or when Core's text is not the shape this expects.
 *
 * @return array{command:string,version:string}|null
 */
function rivetLxCommand($mysqli, string $coreSnippet, string $arch): ?array
{
    $arch = $arch === 'arm64' ? 'arm64' : 'amd64';
    $files = rivetLxCommandFiles($mysqli, $arch);
    if ($files === null) {
        return null;
    }
    $needle = './install-linux.sh ';
    if (substr_count($coreSnippet, $needle) !== 1 || preg_match("/printf '%s' '(rvte1\\.[0-9a-f]{12}\\.[0-9a-f]{40})' > \"\\\$T\"/", $coreSnippet, $tm) !== 1
        || preg_match("/--server '(https?:\\/\\/[^' ]+)'/", $coreSnippet, $sm) !== 1) {
        return null;
    }
    $creds = rivetLxCredentialsForToken($mysqli, $tm[1], $files);
    if (is_string($creds)) {
        return null;
    }
    $server = rtrim($sm[1], '/');
    $hasCa = str_contains($coreSnippet, 'C="$(mktemp)"');
    $q = static fn (string $v): string => "'" . str_replace("'", "'\\''", $v) . "'";
    $url = static fn (array $f): string => $server . '/api/v1/agent_linux_file?name=' . rawurlencode((string) $f['name']) . '&version=' . rawurlencode((string) $f['version']);
    $cmd = str_replace(['trap \'rm -f "$T"', "umask 077\n"], ['trap \'rm -f "$T" "$S" "$B" "$H" "$W"', "umask 077\nS=\"\$(mktemp)\"\nB=\"\$(mktemp)\"\nH=\"\$(mktemp)\"\nW=\"\$(mktemp)\"\n"], $coreSnippet);
    if (!str_contains($cmd, 'rm -f "$T" "$S" "$B" "$H" "$W"') || !str_contains($cmd, 'W="$(mktemp)"')) {
        return null;
    }
    // fetch NAME DEST EXPECTED_SHA256 URL KEY: the key goes to curl as a config file and to wget as a wgetrc, never onto a command line; the answer must match the SHA-256 printed here.
    $caC = $hasCa ? ' --cacert "$C"' : '';
    $caW = $hasCa ? ' --ca-certificate="$C"' : '';
    $fn = "fetch() {\n"
        . "  printf 'header = \"X-Install-Key: %s\"\\n' \"\$5\" > \"\$H\"; printf 'header = X-Install-Key: %s\\n' \"\$5\" > \"\$W\"\n"
        . "  if command -v curl >/dev/null 2>&1; then curl --fail --silent --show-error --max-time 600 --proto '=http,https' -K \"\$H\"$caC -o \"\$2\" \"\$4\"\n"
        . "  elif command -v wget >/dev/null 2>&1; then WGETRC=\"\$W\" wget -q --max-redirect=0$caW -O \"\$2\" \"\$4\"\n"
        . ("  else echo 'curl or wget is required' >&2; exit 69; fi || { echo \"Could not download \$1 from the " . htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'RivetIT') . " server\" >&2; exit 65; }\n")
        . "  printf '%s  %s\\n' \"\$3\" \"\$2\" | sha256sum -c - >/dev/null || { echo \"\$1 does not match the SHA-256 printed in this command\" >&2; exit 65; }\n"
        . "}\n";
    $calls = 'fetch install-linux.sh "$S" ' . $q((string) $files['script']['sha256']) . ' ' . $q($url($files['script'])) . ' ' . $q($creds['script']) . "\n"
        . 'fetch rivetit-agent-linux-' . $arch . ' "$B" ' . $q((string) $files['binary']['sha256']) . ' ' . $q($url($files['binary'])) . ' ' . $q($creds['binary']) . "\n";
    $cmd = str_replace($needle, $fn . $calls . 'bash "$S" --binary "$B" ', $cmd);
    $ver = (string) preg_replace('/[^0-9A-Za-z._-]/', '', (string) $files['version']);
    $cmd = (string) preg_replace('/^# Run as root from the unpacked [^\n]*\n/m', '# Run as root. It downloads install-linux.sh and the agent (rivetit-agent-linux-' . $arch . ' ' . $ver . (') from this ' . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' server and checks both against the SHA-256 values below. The token is a secret: do not paste it into tickets or chat.') . "\n", $cmd, 1);

    return ['command' => $cmd, 'version' => $ver];
}

/**
 * Is there anything the Linux command for this CPU can download: the hosted script and agent, or (installs that only recorded GitHub links before 26.10.64) a recorded script and agent?
 */
function rivetLxAvailable($mysqli, string $arch): bool
{
    if (rivetLxCommandFiles($mysqli, $arch) !== null) {
        return true;
    }
    $assets = rivetAgentFeedLinuxAssets($mysqli);
    $ok = ['script' => false, 'binary' => false];
    foreach ($assets['items'] as $i) {
        if (isset($ok[$i['kind']]) && ($i['arch'] === $arch || $i['arch'] === '') && rivetAgentFeedUrlIsSafe($i['url']) && preg_match('/\A[0-9a-f]{64}\z/', (string) $i['sha256']) === 1) {
            $ok[$i['kind']] = true;
        }
    }

    return $ok['script'] && $ok['binary'];
}
