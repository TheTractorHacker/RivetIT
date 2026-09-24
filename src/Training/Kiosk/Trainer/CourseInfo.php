<?php

namespace ITFlow\Training\Kiosk\Trainer;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\RevisionCache;

/**
 * What trainer mode needs to know about a course, read from its CURRENT published revision
 * (the JSON the learners were trained on; P3 spec §3.6 "the server rebuilds the checklist from
 * the CURRENT revision's course.eval_checklist"). Only training_courses / training_revisions
 * (Phase 1 tables) are read here.
 *
 * null when the course does not exist, is archived, or has never been published.
 */
final class CourseInfo
{
    /**
     * @return array{id:int, uid:string, kind:string, name:string, revision_id:int, languages:list<string>,
     *   needs_online:bool, needs_session:bool, needs_practical:bool, allow_trainer_attest:bool,
     *   requires_signature:bool, window_days:int, checklist:list<array{item:string, critical:bool}>}|null
     */
    public static function current(\mysqli $db, int $courseId, string $lang = 'en'): ?array
    {
        if ($courseId < 1) {
            return null;
        }
        $c = Db::one($db, 'SELECT course_id, course_uid, course_kind, course_name, course_current_revision_id, course_archived_at
            FROM training_courses WHERE course_id = ?', 'i', [$courseId]);
        if ($c === null || $c['course_archived_at'] !== null || $c['course_current_revision_id'] === null) {
            return null;
        }
        return self::fromRevision($db, (int) $c['course_current_revision_id'], $c, $lang);
    }

    /** The same facts for a specific revision of the course (a session keeps the revision it was opened on). */
    public static function atRevision(\mysqli $db, int $courseId, int $revisionId, string $lang = 'en'): ?array
    {
        $c = Db::one($db, 'SELECT course_id, course_uid, course_kind, course_name, course_current_revision_id, course_archived_at
            FROM training_courses WHERE course_id = ?', 'i', [$courseId]);
        if ($c === null) {
            return null;
        }
        return self::fromRevision($db, $revisionId, $c, $lang);
    }

    private static function fromRevision(\mysqli $db, int $revisionId, array $c, string $lang): ?array
    {
        try {
            $rev = RevisionCache::get($db, $revisionId);
        } catch (\RuntimeException $e) {
            error_log('Kiosk trainer: revision unreadable: ' . get_class($e));
            return null;
        }
        if ($rev['course_id'] !== (int) $c['course_id']) {
            return null;
        }
        $course = is_array($rev['doc']['course'] ?? null) ? $rev['doc']['course'] : [];
        $comp = is_array($course['components'] ?? null) ? $course['components'] : [];
        $text = is_array($course['text'] ?? null) ? $course['text'] : [];
        $default = (string) ($course['default_language'] ?? 'en');
        $name = $text[$lang]['name'] ?? $text[$default]['name'] ?? (string) $c['course_name'];
        $checklist = [];
        foreach (is_array($course['eval_checklist'] ?? null) ? $course['eval_checklist'] : [] as $row) {
            if (is_array($row) && isset($row['item']) && is_string($row['item']) && trim($row['item']) !== '') {
                $checklist[] = ['item' => (string) $row['item'], 'critical' => !empty($row['critical'])];
            }
        }
        return [
            'id' => (int) $c['course_id'],
            'uid' => (string) $c['course_uid'],
            'kind' => (string) ($course['kind'] ?? $c['course_kind']),
            'name' => is_string($name) && $name !== '' ? $name : (string) $c['course_name'],
            'revision_id' => $revisionId,
            'languages' => $rev['languages'],
            'needs_online' => !empty($comp['online']),
            'needs_session' => !empty($comp['session']),
            'needs_practical' => !empty($comp['practical']),
            'allow_trainer_attest' => !empty($comp['allow_trainer_attest']),
            'requires_signature' => array_key_exists('requires_signature', $course) ? (bool) $course['requires_signature'] : true,
            'window_days' => (int) ($comp['window_days'] ?? 90),
            'checklist' => $checklist,
        ];
    }

    /** Published, non-archived course ids (id order). */
    public static function publishedIds(\mysqli $db): array
    {
        return array_map(static fn($r) => (int) $r['course_id'], Db::all($db, 'SELECT course_id FROM training_courses
            WHERE course_archived_at IS NULL AND course_current_revision_id IS NOT NULL ORDER BY course_name, course_id'));
    }
}
