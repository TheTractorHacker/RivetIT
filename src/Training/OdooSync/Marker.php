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
 * inst8 names this RivetIT install without posting its installation id to Odoo: the first 8 hex
 * characters of a sha256 over the installation id (or the database name when there is none).
 * It is stable across a domain move.
 *
 * The HR-note target's follow-up note on a void carries its own marker, the create marker with V in place of
 * C ([ITFLOW:<inst8>:V0000000012]): delimited and fixed width like the others, so it never matches the first
 * note's marker and the first note's marker never matches it.
 *
 * "ITFLOW" in the marker and the "itflow-training-odoo|" inst8 label are frozen through the RivetIT
 * rename: lines already in Odoo carry them, and a different spelling would make every write-back
 * look new and create duplicates (see REBRANDING.md). Only the words around the marker changed.
 */
final class Marker
{
    public const RE = '/^\[ITFLOW:[0-9a-f]{8}:[CA][0-9]{10}\]$/';

    /** Any marker this app writes, including the void follow-up note's V marker. */
    public const RE_ANY = '/^\[ITFLOW:[0-9a-f]{8}:[CAV][0-9]{10}\]$/';

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

    /** The follow-up (void) note's marker for a completion's create marker: C -> V. */
    public static function voidOf(string $marker): string
    {
        if (preg_match('/^(\[ITFLOW:[0-9a-f]{8}:)C([0-9]{10}\])$/', $marker, $m) !== 1) {
            throw new \InvalidArgumentException('Marker::voidOf: not a completion marker');
        }
        return $m[1] . 'V' . $m[2];
    }

    /** Exact containment of $marker in the text of an Odoo html field (tags stripped, entities decoded). */
    public static function inHtml(?string $html, string $marker): bool
    {
        if ($html === null || $html === '' || preg_match(self::RE_ANY, $marker) !== 1) {
            return false;
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_contains($text, $marker);
    }
}
