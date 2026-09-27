<?php

namespace ITFlow\Training\Certificates;

/**
 * The public certificate check's rate limit (Phase 5 spec §1.4 #1, §3.3, §8). File-based and
 * run BEFORE any database query, with fixed caps and no miss counter: tokens are 144-bit HMACs,
 * so counting misses adds nothing, and a miss cap would let a few junk requests a minute lock
 * everyone out.
 *
 *   global  GLOBAL_PER_MIN checks a minute, every visitor together
 *   client  CLIENT_PER_MIN checks a minute per visitor, counted only when the request came
 *           through the trusted proxy (REMOTE_ADDR in TRUSTED_PROXIES) and CF-Connecting-IP is a
 *           valid IP. A spoofed header without the proxy address is ignored; one sent through
 *           the proxy can only rotate client buckets, and the global cap still bounds it.
 *
 * Store: <root>/itflow_training_verify_<md5(db)>_<euid>, 0700, one file per bucket per minute
 * ('g-<YmdHi>', 'c-<sha256(ip) first 16 hex>-<YmdHi>', 0600), each read-increment-written under
 * flock(LOCK_EX). The euid suffix means a CLI run as another user can never create a directory
 * that PHP-FPM cannot write. <root> is sys_get_temp_dir() unless a test passes its own.
 *
 * Fail-open by design: when the store is unusable (not creatable, not ours, not writable) hit()
 * returns 0 after one error_log line. The throttle protects against load; the token protects
 * the data.
 */
final class VerifyThrottle
{
    public const GLOBAL_PER_MIN = 240;
    public const CLIENT_PER_MIN = 20;
    public const TRUSTED_PROXIES = ['10.1.0.31'];

    private const PREFIX = 'itflow_training_verify_';
    private const FILE_RE = '/^(g|c-[0-9a-f]{16})-[0-9]{12}$/D';

    private string $dir;
    private int $globalCap;
    private int $clientCap;
    private bool $warned = false;

    /** @param array{0:int,1:int}|null $caps tests only: [global, client] */
    public function __construct(string $dbName, ?string $root = null, ?array $caps = null)
    {
        $this->dir = self::dirFor($dbName, $root);
        $this->globalCap = max(1, (int) ($caps[0] ?? self::GLOBAL_PER_MIN));
        $this->clientCap = max(1, (int) ($caps[1] ?? self::CLIENT_PER_MIN));
    }

    /**
     * Counts this request. Returns 0 when it may proceed, otherwise the seconds until the next
     * minute starts (for Retry-After).
     */
    public function hit(int $nowTs, array $server): int
    {
        if (!$this->ensureDir()) {
            return 0;
        }
        $minute = gmdate('YmdHi', $nowTs);
        $wait = 60 - ($nowTs % 60);
        $over = false;

        $g = $this->bump('g-' . $minute);
        if ($g === null) {
            return 0;
        }
        if ($g > $this->globalCap) {
            $over = true;
        }

        $ip = self::clientIp($server);
        if ($ip !== null) {
            $c = $this->bump('c-' . substr(hash('sha256', $ip), 0, 16) . '-' . $minute);
            if ($c !== null && $c > $this->clientCap) {
                $over = true;
            }
        }

        if (random_int(1, 100) === 1) {
            self::pruneDir($this->dir, $nowTs, 3600);
        }
        return $over ? max(1, $wait) : 0;
    }

    /**
     * Deletes bucket files older than $olderThanS (the daily worker runs this as www-data, the
     * same uid as PHP-FPM, so it prunes the FPM store). Returns how many were removed.
     */
    public static function prune(string $dbName, ?string $root = null, int $olderThanS = 3600): int
    {
        $dir = self::dirFor($dbName, $root);
        if (!is_dir($dir) || is_link($dir) || !self::ownedByMe($dir)) {
            return 0;
        }
        return self::pruneDir($dir, time(), $olderThanS);
    }

    /** The client address used for the per-visitor bucket, or null (global bucket only). */
    public static function clientIp(array $server): ?string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if (!in_array($remote, self::TRUSTED_PROXIES, true)) {
            return null;
        }
        $cf = $server['HTTP_CF_CONNECTING_IP'] ?? null;
        if (!is_string($cf)) {
            return null;
        }
        $cf = trim($cf);
        return filter_var($cf, FILTER_VALIDATE_IP) !== false ? $cf : null;
    }

    private static function dirFor(string $dbName, ?string $root): string
    {
        $base = rtrim($root ?? sys_get_temp_dir(), '/');
        return $base . '/' . self::PREFIX . md5($dbName) . '_' . self::euid();
    }

    private static function euid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    private static function ownedByMe(string $path): bool
    {
        $o = @fileowner($path);
        return $o !== false && $o === self::euid();
    }

    private function ensureDir(): bool
    {
        $d = $this->dir;
        if (!is_dir($d)) {
            $old = umask(0077);
            $ok = @mkdir($d, 0700);
            umask($old);
            clearstatcache(true, $d);   // another worker may have created it between the check and mkdir()
            if (!$ok && !is_dir($d)) {
                return $this->failOpen('cannot create the store');
            }
        }
        if (is_link($d) || !self::ownedByMe($d)) {
            return $this->failOpen('the store is not owned by this user');
        }
        if (!is_writable($d)) {
            return $this->failOpen('the store is not writable');
        }
        return true;
    }

    /** Read-increment-write one bucket under an exclusive lock; the new count, or null on I/O failure. */
    private function bump(string $name): ?int
    {
        $path = $this->dir . '/' . $name;
        $new = !is_file($path);
        $old = umask(0077);
        $fh = @fopen($path, 'c+');
        umask($old);
        if ($fh === false) {
            $this->failOpen('cannot open a bucket');
            return null;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                $this->failOpen('cannot lock a bucket');
                return null;
            }
            $raw = stream_get_contents($fh);
            $n = (is_string($raw) && preg_match('/^[0-9]{1,9}$/D', trim($raw)) === 1) ? (int) trim($raw) : 0;
            $n++;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) $n);
            fflush($fh);
            flock($fh, LOCK_UN);
            if ($new) {
                @chmod($path, 0600);
            }
            return $n;
        } finally {
            fclose($fh);
        }
    }

    private static function pruneDir(string $dir, int $nowTs, int $olderThanS): int
    {
        $n = 0;
        $cut = $nowTs - max(60, $olderThanS);
        foreach ((array) @scandir($dir) as $f) {
            if (!is_string($f) || preg_match(self::FILE_RE, $f) !== 1) {
                continue;
            }
            $p = $dir . '/' . $f;
            $m = @filemtime($p);
            if ($m !== false && $m < $cut && @unlink($p)) {
                $n++;
            }
        }
        return $n;
    }

    private function failOpen(string $why): bool
    {
        if (!$this->warned) {
            $this->warned = true;
            error_log('Training verify throttle: ' . $why . ' (' . $this->dir . '); checks are not rate-limited');
        }
        return false;
    }
}
