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
 *           valid IP. An IPv6 visitor is keyed on its /64 (one subscriber's allocation), so a new
 *           address per request does not give a new bucket. A spoofed header without the proxy
 *           address is ignored.
 *
 * Order, so that one visitor cannot spend everyone's budget:
 *   1 the global count for this minute is already at the cap -> wait, and nothing is written
 *   2 the visitor's bucket is counted; over its cap -> wait (the global budget is NOT charged)
 *   3 the global bucket is counted; over the cap -> wait
 *
 * Store: <root>/itflow_training_verify_<md5(db)>_<euid>, 0700, holding a FIXED set of files (0600):
 * 'g' and 'c-<first 3 hex of sha256(visitor key)>', so at most 4,097 files ever exist. Each holds
 * "<YmdHi> <count>" and is read-increment-written under flock(LOCK_EX); a count from an older minute
 * starts again at 1. Visitors sharing a shard share its per-minute cap, which is rare with 4,096
 * shards and harmless (it only makes the cap stricter). Nothing is pruned inside a request: the
 * daily worker (www-data, the same uid as PHP-FPM) runs prune(). The euid suffix means a CLI run as
 * another user can never create a directory that PHP-FPM cannot write. <root> is sys_get_temp_dir()
 * unless a test passes its own.
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

    /** Hex characters of sha256(visitor key) that name a client shard: 16^3 = 4,096 files at most. */
    public const SHARD_HEX = 3;

    private const PREFIX = 'itflow_training_verify_';
    /** Current names, plus the per-minute names an earlier build wrote (prune() still clears those). */
    private const FILE_RE = '/^(?:g|c-[0-9a-f]{3}|(?:g|c-[0-9a-f]{16})-[0-9]{12})$/D';

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
        $wait = max(1, 60 - ($nowTs % 60));

        // 1. Everyone's budget is already spent this minute: refuse without writing anything.
        if ($this->peek('g', $minute) >= $this->globalCap) {
            return $wait;
        }

        // 2. This visitor first, so a visitor over its own cap does not use up the shared budget.
        $key = self::clientKey($server);
        if ($key !== null) {
            $c = $this->bump(self::shardName($key), $minute);
            if ($c !== null && $c > $this->clientCap) {
                return $wait;
            }
        }

        // 3. The shared budget.
        $g = $this->bump('g', $minute);
        if ($g === null) {
            return 0;
        }
        return $g > $this->globalCap ? $wait : 0;
    }

    /**
     * Deletes bucket files not written for $olderThanS (the daily worker runs this as www-data, the
     * same uid as PHP-FPM, so it prunes the FPM store). A deleted shard simply starts again at 1.
     * Returns how many were removed.
     */
    public static function prune(string $dbName, ?string $root = null, int $olderThanS = 3600): int
    {
        $dir = self::dirFor($dbName, $root);
        if (!is_dir($dir) || is_link($dir) || !self::ownedByMe($dir)) {
            return 0;
        }
        $n = 0;
        $cut = time() - max(60, $olderThanS);
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

    /**
     * The per-visitor key: an IPv4 address as is; an IPv6 address as its /64 network (an IPv4-mapped
     * IPv6 address counts as the IPv4 address). Null when there is no trusted client address.
     */
    public static function clientKey(array $server): ?string
    {
        $ip = self::clientIp($server);
        if ($ip === null) {
            return null;
        }
        $bin = @inet_pton($ip);
        if (!is_string($bin)) {
            return null;
        }
        if (strlen($bin) === 4) {
            return $ip;
        }
        if (strlen($bin) !== 16) {
            return null;
        }
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $v4 = inet_ntop(substr($bin, 12));
            return is_string($v4) ? $v4 : null;
        }
        $net = inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
        return is_string($net) ? $net . '/64' : null;
    }

    /** The client shard file for a visitor key. */
    public static function shardName(string $key): string
    {
        return 'c-' . substr(hash('sha256', $key), 0, self::SHARD_HEX);
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

    /** "<YmdHi> <n>" -> n when it is $minute's count, else 0. */
    private static function countFor(mixed $raw, string $minute): int
    {
        if (!is_string($raw) || preg_match('/^([0-9]{12}) ([0-9]{1,9})$/D', trim($raw), $m) !== 1) {
            return 0;
        }
        return $m[1] === $minute ? (int) $m[2] : 0;
    }

    /** This minute's count in a bucket without changing it (0 when the file does not exist or cannot be read). */
    private function peek(string $name, string $minute): int
    {
        $path = $this->dir . '/' . $name;
        if (!is_file($path)) {
            return 0;
        }
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            return 0;
        }
        try {
            if (!flock($fh, LOCK_SH)) {
                return 0;
            }
            $n = self::countFor(stream_get_contents($fh), $minute);
            flock($fh, LOCK_UN);
            return $n;
        } finally {
            fclose($fh);
        }
    }

    /** Read-increment-write one bucket for $minute under an exclusive lock; the new count, or null on I/O failure. */
    private function bump(string $name, string $minute): ?int
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
            $n = self::countFor(stream_get_contents($fh), $minute) + 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $minute . ' ' . $n);
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

    private function failOpen(string $why): bool
    {
        if (!$this->warned) {
            $this->warned = true;
            error_log('Training verify throttle: ' . $why . ' (' . $this->dir . '); checks are not rate-limited');
        }
        return false;
    }
}
