<?php

namespace ITFlow\Training\Core;

/**
 * Live storage used by training media, per kind.
 *
 * "Live" means rows whose file is still on disk: every training_media row, minus those whose
 * LATEST file event (media.file_purged / media.file_restored) is a purge. Purges delete only
 * the file - the row, its hash and its events stay (spec §3.3 MediaPurger) - so SUM(media_bytes)
 * alone would over-count after a purge. The media budget and Admin › Training's usage bar both
 * use this definition, which counts bytes actually stored (the budget protects the disk).
 */
final class MediaUsage
{
    /** @return array<string, array{count:int, bytes:int}> kind => totals (only kinds that have rows) */
    public static function liveByKind(\mysqli $db): array
    {
        $rows = Db::all($db, "SELECT m.media_kind, COUNT(*) AS n, COALESCE(SUM(m.media_bytes), 0) AS bytes
            FROM training_media m
            LEFT JOIN (
                SELECT tevent_entity_id AS mid, MAX(tevent_seq) AS last_seq
                FROM training_events
                WHERE tevent_entity_type = 'media' AND tevent_type IN ('media.file_purged', 'media.file_restored')
                GROUP BY tevent_entity_id
            ) f ON f.mid = m.media_id
            LEFT JOIN training_events le ON le.tevent_seq = f.last_seq
            WHERE le.tevent_type IS NULL OR le.tevent_type <> 'media.file_purged'
            GROUP BY m.media_kind
            ORDER BY m.media_kind");
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['media_kind']] = ['count' => (int) $r['n'], 'bytes' => (int) $r['bytes']];
        }
        return $out;
    }

    public static function liveBytes(\mysqli $db): int
    {
        $total = 0;
        foreach (self::liveByKind($db) as $k) {
            $total += $k['bytes'];
        }
        return $total;
    }
}
