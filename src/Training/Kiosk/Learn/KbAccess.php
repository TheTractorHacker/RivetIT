<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Core\Db;

/**
 * Knowledge Base access for a kiosk learner: the client portal's rule, applied to the learner's own department.
 * A learner reads an article only when it is switched on for the training portal (kb_article_training_visible, off by default and independent of the department portal switch), belongs to the
 * company-wide set or to their department, and is not archived. kiosk/kb_media.php re-runs the same clause per
 * request, so a hidden or other-department article never leaks its images.
 */
final class KbAccess
{
    /** The learner's department id, or null when the contact is gone/archived or has none (no KB then). */
    public static function clientId(\mysqli $db, int $contactId): ?int
    {
        if ($contactId < 1) {
            return null;
        }
        $row = Db::one($db, 'SELECT contact_client_id FROM contacts WHERE contact_id = ? AND contact_archived_at IS NULL', 'i', [$contactId]);
        $cid = (int) ($row['contact_client_id'] ?? 0);
        return $cid > 0 ? $cid : null;
    }

    /** The visibility predicate for kb_articles (unaliased columns). $clientId is an int, so it is safe to inline. */
    public static function scopeSql(int $clientId): string
    {
        return 'kb_article_training_visible = 1 AND kb_article_client_id IN (0, ' . (int) $clientId . ') AND kb_article_archived_at IS NULL';
    }
}
