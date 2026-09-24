<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * Who may receive the bytes of a training_media row - the ONE rule shared by
 * agent/training_media.php and the media_get action (the Phase 3 kiosk media endpoint copies it).
 * Spec §3.3 / §8 "Media authorization":
 *
 *   evidence            never, at any level (Phase 2 streams evidence through its own path)
 *   level >= 2 (author) any other kind - authors work with drafts, which are not published yet
 *   level 1 (read)      only media that a PUBLISHED revision of a non-archived course lists in
 *                       its media manifest (training_revision_media), or the cover of a live
 *                       learning path. PDF originals and resource files additionally need the
 *                       manifest's downloadable flag (the lesson's "Allow download"); so does
 *                       any explicit download (dl=1) - a reader may view a published page image
 *                       but only download what the author allowed.
 *   anything else       refused
 *
 * Callers turn a refusal into a 404, so the endpoint never reveals whether an id exists. The
 * module toggle and the session are the caller's job; this only answers for a row it is given.
 */
final class MediaAccess
{
    public static function canServe(Ctx $c, array $row, bool $download): bool
    {
        $kind = (string) ($row['media_kind'] ?? '');
        $id = (int) ($row['media_id'] ?? 0);
        if ($id < 1 || $kind === '' || $kind === 'evidence') {
            return false;
        }
        if ($c->level >= 2) {
            return in_array($kind, MediaStore::KINDS, true);
        }
        if ($c->level < 1) {
            return false;
        }

        $needDownloadable = $download || in_array($kind, ['pdf', 'file'], true);
        $published = Db::one($c->db,
            'SELECT 1 AS ok FROM training_revision_media rm
               JOIN training_revisions r ON r.revision_id = rm.rmedia_revision_id
               JOIN training_courses co ON co.course_id = r.revision_course_id
              WHERE rm.rmedia_media_id = ? AND co.course_archived_at IS NULL'
                . ($needDownloadable ? ' AND rm.rmedia_downloadable = 1' : '') . '
              LIMIT 1', 'i', [$id]);
        if ($published !== null) {
            return true;
        }
        if ($needDownloadable) {
            return false;
        }
        $cover = Db::one($c->db, 'SELECT 1 AS ok FROM training_paths WHERE tpath_cover_media_id = ? AND tpath_archived_at IS NULL LIMIT 1', 'i', [$id]);
        return $cover !== null && $kind === 'image';
    }
}
