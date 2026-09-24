<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * Course sections: create (optionally right after another section), rename (per language) and
 * delete (move the lessons to another section / unsectioned, or delete them with it).
 *
 * Section titles and order are in the revision JSON, so every change touches the course.
 * Sections have no version: a rename is a single field and last write wins.
 */
final class SectionService
{
    public function __construct(private readonly Ctx $c)
    {
    }

    public function create(int $courseId, string $title, ?int $afterSectionId): array
    {
        $db = $this->c->db;
        Guard::writableCourse($db, $courseId);
        $title = Patch::text(['title' => $title], 'title', 200, true);
        if ($afterSectionId !== null) {
            Guard::sectionOfCourse($db, $afterSectionId, $courseId, 'after_section_id');
        }
        $id = Db::tx($db, function () use ($db, $courseId, $title, $afterSectionId): int {
            $rows = Db::all($db, 'SELECT csection_id, csection_sort FROM training_course_sections WHERE csection_course_id = ? ORDER BY csection_sort, csection_id FOR UPDATE', 'i', [$courseId]);
            $order = array_map(static fn($r) => (int) $r['csection_id'], $rows);
            $pos = count($order);
            if ($afterSectionId !== null) {
                $idx = array_search($afterSectionId, $order, true);
                $pos = $idx === false ? count($order) : $idx + 1;
            }
            $id = Rows::insertSection($db, $courseId, $title, $pos);
            array_splice($order, $pos, 0, [$id]);
            $rows[] = ['csection_id' => $id, 'csection_sort' => $pos];
            self::renumber($db, $order, $rows);
            CourseTouch::touch($db, $courseId);
            return $id;
        });
        return self::load($this->c, $id);
    }

    /** Renames a section. A non-default $lang stores a translation. */
    public function update(int $sectionId, string $title, ?string $lang): array
    {
        $db = $this->c->db;
        $section = Guard::section($db, $sectionId);
        $course = Guard::writableCourse($db, (int) $section['csection_course_id']);
        $default = (string) $course['course_default_language'];
        if ($lang !== null) {
            Guard::courseLang($course, $lang);
        }
        $translated = $lang !== null && $lang !== $default;
        $title = Patch::text(['title' => $title], 'title', 200, !$translated);

        Db::tx($db, function () use ($db, $sectionId, $title, $translated, $lang): void {
            $current = Guard::section($db, $sectionId, true);
            if ($translated) {
                $changed = (new I18nService($this->c))->set('section', $sectionId, (string) $lang, 'title', $title);
            } else {
                $changed = $current['csection_title'] !== $title;
                if ($changed) {
                    Db::exec($db, 'UPDATE training_course_sections SET csection_title = ? WHERE csection_id = ?', 'si', [$title, $sectionId]);
                }
            }
            if ($changed) {
                CourseTouch::touch($db, (int) $current['csection_course_id']);
            }
        });
        return self::load($this->c, $sectionId);
    }

