<?php

namespace ITFlow\Training\Core;

/**
 * Short-lived server-side state for training requests, in place of $_SESSION.
 *
 * PHP sessions here are `files` sessions that lock per user, so every training endpoint
 * closes the session right after authentication and no training code writes to it (spec §0
 * "Sessions"). Preview attempts, import previews and video-check tokens live here instead:
 * one small JSON file per token, bound to the user it was issued to, with an expiry.
 *
 *   dir:  sys_get_temp_dir()/itflow_training_<md5(DATABASE())>/<kind>/   (0700)
 *         php8.4-fpm runs with PrivateTmp=no, so the CLI and FPM see the same directory;
 *         the database name keeps a scratch/verify DB's files apart from the live site's.
 *   file: <sha256(token)>.json (0600). The token itself is never stored, so a directory
 *         listing does not reveal usable tokens. The file's mtime is set to its expiry,
 *         which lets the ~1%-of-writes sweep drop expired files without reading them.
 *
 * The directory is refused if it is a symlink, not owned by this process's user, or
 * group/world accessible - /tmp is shared, and a pre-created directory must not be able to
 * capture another user's state.
 *
 * Bound to a connection by bind() (Ctx's constructor does this), because DATABASE() is the
 * namespace and Core classes do not read legacy globals.
 */
final class Scratch
{
    private const KIND_RE = '/^[a-z][a-z0-9_]{0,31}$/';
    private const TOKEN_RE = '/^[0-9a-f]{32}$/';
    private const MAX_TTL = 86400;

    private static ?\mysqli $db = null;
    private static ?string $root = null;

    public static function bind(\mysqli $db): void
    {
        if (self::$db !== $db) {
            self::$db = $db;
            self::$root = null;
        }
    }

    /** Stores $data for $userId and returns a new 32-hex token. */
    public static function put(string $kind, int $userId, array $data, int $ttlS): string
    {
        $ttlS = max(1, min(self::MAX_TTL, $ttlS));
        $token = bin2hex(random_bytes(16));
        $exp = time() + $ttlS;
        self::write(self::path($kind, $token), $userId, $exp, $data);
        if (random_int(1, 100) === 1) {
            self::sweep($kind);
        }
        return $token;
    }

    /** Returns the stored data, or null when missing, expired or issued to another user. */
    public static function get(string $kind, string $token, int $userId): ?array
    {
        if (preg_match(self::TOKEN_RE, $token) !== 1) {
            return null;
        }
        $rec = self::read(self::path($kind, $token));
        if ($rec === null || $rec['u'] !== $userId || $rec['exp'] < time()) {
            return null;
        }
        return $rec['d'];
    }

    /** Overwrites the data behind an existing, valid token (same expiry). */
    public static function replace(string $kind, string $token, int $userId, array $data): void
    {
        if (preg_match(self::TOKEN_RE, $token) !== 1) {
            throw new \InvalidArgumentException('Scratch: bad token');
        }
        $path = self::path($kind, $token);
        $rec = self::read($path);
        if ($rec === null || $rec['u'] !== $userId || $rec['exp'] < time()) {
            throw new \RuntimeException('Scratch: token not found or expired');
        }
        self::write($path, $userId, $rec['exp'], $data);
    }

    /**
     * Single use: returns the data and deletes the file. Of two concurrent take()s only one
     * gets the data - the claim is an atomic rename().
     */
    public static function take(string $kind, string $token, int $userId): ?array
    {
        if (preg_match(self::TOKEN_RE, $token) !== 1) {
            return null;
        }
        $path = self::path($kind, $token);
        $rec = self::read($path);
        if ($rec === null || $rec['u'] !== $userId) {
            return null;
        }
        $claimed = $path . '.taken.' . bin2hex(random_bytes(4));
        if (!@rename($path, $claimed)) {
            return null;
        }
        $rec = self::read($claimed);
        @unlink($claimed);
        if ($rec === null || $rec['u'] !== $userId || $rec['exp'] < time()) {
            return null;
        }
        return $rec['d'];
    }

