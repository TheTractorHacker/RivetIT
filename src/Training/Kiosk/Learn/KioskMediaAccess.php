<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Media\MediaStore;

/**
 * Who may receive a training_media row's bytes through kiosk/media.php (P3 spec §3.4, §5.9, §8 "Media").
 *
 *   evidence                       never
 *   r > 0                          the row is in training_revision_media of revision r (PDF originals,
 *                                  files and any dl=1 also need rmedia_downloadable = 1) AND
 *                                    learner:  r is the revision of ANY run of the session's contact,
 *                                              or the current revision of a non-archived course;
 *                                    trainer / checkin / handoff: the second clause only
 *   r = 0                          the cover image of a live learning path
 *
 * The caller (kiosk/media.php) turns every refusal into the same plain 404.
 */
final class KioskMediaAccess
{
    public static function canServe(KioskCtx $k, array $row, int $revisionId, bool $download): bool
    {
        $kind = (string) ($row['media_kind'] ?? '');
        $id = (int) ($row['media_id'] ?? 0);
        if ($id < 1 || $kind === '' || $kind === 'evidence' || !in_array($kind, MediaStore::KINDS, true) || $k->ksess === null) {
            return false;
        }
        $db = $k->db();
        if ($revisionId === 0) {
            if ($download || $kind !== 'image') {
                return false;
            }
            return Db::one($db, 'SELECT 1 AS ok FROM training_paths WHERE tpath_cover_media_id = ? AND tpath_archived_at IS NULL LIMIT 1', 'i', [$id]) !== null;
        }
        $needDownloadable = $download || in_array($kind, ['pdf', 'file'], true);
        $inManifest = Db::one($db, 'SELECT 1 AS ok FROM training_revision_media WHERE rmedia_revision_id = ? AND rmedia_media_id = ?'
            . ($needDownloadable ? ' AND rmedia_downloadable = 1' : '') . ' LIMIT 1', 'ii', [$revisionId, $id]);
        if ($inManifest === null) {
            return false;
        }
        $live = Db::one($db, 'SELECT 1 AS ok FROM training_courses WHERE course_current_revision_id = ? AND course_archived_at IS NULL LIMIT 1', 'i', [$revisionId]);
        if ($live !== null) {
            return true;
        }
        if ((string) ($k->ksess['ksess_role'] ?? '') !== 'learner') {
            return false;
        }
        return Db::one($db, 'SELECT 1 AS ok FROM training_runs WHERE trun_contact_id = ? AND trun_revision_id = ? LIMIT 1', 'ii',
            [$k->contactId(), $revisionId]) !== null;
    }
}
