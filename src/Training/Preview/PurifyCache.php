<?php

namespace ITFlow\Training\Preview;

use ITFlow\Training\Media\ArticleSanitizer;

/**
 * Content-addressed cache of ArticleSanitizer::purify() results for LearnerView projections.
 *
 * LearnerView purifies every HTML field again at projection (spec §3.7, §8), on every preview
 * of every course. A revision never changes, and a draft's HTML changes only when an author saves
 * it, so the same input is purified over and over; a large article costs a second of CPU and
 * tens of MB each time. The result depends only on
 *   - the HTML itself,
 *   - the media kinds the URI pass may look up (the projected document's manifest, id => kind;
 *     a media id's kind never changes),
 *   - the sanitizer (this file's CACHE_VERSION, the ArticleSanitizer source and HTMLPurifier's version),
 * so the key is a sha256 over exactly those, and a hit is byte-for-byte what purify() would return.
 *
 * Storage: sys_get_temp_dir()/itflow_training_purify_<euid>/<key>.html, directory 0700 owned by
 * this process's user (refused if it is a symlink, foreign-owned or group/world accessible, like
 * Core\Scratch), files 0600, written to a temp name and renamed. Entries unused for 30 days are
 * swept on ~1% of writes. Any cache problem falls back to purifying directly: the cache can make
 * a projection faster, never different.
 */
final class PurifyCache
{
    private const CACHE_VERSION = 1;
    private const TTL_S = 2592000;
    private const MIN_BYTES = 2048;   // small fields: purifying is cheaper than a file round trip

    private static ?string $dir = null;
    private static bool $disabled = false;
    private static ?string $version = null;

    /**
     * @param array<int, string> $kinds media id => kind, as ArticleSanitizer's URI pass would see them
     */
    public static function purify(string $html, array $kinds): string
    {
        $run = static function () use ($html, $kinds): string {
            $out = ArticleSanitizer::purify($html, static fn(int $id): ?string => $kinds[$id] ?? null);
            return (string) ($out['html'] ?? '');
        };
        if (strlen($html) < self::MIN_BYTES) {
            return $run();
        }
        $dir = self::dir();
        if ($dir === null) {
            return $run();
        }
        ksort($kinds);
        $key = hash('sha256', self::version() . "\n" . json_encode($kinds) . "\n" . $html);
        $file = $dir . '/' . $key . '.html';
        if (is_file($file) && !is_link($file)) {
            $hit = @file_get_contents($file);
            if (is_string($hit)) {
                @touch($file);
                return $hit;
            }
        }
        $out = $run();
        self::write($dir, $file, $out);
        return $out;
    }

    private static function version(): string
    {
        return self::$version ??= self::CACHE_VERSION . ':' . \HTMLPurifier::VERSION . ':'
            . (string) @hash_file('sha256', (new \ReflectionClass(ArticleSanitizer::class))->getFileName());
    }

    private static function dir(): ?string
    {
        if (self::$disabled) {
            return null;
        }
        if (self::$dir !== null) {
            return self::$dir;
        }
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($euid === null) {
            self::$disabled = true;   // cannot prove who owns the directory
            return null;
        }
        $dir = rtrim(sys_get_temp_dir(), '/') . '/itflow_training_purify_' . $euid;
        if (!is_dir($dir)) {
            $old = umask(0077);
            try {
                @mkdir($dir, 0700);
            } finally {
                umask($old);
            }
        }
        clearstatcache(true, $dir);
        if (!is_dir($dir) || is_link($dir) || fileowner($dir) !== $euid || (fileperms($dir) & 0077) !== 0) {
            error_log("Training PurifyCache: $dir is not a private directory of this user; caching is off");
            self::$disabled = true;
            return null;
        }
        return self::$dir = $dir;
    }

    private static function write(string $dir, string $file, string $html): void
    {
        $tmp = $dir . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        $old = umask(0077);
        try {
            if (@file_put_contents($tmp, $html) === strlen($html) && @rename($tmp, $file)) {
                if (random_int(1, 100) === 1) {
                    self::sweep($dir);
                }
                return;
            }
            @unlink($tmp);
        } finally {
            umask($old);
        }
    }

    private static function sweep(string $dir): void
    {
        $cut = time() - self::TTL_S;
        foreach (glob($dir . '/*.html') ?: [] as $f) {
            if (is_file($f) && !is_link($f) && (int) @filemtime($f) < $cut) {
                @unlink($f);
            }
        }
        foreach (glob($dir . '/.*.tmp') ?: [] as $f) {
            if (is_file($f) && !is_link($f) && (int) @filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
    }
}
