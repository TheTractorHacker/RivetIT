<?php

declare(strict_types=1);

/**
 * Shared plumbing for the RivetCore adapter conformance tests (the *ConformanceTest.php files here).
 *
 * The kit (RivetCore\Testing\*ConformanceTestCase) ships with RivetCore v1.0.0-rc.1 and later. Until the composer pin reaches
 * it, each conformance file declares a single skipped placeholder test instead of the real class, so the suite stays green and
 * the skip message says why. See rivet-core docs/conformance.md.
 */
// Stand-ins for functions.php (which needs a whole app bootstrap); same 'ENC:' convention as SharedModulesShimTest.php so the files can share a process.
if (!function_exists('encryptSetting')) {
    function encryptSetting(string $p): string { return $p === '' ? '' : 'ENC:' . $p; }
}
if (!function_exists('decryptSetting')) {
    function decryptSetting(string $c): string { return str_starts_with($c, 'ENC:') ? substr($c, 4) : $c; }
}

final class KitSupport
{
    public const MISSING = 'RivetCore conformance kit not available (needs rivet/rivet-core >= 1.0.0-rc.1, or RIVETCORE_PHPUNIT_AUTOLOAD pointing at a Core checkout that has src/Testing/*ConformanceTestCase).';

    public static function has(string $kitClass): bool
    {
        return class_exists($kitClass);
    }

    /** Scratch database handle (never production); skips the calling test when RIVETCORE_TEST_DB_NAME is not set. */
    public static function db(): \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter
    {
        static $adapter = null;
        if ($adapter === null) {
            $name = getenv('RIVETCORE_TEST_DB_NAME');
            if (!$name) {
                throw new \PHPUnit\Framework\SkippedWithMessageException('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
            }
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $m = new \mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
            $m->set_charset('utf8mb4');
            $m->query("SET SESSION sql_mode=''");
            $GLOBALS['mysqli'] = $m;
            $adapter = new \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter($m);
        }

        return $adapter;
    }
}
