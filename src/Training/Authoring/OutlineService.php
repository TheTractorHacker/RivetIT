<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * The builder's drag-and-drop outline save (outline_reorder): the COMPLETE outline, every
 * section in order with its lessons in order, plus the unsectioned lessons. Anything missing,
 * repeated or belonging to another course is a 422 - a partial list can never silently drop a
 * lesson out of the outline.
 *
 * Order is revision content, so a real change touches the course; nothing is version-bumped
 * (versions guard typed content only), and an unchanged outline writes nothing.
 */
final class OutlineService
{
    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * @param list<array{section_id:int, lesson_ids:list<int>}> $sections
     * @param list<int> $unsectioned
     */
    public function reorder(int $courseId, array $sections, array $unsectioned): void
    {
        $db = $this->c->db;
        Guard::writableCourse($db, $courseId);

        $wantSections = [];
        $wantLessons = [];   // lesson id => [section id|null, sort]
        foreach ($sections as $i => $s) {
            if (!is_array($s) || !isset($s['section_id'])) {
                throw ApiException::validation(['sections' => 'Each section needs its section_id and lesson_ids.']);
            }
            $sid = self::intId($s['section_id'], 'sections');
            if (isset($wantSections[$sid])) {
                throw ApiException::validation(['sections' => 'A section appears twice.']);
            }
            $wantSections[$sid] = $i;
            $ids = $s['lesson_ids'] ?? [];
            if (!is_array($ids) || !array_is_list($ids)) {
                throw ApiException::validation(['sections' => 'lesson_ids must be a list.']);
            }
            foreach ($ids as $j => $lid) {
                self::place($wantLessons, self::intId($lid, 'sections'), $sid, $j);
            }
        }
        foreach ($unsectioned as $j => $lid) {
            self::place($wantLessons, self::intId($lid, 'unsectioned'), null, $j);
        }

        Db::tx($db, function () use ($db, $courseId, $wantSections, $wantLessons): void {
            $lessons = Db::all($db, 'SELECT lesson_id, lesson_section_id, lesson_sort FROM training_lessons
                WHERE lesson_course_id = ? AND lesson_archived_at IS NULL ORDER BY lesson_id FOR UPDATE', 'i', [$courseId]);
            $secs = Db::all($db, 'SELECT csection_id, csection_sort FROM training_course_sections
                WHERE csection_course_id = ? ORDER BY csection_id FOR UPDATE', 'i', [$courseId]);

            $haveSections = [];
            foreach ($secs as $s) {
                $haveSections[(int) $s['csection_id']] = (int) $s['csection_sort'];
            }
            $haveLessons = [];
            foreach ($lessons as $l) {
                $haveLessons[(int) $l['lesson_id']] = [$l['lesson_section_id'] === null ? null : (int) $l['lesson_section_id'], (int) $l['lesson_sort']];
            }

            $sectionIds = array_keys($wantSections);
            if (count($sectionIds) !== count($haveSections) || array_diff($sectionIds, array_keys($haveSections)) !== []) {
                throw ApiException::validation(['sections' => 'The outline is out of date or lists a section from another course. Reload and try again.']);
            }
            $lessonIds = array_keys($wantLessons);
            if (count($lessonIds) !== count($haveLessons) || array_diff($lessonIds, array_keys($haveLessons)) !== []) {
                throw ApiException::validation(['sections' => 'The outline is out of date or lists content from another course. Reload and try again.']);
            }

            $changed = false;
            foreach ($wantSections as $sid => $sort) {
                if ($haveSections[$sid] !== $sort) {
                    Db::exec($db, 'UPDATE training_course_sections SET csection_sort = ? WHERE csection_id = ?', 'ii', [min($sort, 65535), $sid]);
                    $changed = true;
                }
            }
            foreach ($wantLessons as $lid => [$sid, $sort]) {
                if ($haveLessons[$lid][0] !== $sid || $haveLessons[$lid][1] !== $sort) {
                    Db::exec($db, 'UPDATE training_lessons SET lesson_section_id = ?, lesson_sort = ? WHERE lesson_id = ?', 'iii', [$sid, min($sort, 65535), $lid]);
                    $changed = true;
                }
            }
            if ($changed) {
                CourseTouch::touch($db, $courseId);
            }
        });
    }

    private static function place(array &$want, int $lessonId, ?int $sectionId, int $sort): void
    {
        if (isset($want[$lessonId])) {
            throw ApiException::validation(['sections' => 'A lesson appears twice in the outline.']);
        }
        $want[$lessonId] = [$sectionId, $sort];
    }

    private static function intId(mixed $v, string $field): int
    {
        if (is_string($v) && preg_match('/^[0-9]{1,10}$/', $v) === 1) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < 1) {
            throw ApiException::validation([$field => 'Must be a list of ids.']);
        }
        return $v;
    }
}
