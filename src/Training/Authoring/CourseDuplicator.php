<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Uid;
use ITFlow\Training\Quiz\QuizCloner;

/**
 * "Duplicate" and "Duplicate as training course": a new, never-published draft with the same
 * settings, translations, sections, live lessons (all variants), resources, tags, prerequisites
 * and quizzes (QuizCloner, Lane D). Media is content-addressed and simply shared; video
 * verifications are per video, so the copy inherits them.
 *
 * The copy gets a new uid, no code (course codes are unique), no revision and version 0. Path
 * memberships are not copied. A document can be duplicated as a training course
 * ($asTraining); a training course cannot become a document.
 */
final class CourseDuplicator
{
    private const COPY_COLS = ['course_summary', 'course_description_html', 'course_category_id', 'course_cover_media_id', 'course_color',
        'course_default_language', 'course_languages', 'course_required_languages', 'course_regulation_ref', 'course_responsible_user_id',
        'course_sequential', 'course_est_minutes', 'course_validity_months', 'course_renewal_lead_days', 'course_requires_signature',
        'course_attestation_text', 'course_is_qualification', 'course_needs_online', 'course_needs_session', 'course_needs_practical',
        'course_external_only', 'course_component_window_days', 'course_allow_trainer_attest', 'course_eval_checklist', 'course_template_key'];

    public function __construct(private readonly Ctx $c)
    {
    }

    public function duplicate(int $courseId, ?string $name, bool $asTraining = false): int
    {
        $db = $this->c->db;
        $src = Guard::course($db, $courseId);
        if ($asTraining && $src['course_kind'] !== 'document') {
            throw ApiException::validation(['as_training' => 'Only a required document can be duplicated as a training course.']);
        }
        $name = $name === null ? null : Patch::text(['name' => $name], 'name', 200, true);
        if ($name === null) {
            $suffix = ' (copy)';
            $name = mb_substr((string) $src['course_name'], 0, 200 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
        }
        $kind = $asTraining ? 'training' : (string) $src['course_kind'];

        return Db::tx($db, function () use ($db, $courseId, $src, $name, $kind): int {
            $cols = array_merge(['course_uid', 'course_kind', 'course_name', 'course_code', 'course_draft_updated_at_utc', 'course_created_by'], self::COPY_COLS);
            $values = array_merge([Uid::new('c'), $kind, $name, null, Clock::nowUtc(), $this->c->userId], array_map(static fn($c) => $src[$c], self::COPY_COLS));
            if ($src['course_category_id'] !== null
                && Db::one($db, 'SELECT tcat_id FROM training_categories WHERE tcat_id = ? AND tcat_archived_at IS NULL', 'i', [(int) $src['course_category_id']]) === null) {
                $values[array_search('course_category_id', $cols, true)] = null;
            }
            $newId = Db::insert(
                $db,
                'INSERT INTO training_courses (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                str_repeat('s', count($cols)),
                $values
            );

            $i18n = new I18nService($this->c);
            $i18n->copy('course', $courseId, $newId);
            TagService::copyLinks($db, 'course', $courseId, $newId);
            Db::exec($db, 'INSERT INTO training_course_prereqs (prereq_course_id, prereq_requires_course_id, prereq_created_by)
                SELECT ?, prereq_requires_course_id, ? FROM training_course_prereqs WHERE prereq_course_id = ?', 'iii', [$newId, $this->c->userId, $courseId]);

            // Sections, in order.
            $sectionMap = [];
            foreach (Db::all($db, 'SELECT csection_id, csection_title, csection_sort FROM training_course_sections WHERE csection_course_id = ? ORDER BY csection_sort, csection_id', 'i', [$courseId]) as $s) {
                $sid = Rows::insertSection($db, $newId, (string) $s['csection_title'], (int) $s['csection_sort']);
                $sectionMap[(int) $s['csection_id']] = $sid;
                $i18n->copy('section', (int) $s['csection_id'], $sid);
            }

            // Live lessons with variants, resources and tags.
            $lessonMap = [];
            $lessons = Db::all($db, 'SELECT ' . Guard::LESSON_COLS . ' FROM training_lessons WHERE lesson_course_id = ? AND lesson_archived_at IS NULL ORDER BY lesson_sort, lesson_id', 'i', [$courseId]);
            foreach ($lessons as $l) {
                $oldSection = $l['lesson_section_id'] === null ? null : (int) $l['lesson_section_id'];
                $lid = Rows::insertLesson($db, $newId, $oldSection === null ? null : ($sectionMap[$oldSection] ?? null), (string) $l['lesson_type'], (int) $l['lesson_sort'], $this->c->userId, [
                    'required' => (int) $l['lesson_required'] === 1,
                    'duration_s' => $l['lesson_duration_s'] === null ? null : (int) $l['lesson_duration_s'],
                    'allow_download' => (int) $l['lesson_allow_download'] === 1,
                    'preview_enabled' => (int) $l['lesson_preview_enabled'] === 1,
                    'responsible_user_id' => $l['lesson_responsible_user_id'] === null ? null : (int) $l['lesson_responsible_user_id'],
                    'thumb_media_id' => $l['lesson_thumb_media_id'] === null ? null : (int) $l['lesson_thumb_media_id'],
                    'min_watch_pct' => (int) $l['lesson_min_watch_pct'],
                    'ack_require_signature' => (int) $l['lesson_ack_require_signature'] === 1,
                    'ack_require_pin' => (int) $l['lesson_ack_require_pin'] === 1,
                ]);
                $lessonMap[(int) $l['lesson_id']] = $lid;
                LessonService::copyVariants($db, (int) $l['lesson_id'], $lid, $this->c->userId);
                ResourceService::copyAll($db, (int) $l['lesson_id'], $lid, $this->c->userId);
                TagService::copyLinks($db, 'lesson', (int) $l['lesson_id'], $lid);
            }

            if ($lessonMap !== [] && Db::one($db, 'SELECT q.quiz_id FROM training_quizzes q JOIN training_lessons l ON l.lesson_id = q.quiz_lesson_id
                    WHERE l.lesson_course_id = ? AND l.lesson_archived_at IS NULL LIMIT 1', 'i', [$courseId]) !== null) {
                QuizCloner::cloneCourse($this->c, $courseId, $newId, $lessonMap);
            }
            return $newId;
        });
    }
}
