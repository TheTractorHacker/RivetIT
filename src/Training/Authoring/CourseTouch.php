<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;

/**
 * The cheap "unpublished changes" signal: course_draft_updated_at_utc.
 *
 * touch() runs only when data that is IN the revision JSON (spec §3.6) changed - never for
 * tags, prerequisites, category, responsible, paths, achievements or video checks - and only
 * when something actually changed (no-op patches never touch). The nav badge and the course
 * list compare it with the current revision's published_at; the authoritative has_changes is
 * the build-sha comparison (Lane D), which calls settle() whenever it finds the shas equal so a
 * stale "Unpublished changes" clears itself.
 *
 * Both write the owning course row, so inside a transaction they come AFTER the entity rows
 * (lock order: entity rows -> course row -> ledger head).
 */
final class CourseTouch
{
    public static function touch(\mysqli $db, int $courseId): void
    {
        Db::exec($db, 'UPDATE training_courses SET course_draft_updated_at_utc = ? WHERE course_id = ?', 'si', [Clock::nowUtc(), $courseId]);
    }

    /** The draft equals the published revision: draft_updated := published_at. */
    public static function settle(\mysqli $db, int $courseId, string $publishedAtUtc): void
    {
        Db::exec($db, 'UPDATE training_courses SET course_draft_updated_at_utc = ? WHERE course_id = ?', 'si', [$publishedAtUtc, $courseId]);
    }
}
