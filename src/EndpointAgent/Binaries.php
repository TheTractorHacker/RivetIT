<?php

namespace ITFlow\EndpointAgent;

/**
 * Hosted agent executables (unstamped), one row per (version, architecture).
 *
 * Files live under backups/endpoint-agent/ (or EA_BINARY_DIR from config.php): that directory is denied over HTTP by the shipped nginx
 * rules and .htaccess, is git-ignored and is not part of the in-app backup zip. File names are random, never derived from user input.
 * Rows say what may be served: `active` = published (installer and self-update may use it), `is_current` = the one a new installer is
 * stamped from. A delete only deactivates: the file is kept so a device that was offered the version can still finish its update.
 */
final class Binaries
{
    public const ARCHS = ['amd64' => 0x8664, 'arm64' => 0xAA64];
    public const VERSION_RE = '/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?\z/';
    public const DEFAULT_MAX_BYTES = 67108864;   // 64 MiB
    private const MIN_BYTES = 1024;

    public static function maxBytes(): int
    {
        return defined('EA_BINARY_MAX_BYTES') ? max(self::MIN_BYTES, (int) EA_BINARY_MAX_BYTES) : self::DEFAULT_MAX_BYTES;
    }

    /** The largest upload the web form can really accept: the cap above, bounded by PHP's own limits. */
    public static function effectiveUploadLimit(): int
    {
        $lim = self::maxBytes();
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $b = self::iniBytes((string) ini_get($k));
            if ($b > 0) {
                $lim = min($lim, $b);
            }
        }
        return $lim;
    }

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            // no break
            case 'm': $n *= 1024;
            // no break
            case 'k': $n *= 1024;
        }
        return $n;
    }

    public static function storageDir(): string
    {
        $dir = defined('EA_BINARY_DIR') ? (string) EA_BINARY_DIR : dirname(__DIR__, 2) . '/backups/endpoint-agent';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        // Belt and braces for Apache and a misconfigured vhost: never serve, never execute, never list.
        if (is_dir($dir) && is_writable($dir)) {
            if (!is_file("$dir/.htaccess")) {
                @file_put_contents("$dir/.htaccess", "Require all denied\nOptions -Indexes -ExecCGI\n");
            }
            if (!is_file("$dir/index.html")) {
                @file_put_contents("$dir/index.html", '');
            }
        }
        return $dir;
    }

    public static function pathFor(array $row): string
    {
        $name = (string) $row['storage_name'];
        if (!preg_match('/^bin_[0-9a-f]{32}\.bin$/', $name)) {
            throw new \RuntimeException('bad storage name');   // never build a path from anything else (no traversal)
        }
        return self::storageDir() . '/' . $name;
    }

    // ---------------------------------------------------------------- validation

    /**
     * Validate a candidate executable on disk. Reads only the headers and the last bytes (the SHA-256 streams the file).
     * @return array{sha256:string,size:int}|string a description on success, an error message otherwise
     */
    public static function inspect(string $path, string $arch, ?int $maxBytes = null)
    {
        $maxBytes ??= self::maxBytes();
        if (!isset(self::ARCHS[$arch])) {
            return 'The architecture must be amd64 or arm64.';
        }
        if (!is_file($path) || !is_readable($path)) {
            return 'The file could not be read.';
        }
        $size = (int) filesize($path);
        if ($size < self::MIN_BYTES) {
            return 'The file is too small to be a Windows executable.';
        }
        if ($size > $maxBytes) {
            return 'The file is ' . self::human($size) . ', larger than the ' . self::human($maxBytes) . ' limit.';
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return 'The file could not be read.';
        }
        $dos = (string) fread($fh, 64);
        $err = null;
        if (strlen($dos) < 64 || substr($dos, 0, 2) !== 'MZ') {
            $err = 'This is not a Windows executable (missing the MZ header).';
        } else {
            $peOff = unpack('V', substr($dos, 0x3C, 4))[1];
            if ($peOff < 64 || $peOff > 0x100000 || $peOff + 24 > $size) {
                $err = 'This is not a valid Windows executable (bad PE header offset).';
            } else {
                fseek($fh, $peOff);
                $pe = (string) fread($fh, 24);
                if (strlen($pe) < 24 || substr($pe, 0, 4) !== "PE\0\0") {
                    $err = 'This is not a valid Windows executable (missing the PE signature).';
                } else {
                    $machine = unpack('v', substr($pe, 4, 2))[1];
                    $isDll = (unpack('v', substr($pe, 22, 2))[1] & 0x2000) !== 0;
                    if ($machine !== self::ARCHS[$arch]) {
                        $err = sprintf('The executable is built for machine type 0x%04X, which is not %s (expected 0x%04X).', $machine, $arch, self::ARCHS[$arch]);
                    } elseif ($isDll) {
                        $err = 'This is a DLL, not an executable.';
                    }
                }
            }
        }
        $tail = '';
        if ($err === null) {
            fseek($fh, -self::tailBytes(), SEEK_END);
            $tail = (string) fread($fh, self::tailBytes());
        }
        fclose($fh);
        if ($err !== null) {
            return $err;
        }
        if (InstallerStamp::hasFooter($tail)) {
            return 'This file already carries an installer footer (RIVETIT-EMBED). Upload the original, unstamped agent executable.';
        }
        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            return 'The file could not be read.';
        }
        return ['sha256' => $sha, 'size' => $size];
    }

    private static function tailBytes(): int
    {
        return InstallerStamp::FOOTER_LEN;
    }

    public static function human(int $n): string
    {
        return $n >= 1048576 ? round($n / 1048576, 1) . ' MiB' : ($n >= 1024 ? round($n / 1024, 1) . ' KiB' : $n . ' B');
    }

    // ---------------------------------------------------------------- publishing

    /**
     * Validate, copy into storage and register a binary. Re-publishing the identical file for the same (version, arch) is a no-op;
     * a different file for an existing (version, arch) is refused (a released version never changes under devices).
     * @param array{activate?:bool,release_ring?:?string,rollout_pct?:int,notes?:string} $opts
     * @return array{ok:bool,error?:string,binary?:array<string,mixed>,created?:bool}
     */
    public static function publish(string $path, string $version, string $arch, int $userId, array $opts = []): array
    {
        if (!preg_match(self::VERSION_RE, $version)) {
            return ['ok' => false, 'error' => 'The version must look like 1.2.3 (optionally 1.2.3-rc1).'];
        }
        $info = self::inspect($path, $arch);
        if (is_string($info)) {
            return ['ok' => false, 'error' => $info];
        }
        $existing = Db::one('SELECT * FROM endpoint_agent_binaries WHERE version = ? AND arch = ?', [$version, $arch]);
        $created = false;
        if ($existing !== null) {
            if ($existing['sha256'] !== $info['sha256']) {
                return ['ok' => false, 'error' => "Version $version for $arch is already published with different contents. Use a new version number."];
            }
            $bin = $existing;
            if (!is_file(self::pathFor($bin))) {   // row survived but the file was lost: restore it from this upload
                self::copyIn($path, $bin['storage_name']);
            }
        } else {
            $name = 'bin_' . bin2hex(random_bytes(16)) . '.bin';
            self::copyIn($path, $name);
            $id = Db::insert('INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, uploaded_by, active, is_current, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?)',
                [$version, $arch, $info['sha256'], $info['size'], $name, $userId, Db::utcNow()]);
            $bin = Db::one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ?', [$id]);
            $created = true;
        }
        if ($bin['active'] == 0) {
            Db::run('UPDATE endpoint_agent_binaries SET active = 1 WHERE binary_id = ?', [$bin['binary_id']]);
        }
        if (!empty($opts['activate'])) {
            self::setCurrent((int) $bin['binary_id']);
        }
        $ring = $opts['release_ring'] ?? null;
        if ($ring !== null && $ring !== '') {
            $e = self::publishRelease((int) $bin['binary_id'], (string) $ring, (int) ($opts['rollout_pct'] ?? 10), (string) ($opts['notes'] ?? ''), $userId);
            if ($e !== null) {
                return ['ok' => false, 'error' => $e, 'binary' => $bin, 'created' => $created];
            }
        }
        return ['ok' => true, 'binary' => Db::one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ?', [$bin['binary_id']]), 'created' => $created];
    }

    private static function copyIn(string $src, string $name): void
    {
        $dir = self::storageDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException("The agent binary directory $dir is not writable by the web server.");
        }
        $tmp = "$dir/.incoming_" . bin2hex(random_bytes(8));
        if (!copy($src, $tmp)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not store the file.');
        }
        @chmod($tmp, 0640);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // Published by root from the CLI: hand the file to the directory's owner (the web server user), or PHP-FPM cannot read it.
            @chown($tmp, (int) fileowner($dir));
            @chgrp($tmp, (int) filegroup($dir));
        }
        if (!rename($tmp, "$dir/$name")) {
            @unlink($tmp);
            throw new \RuntimeException('Could not store the file.');
        }
    }

    /** Make this binary the one new installers are stamped from for its architecture (and make sure it is active). */
    public static function setCurrent(int $binaryId): bool
    {
        $b = Db::one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ?', [$binaryId]);
        if ($b === null) {
            return false;
        }
        Db::run('UPDATE endpoint_agent_binaries SET is_current = 0 WHERE arch = ?', [$b['arch']]);
        Db::run('UPDATE endpoint_agent_binaries SET is_current = 1, active = 1 WHERE binary_id = ?', [$binaryId]);
        return true;
    }

    /** "Delete": deactivate the binary and its release rows. The file stays. */
    public static function deactivate(int $binaryId): bool
    {
        $n = Db::run('UPDATE endpoint_agent_binaries SET active = 0, is_current = 0 WHERE binary_id = ?', [$binaryId]);
        Db::run('UPDATE endpoint_agent_releases SET active = 0 WHERE binary_id = ?', [$binaryId]);
        return $n > 0;
    }

    public static function current(string $arch): ?array
    {
        return Db::one('SELECT * FROM endpoint_agent_binaries WHERE arch = ? AND active = 1 AND is_current = 1 ORDER BY binary_id DESC LIMIT 1', [$arch]);
    }

    /** The https URL on the RivetIT host a device downloads a hosted release from. Null when no https service URL is configured. */
    public static function updateUrl(string $arch, string $version): ?string
    {
        $base = self::serviceBase();
        return $base === null ? null : $base . '/api/v1/agent_update?arch=' . rawurlencode($arch) . '&version=' . rawurlencode($version);
    }

    /** The configured service URL without a trailing slash, only when it is https (EA_ALLOW_INSECURE_HTTP loosens this for loopback tests). */
    public static function serviceBase(): ?string
    {
        $u = trim((string) (Config::get()['service_url'] ?? ''));
        $p = parse_url($u);
        if (!$p || empty($p['host']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }
        $scheme = $p['scheme'] ?? '';
        // Plain http is only ever tolerated for loopback test servers (config.php defines EA_ALLOW_INSECURE_HTTP there, never in production).
        $insecureOk = defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true && $scheme === 'http'
            && in_array(strtolower($p['host']), ['127.0.0.1', 'localhost', '[::1]'], true);
        if ($scheme !== 'https' && !$insecureOk) {
            return null;
        }
        return rtrim($u, '/');
    }

    /** Create or refresh the update release row that offers this binary to enrolled agents of its architecture. @return string|null error */
    public static function publishRelease(int $binaryId, string $ring, int $pct, string $notes, int $userId): ?string
    {
        $b = Db::one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ? AND active = 1', [$binaryId]);
        if ($b === null) {
            return 'Unknown or inactive binary.';
        }
        if (!in_array($ring, ['pilot', 'stable'], true)) {
            return 'Unknown ring.';
        }
        $url = self::updateUrl($b['arch'], $b['version']);
        if ($url === null) {
            return 'Set an https service URL first: agents download hosted updates from it.';
        }
        Db::run('INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, notes, created_by, created_at, arch, binary_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE url = VALUES(url), sha256 = VALUES(sha256), rollout_pct = VALUES(rollout_pct), notes = VALUES(notes), active = 1, binary_id = VALUES(binary_id)',
            [$b['version'], $url, $b['sha256'], '0.0.0', $ring, max(0, min(100, $pct)), mb_substr($notes, 0, 500), $userId, Db::utcNow(), $b['arch'], $b['binary_id']]);
        Enrollment::audit('Agent Release Published', "Release {$b['version']} ({$b['arch']}, $ring, $pct%) from hosted binary #{$b['binary_id']} by user $userId", 0, 0);
        return null;
    }

    // ---------------------------------------------------------------- serving

    /**
     * Stream a stored binary (optionally followed by an installer trailer) to the client with an exact Content-Length, in 64 KiB chunks:
     * memory use does not depend on the file size. Verifies size and SHA-256 first, so a damaged or swapped file is never served.
     * Sends headers and exits the script on success; returns an error message (nothing sent) otherwise.
     */
    public static function stream(array $row, string $filename, string $trailer = ''): string
    {
        $path = self::pathFor($row);
        if (!is_file($path) || (int) filesize($path) !== (int) $row['size_bytes']) {
            return 'The stored agent binary is missing or damaged.';
        }
        if (!hash_equals((string) $row['sha256'], (string) hash_file('sha256', $path))) {
            return 'The stored agent binary failed its integrity check.';
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return 'The stored agent binary could not be read.';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $filename)) {
            $filename = 'RivetIT-Agent.exe';   // header injection is impossible: only this alphabet reaches a header
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @set_time_limit(0);
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . ((int) $row['size_bytes'] + strlen($trailer)));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Accel-Buffering: no');
        while (!feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
        }
        fclose($fh);
        echo $trailer;
        flush();
        exit;
    }
}
