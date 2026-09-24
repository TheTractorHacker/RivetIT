<?php

namespace ITFlow\Training\Media;

/**
 * Finds a Knowledge Base media file on disk for the KB snapshot import - a non-exiting
 * replacement for kbMediaResolve() (agent/includes/kb_media_serve.php), which is guarded by
 * FROM_KB_MEDIA, ends the request on failure and builds its root from DOCUMENT_ROOT (unset on
 * the CLI).
 *
 * Only the two shapes the KB writers produce are accepted, with the same reference-name rule
 * the KB serve path uses (functions.php isUploadReferenceName()):
 *   <article_id>/<name>   article-scoped images and attachments
 *   <name>                the flat TinyMCE pool
 * The candidate is realpath()'d and must still be a regular file under <app>/uploads/kb, so
 * no "..", symlink or crafted name can reach anything else.
 */
final class KbFileLocator
{
    private const NAME_RE = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/';

    private static ?string $rootOverride = null;

    /** @return string|null absolute path of an existing, readable file inside the KB upload root */
    public static function resolve(string $relativePath): ?string
    {
        $parts = explode('/', $relativePath);
        if (count($parts) === 2) {
            if (!ctype_digit($parts[0]) || strlen($parts[0]) > 10 || (int) $parts[0] < 1 || !self::validName($parts[1])) {
                return null;
            }
        } elseif (count($parts) !== 1 || !self::validName($parts[0])) {
            return null;
        }
        $root = realpath(self::root());
        if ($root === false) {
            return null;
        }
        $real = realpath($root . '/' . $relativePath);
        if ($real === false || strncmp($real, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
            return null;
        }
        if (!is_file($real) || !is_readable($real)) {
            return null;
        }
        return $real;
    }

    /** TEST SEAM ONLY: point the locator at a fixture directory (null restores the default). */
    public static function useRoot(?string $root): void
    {
        self::$rootOverride = $root;
    }

    private static function root(): string
    {
        return self::$rootOverride ?? dirname(__DIR__, 3) . '/uploads/kb';
    }

    private static function validName(string $name): bool
    {
        return $name !== '' && strlen($name) <= 255 && preg_match(self::NAME_RE, $name) === 1;
    }
}
