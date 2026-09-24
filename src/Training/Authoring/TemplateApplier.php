<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Quiz\QuizService;

/**
 * Applies a TemplateCatalog entry to a (new) course: the suggested defaults, the suggested
 * category when the course has none, and - for training-kind templates - the outline of
 * sections and empty lesson stubs with their titles in every course language the template
 * has. Quiz stubs get their quiz (and auto bank) through QuizService; final exams get the
 * 'exam' role. Hints are never stored.
 *
 * Runs inside the caller's transaction (course creation), or its own.
 */
final class TemplateApplier
{
    private const DEFAULT_COLUMNS = [
        'regulation_ref' => 'course_regulation_ref',
        'validity_months' => 'course_validity_months',
        'requires_signature' => 'course_requires_signature',
        'needs_online' => 'course_needs_online',
        'needs_session' => 'course_needs_session',
        'needs_practical' => 'course_needs_practical',
        'is_qualification' => 'course_is_qualification',
    ];

    public function __construct(private readonly Ctx $c)
    {
    }

    public function apply(int $courseId, string $key): void
    {
        $template = TemplateCatalog::get($key);
        if ($template === null) {
            throw ApiException::validation(['template_key' => 'That template does not exist.']);
        }
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $courseId, $template, $key): void {
            $course = Guard::writableCourse($db, $courseId, true);
            if ($course['course_kind'] !== $template['kind']) {
                throw ApiException::validation(['template_key' => $template['kind'] === 'document'
                    ? 'This template is for a required document.'
                    : 'This template is for a training course.']);
            }

            $sets = ['course_template_key = ?'];
            $params = [$key];
            foreach ($template['defaults'] as $k => $value) {
                $col = self::DEFAULT_COLUMNS[$k] ?? null;
                if ($col === null) {
                    throw new \LogicException("TemplateApplier: unknown default '$k'");
                }
                $sets[] = "$col = ?";
                $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
            }
            if ($course['course_category_id'] === null && $template['category'] !== null) {
                $cat = Db::one($db, 'SELECT tcat_id FROM training_categories WHERE tcat_name = ? AND tcat_archived_at IS NULL', 's', [$template['category']]);
                if ($cat !== null) {
                    $sets[] = 'course_category_id = ?';
                    $params[] = (int) $cat['tcat_id'];
                }
            }
            Db::exec($db, 'UPDATE training_courses SET ' . implode(', ', $sets) . ' WHERE course_id = ?', str_repeat('s', count($params)) . 'i', array_merge($params, [$courseId]));

            if ($template['kind'] === 'training') {
                $this->outline($course, $template);
            }
            CourseTouch::touch($db, $courseId);
        });
    }

    private function outline(array $course, array $template): void
    {
        $db = $this->c->db;
        $courseId = (int) $course['course_id'];
        $default = (string) $course['course_default_language'];
        $langs = array_values(array_filter(Guard::languages($course), static fn($l) => in_array($l, TemplateCatalog::LANGS, true)));
        $baseLang = in_array($default, TemplateCatalog::LANGS, true) ? $default : 'en';
        $i18n = new I18nService($this->c);
        $quizzes = null;
        $sectionSort = Rows::nextSectionSort($db, $courseId);

        foreach ($template['sections'] as $s) {
            $sectionId = Rows::insertSection($db, $courseId, $s['title'][$baseLang], $sectionSort++);
            foreach ($langs as $lang) {
                if ($lang !== $default) {
                    $i18n->set('section', $sectionId, $lang, 'title', $s['title'][$lang]);
                }
            }
            foreach ($s['lessons'] as $sort => $l) {
                $lessonId = Rows::insertLesson($db, $courseId, $sectionId, $l['type'], $sort, $this->c->userId);
                foreach (array_values(array_unique(array_merge([$default], $langs))) as $lang) {
                    Rows::putVariant($db, $lessonId, $lang, ['lvar_title' => $l['title'][$lang] ?? $l['title'][$baseLang]], $this->c->userId);
                }
                if ($l['type'] === 'quiz') {
                    $quizzes ??= new QuizService($this->c);
                    $quizzes->ensureForLesson($lessonId, $l['role'] ?? 'standalone');
                }
            }
        }
    }
}