    /**
     * mode 'move': lessons go to the end of $targetSectionId (null = unsectioned).
     * mode 'delete_lessons': the section's lessons are deleted (archived) with it.
     */
    public function delete(int $sectionId, string $mode, ?int $targetSectionId): void
    {
        $db = $this->c->db;
        $section = Guard::section($db, $sectionId);
        $courseId = (int) $section['csection_course_id'];
        $course = Guard::writableCourse($db, $courseId);
        if (!in_array($mode, ['move', 'delete_lessons'], true)) {
            throw ApiException::validation(['mode' => 'Choose whether to keep or delete the lessons.']);
        }
        if ($mode === 'move' && $targetSectionId !== null) {
            if ($targetSectionId === $sectionId) {
                throw ApiException::validation(['target_section_id' => 'Choose a different section.']);
            }
            Guard::sectionOfCourse($db, $targetSectionId, $courseId, 'target_section_id');
        }

        Db::tx($db, function () use ($db, $sectionId, $courseId, $course, $mode, $targetSectionId): void {
            $lessons = Db::all($db, 'SELECT lesson_id, lesson_type FROM training_lessons WHERE lesson_section_id = ? AND lesson_archived_at IS NULL ORDER BY lesson_sort, lesson_id FOR UPDATE', 'i', [$sectionId]);
            Guard::section($db, $sectionId, true);
            if ($mode === 'move') {
                $sort = Rows::nextLessonSort($db, $courseId, $targetSectionId);
                foreach ($lessons as $l) {
                    Db::exec($db, 'UPDATE training_lessons SET lesson_section_id = ?, lesson_sort = ? WHERE lesson_id = ?', 'iii', [$targetSectionId, min($sort++, 65535), (int) $l['lesson_id']]);
                }
            } else {
                if ($course['course_kind'] === 'document') {
                    foreach ($lessons as $l) {
                        if ($l['lesson_type'] === 'acknowledgment') {
                            throw new ApiException(422, 'document_shape', "The acknowledgment can't be removed from a required document.");
                        }
                    }
                }
                foreach ($lessons as $l) {
                    Db::exec($db, 'UPDATE training_lessons SET lesson_archived_at = NOW(), lesson_section_id = NULL WHERE lesson_id = ?', 'i', [(int) $l['lesson_id']]);
                }
            }
            // Archived lessons that still point at this section fall back to unsectioned.
            Db::exec($db, 'UPDATE training_lessons SET lesson_section_id = NULL WHERE lesson_section_id = ?', 'i', [$sectionId]);
            Db::exec($db, 'DELETE FROM training_course_sections WHERE csection_id = ?', 'i', [$sectionId]);
            (new I18nService($this->c))->deleteFor('section', [$sectionId]);
            $rest = Db::all($db, 'SELECT csection_id, csection_sort FROM training_course_sections WHERE csection_course_id = ? ORDER BY csection_sort, csection_id', 'i', [$courseId]);
            self::renumber($db, array_map(static fn($r) => (int) $r['csection_id'], $rest), $rest);
            CourseTouch::touch($db, $courseId);
        });
    }

    /** The section shape used by CourseDetail: {id, uid, title, i18n, sort, lesson_ids}. */
    public static function load(Ctx $c, int $sectionId): array
    {
        $s = Guard::section($c->db, $sectionId);
        $lessonIds = array_map(static fn($r) => (int) $r['lesson_id'], Db::all(
            $c->db,
            'SELECT lesson_id FROM training_lessons WHERE lesson_section_id = ? AND lesson_archived_at IS NULL ORDER BY lesson_sort, lesson_id',
            'i',
            [$sectionId]
        ));
        return self::shape($s, (new I18nService($c))->forEntity('section', $sectionId), $lessonIds);
    }

    public static function shape(array $s, array $i18n, array $lessonIds): array
    {
        $titles = [];
        foreach ($i18n as $lang => $fields) {
            if (isset($fields['title'])) {
                $titles[$lang] = ['title' => $fields['title']];
            }
        }
        return [
            'id' => (int) $s['csection_id'],
            'uid' => (string) $s['csection_uid'],
            'course_id' => (int) $s['csection_course_id'],
            'title' => (string) $s['csection_title'],
            'i18n' => $titles === [] ? new \stdClass() : $titles,
            'sort' => (int) $s['csection_sort'],
            'lesson_ids' => array_values($lessonIds),
        ];
    }

    /** Writes sort = position for every section whose sort differs. */
    private static function renumber(\mysqli $db, array $order, array $rows): void
    {
        $current = [];
        foreach ($rows as $r) {
            $current[(int) $r['csection_id']] = (int) $r['csection_sort'];
        }
        foreach ($order as $i => $id) {
            if (($current[$id] ?? -1) !== $i) {
                Db::exec($db, 'UPDATE training_course_sections SET csection_sort = ? WHERE csection_id = ?', 'ii', [min($i, 65535), $id]);
            }
        }
    }
}