    /** Removes expired files of one kind (also run on ~1% of writes). Returns the number removed. */
    public static function sweep(string $kind): int
    {
        $dir = self::dir($kind);
        $now = time();
        $n = 0;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (!is_file($f) || is_link($f)) {
                continue;
            }
            $mtime = @filemtime($f);
            if ($mtime === false) {
                continue;
            }
            // A live file's mtime IS its expiry. Leftover .tmp./.taken. files (a crash between
            // write and rename, or between claim and unlink) get an extra hour of grace.
            $grace = str_ends_with($f, '.json') ? 0 : 3600;
            if ($mtime + $grace < $now && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Seconds since stamp($name) last ran, or null when it never did (or the stamp is unreadable).
     * For cheap "at most every N seconds" throttles (department job group sync); not user-bound.
     */
    public static function stampAge(string $name): ?int
    {
        $path = self::stampPath($name);
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $mtime = @filemtime($path);
        return $mtime === false ? null : max(0, time() - $mtime);
    }

    /** Marks $name as done now (the file's mtime is the stamp; it holds no data). */
    public static function stamp(string $name): void
    {
        $path = self::stampPath($name);
        $old = umask(0077);
        try {
            if (!@touch($path)) {
                throw new \RuntimeException('Scratch: stamp failed');
            }
        } finally {
            umask($old);
        }
    }

    private static function stampPath(string $name): string
    {
        if (preg_match(self::KIND_RE, $name) !== 1) {
            throw new \InvalidArgumentException("Scratch: bad stamp name '$name'");
        }
        return self::dir('stamps') . '/' . $name . '.stamp';
    }

    private static function path(string $kind, string $token): string
    {
        return self::dir($kind) . '/' . hash('sha256', $token) . '.json';
    }

    private static function dir(string $kind): string
    {
        if (preg_match(self::KIND_RE, $kind) !== 1) {
            throw new \InvalidArgumentException("Scratch: bad kind '$kind'");
        }
        $dir = self::root() . '/' . $kind;
        self::ensureDir($dir);
        return $dir;
    }

    private static function root(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }
        if (self::$db === null) {
            throw new \LogicException('Scratch used before Scratch::bind() (construct a Ctx first)');
        }
        $row = self::$db->query("SELECT DATABASE() AS d")->fetch_assoc();
        $name = (string) ($row['d'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Scratch: no database selected');
        }
        $root = rtrim(sys_get_temp_dir(), '/') . '/itflow_training_' . md5($name);
        self::ensureDir($root);
        return self::$root = $root;
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            $old = umask(0077);
            try {
                if (!@mkdir($dir, 0700) && !is_dir($dir)) {
                    throw new \RuntimeException("Scratch: cannot create $dir");
                }
            } finally {
                umask($old);
            }
        }
        clearstatcache(true, $dir);
        if (is_link($dir)) {
            throw new \RuntimeException("Scratch: $dir is a symlink");
        }
        if (function_exists('posix_geteuid') && fileowner($dir) !== posix_geteuid()) {
            throw new \RuntimeException("Scratch: $dir is not owned by this user");
        }
        if ((fileperms($dir) & 0077) !== 0) {
            @chmod($dir, 0700);
            clearstatcache(true, $dir);
            if ((fileperms($dir) & 0077) !== 0) {
                throw new \RuntimeException("Scratch: $dir is group/world accessible");
            }
        }
    }

    private static function write(string $path, int $userId, int $exp, array $data): void
    {
        $json = json_encode(['u' => $userId, 'exp' => $exp, 'd' => $data],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $old = umask(0077);
        try {
            if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
                throw new \RuntimeException('Scratch: write failed');
            }
            @chmod($tmp, 0600);
            @touch($tmp, $exp);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException('Scratch: rename failed');
            }
        } finally {
            umask($old);
        }
    }

    /** @return array{u:int, exp:int, d:array}|null */
    private static function read(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $rec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($rec) || !is_int($rec['u'] ?? null) || !is_int($rec['exp'] ?? null) || !is_array($rec['d'] ?? null)) {
            return null;
        }
        return $rec;
    }
}
