<?php

namespace ITFlow\Training\OdooSync;

/**
 * The idempotency marker written into every Odoo line's description (spec §3.4, critique #2):
 * [ITFLOW:<inst8>:C0000000012] for completion 12, [ITFLOW:<inst8>:A0000000007] for award 7.
 *
 * Delimited and fixed width, so an exact containment check never lets C12 match C123. It contains
 * no '%' and no '_', so Odoo's ilike (SQL LIKE wildcards) cannot widen the search either; the
 * search is only a pre-filter and every hit is re-checked with inHtml().
 *
 * inst8 names this ITFlow install without posting its installation id to Odoo: the first 8 hex
 * characters of a sha256 over the installation id (or the database name when there is none).
 * It is stable across a domain move.
 */
final class Marker
{
    public const RE = '/^\[ITFLOW:[0-9a-f]{8}:[CA][0-9]{10}\]$/';

    public static function inst8(string $installationId, string $dbName): string
    {
        return substr(hash('sha256', 'itflow-training-odoo|' . ($installationId !== '' ? $installationId : 'db:' . $dbName)), 0, 8);
    }

    /** From config.php's $installation_id and $database (web requests and the worker load it). */
    public static function inst8FromGlobals(): string
    {
        return self::inst8((string) ($GLOBALS['installation_id'] ?? ''), (string) ($GLOBALS['database'] ?? ''));
    }

    /**
     * Like inst8FromGlobals(), but when neither global is set (a CLI script that did not load
     * config.php) the connection's DATABASE() names the install, never an empty string.
     */
    public static function inst8For(\mysqli $db): string
    {
        $inst = (string) ($GLOBALS['installation_id'] ?? '');
        $name = (string) ($GLOBALS['database'] ?? '');
        if ($inst === '' && $name === '') {
            try {
                $res = $db->query('SELECT DATABASE() AS d');
                $name = (string) ($res->fetch_assoc()['d'] ?? '');
                $res->free();
            } catch (\mysqli_sql_exception) {
                $name = '';
            }
        }
        return self::inst8($inst, $name);
    }

    /** @param string $sourceType 'completion' | 'award' */
    public static function for(string $inst8, string $sourceType, int $sourceId): string
    {
        if (!preg_match('/^[0-9a-f]{8}$/', $inst8)) {
            throw new \InvalidArgumentException('Marker: inst8 must be 8 hex characters');
        }
        if ($sourceId < 1 || $sourceId > 9999999999) {
            throw new \InvalidArgumentException('Marker: source id out of range');
        }
        $letter = match ($sourceType) {
            'completion' => 'C',
            'award' => 'A',
            default => throw new \InvalidArgumentException("Marker: unknown source type $sourceType"),
        };
        return '[ITFLOW:' . $inst8 . ':' . $letter . sprintf('%010d', $sourceId) . ']';
    }

    /** Exact containment of $marker in the text of an Odoo html field (tags stripped, entities decoded). */
    public static function inHtml(?string $html, string $marker): bool
    {
        if ($html === null || $html === '' || preg_match(self::RE, $marker) !== 1) {
            return false;
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_contains($text, $marker);
    }
}
