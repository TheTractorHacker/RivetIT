<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Core\Db;

/**
 * Decodes a published revision once per request (P3 spec §3.1). The stored JSON must hash to
 * revision_sha256, otherwise \RuntimeException: a learner is never shown content that does not
 * match its ledgered hash.
 *
 * Returns ['id','course_id','number','sha256','languages' (list),'requires_retraining' (bool),'doc' (array)].
 */
final class RevisionCache
{
    /** @var array<int, array> */
    private static array $memo = [];

    public static function get(\mysqli $db, int $revisionId): array
    {
        if (isset(self::$memo[$revisionId])) {
            return self::$memo[$revisionId];
        }
        $row = Db::one($db, 'SELECT revision_id, revision_course_id, revision_number, revision_json, revision_sha256, revision_languages,
                revision_requires_retraining FROM training_revisions WHERE revision_id = ?', 'i', [$revisionId]);
        if ($row === null) {
            throw new \RuntimeException("RevisionCache: revision #$revisionId not found");
        }
        $json = (string) $row['revision_json'];
        if (!hash_equals((string) $row['revision_sha256'], hash('sha256', $json))) {
            throw new \RuntimeException("RevisionCache: revision #$revisionId JSON does not match its sha256");
        }
        $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($doc)) {
            throw new \RuntimeException("RevisionCache: revision #$revisionId JSON is not an object");
        }
        $langs = [];
        foreach (explode(',', (string) $row['revision_languages']) as $l) {
            $l = strtolower(trim($l));
            if ($l !== '' && !in_array($l, $langs, true)) {
                $langs[] = $l;
            }
        }
        return self::$memo[$revisionId] = [
            'id' => (int) $row['revision_id'],
            'course_id' => (int) $row['revision_course_id'],
            'number' => (int) $row['revision_number'],
            'sha256' => (string) $row['revision_sha256'],
            'languages' => $langs,
            'requires_retraining' => (int) $row['revision_requires_retraining'] === 1,
            'doc' => $doc,
        ];
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$memo = [];
    }
}
