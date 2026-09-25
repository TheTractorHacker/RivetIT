<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\Uid;
use ITFlow\Training\Media\ArticleSanitizer;
use ITFlow\Training\Media\CoverLibrary;
use ITFlow\Training\Media\MediaException;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\Quiz\QuizService;

/**
 * Courses and required documents: the list, create (plain, document-kind shape, or from a
 * template), the builder's CourseDetail, the settings autosave, languages, tags,
 * archive/restore (ledger-evented) and delete-draft.
 *
 * course_version guards the typed course fields; course_kind is immutable (not in the
 * allowlist); category and responsible are live catalog configuration (no CourseTouch);
 * archived courses are read-only (409 archived) until restored.
 */
final class CourseService
{
    /** course_update allowlist. name/summary/description_html/attestation_text are per language. */
    public const UPDATE_FIELDS = ['name', 'code', 'summary', 'description_html', 'category_id', 'cover_media_id', 'color', 'regulation_ref',
        'responsible_user_id', 'sequential', 'est_minutes', 'validity_months', 'renewal_lead_days', 'requires_signature', 'attestation_text',
        'is_qualification', 'needs_online', 'needs_session', 'needs_practical', 'external_only', 'component_window_days',
        'allow_trainer_attest', 'eval_checklist'];

    /** Fields that are not in the revision JSON: they never touch the course. */
    private const NOT_REVISION = ['category_id', 'responsible_user_id'];

    public const LIST_STATUSES = ['active', 'draft', 'published', 'changes', 'archived', 'all'];
    public const LIST_SORTS = ['updated', 'name', 'lessons'];

    /** The published revision's default-language name / summary and its code (level-1 search, sort and display). */
    private const REV_NAME_SQL = "JSON_VALUE(r.revision_json, CONCAT('$.course.text.', JSON_VALUE(r.revision_json, '$.course.default_language'), '.name'))";
    private const REV_SUMMARY_SQL = "JSON_VALUE(r.revision_json, CONCAT('$.course.text.', JSON_VALUE(r.revision_json, '$.course.default_language'), '.summary'))";
    private const REV_CODE_SQL = "JSON_VALUE(r.revision_json, '$.course.code')";

    private const ACK_STATEMENT = [
        'en' => ['I have read and understand ', '. I will follow it.'],
        'es' => ['He leído y entiendo ', '. Lo cumpliré.'],
    ];
    private const ACK_TITLE = ['en' => 'Acknowledgment', 'es' => 'Reconocimiento'];

    public function __construct(private readonly Ctx $c)
    {
    }

    // =========================================================================================
    // List
    // =========================================================================================

    /**
     * CourseSummary list, level-aware: level 1 sees published, non-archived courses only, with
     * the name, code, summary and counts of the PUBLISHED version; level 2+ sees drafts too.
     * Nothing a level-1 reader can observe depends on the draft: the search matches the published
     * text, the order uses published values, and updated_at is the version's publish time.
     *
     * @param array{q?:?string, kind?:?string, status?:?string, category_id?:?int, tag_ids?:list<int>, mine?:bool, sort?:?string} $filters
     * @return array{courses: list<array>, facets: array}
     */
    public function list(array $filters): array
    {
        $db = $this->c->db;
        $reader = $this->c->level < 2;
        $where = [];
        $types = '';
        $params = [];

        $status = $reader ? 'published' : ($filters['status'] ?? 'active');
        switch ($status) {
            case 'draft':
                $where[] = 'c.course_archived_at IS NULL AND c.course_current_revision_id IS NULL';
                break;
            case 'published':
                $where[] = 'c.course_archived_at IS NULL AND c.course_current_revision_id IS NOT NULL';
                break;
            case 'changes':
                $where[] = 'c.course_archived_at IS NULL AND c.course_current_revision_id IS NOT NULL AND c.course_draft_updated_at_utc > r.revision_published_at_utc';
                break;
            case 'archived':
                $where[] = 'c.course_archived_at IS NOT NULL';
                break;
            case 'all':
                break;
            default:
                $where[] = 'c.course_archived_at IS NULL';
        }
        if (!empty($filters['kind'])) {
            $where[] = 'c.course_kind = ?';
            $types .= 's';
            $params[] = $filters['kind'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'c.course_category_id = ?';
            $types .= 'i';
            $params[] = (int) $filters['category_id'];
        }
        $tagIds = array_values(array_unique(array_map('intval', $filters['tag_ids'] ?? [])));
        if ($tagIds !== []) {
            $where[] = "EXISTS (SELECT 1 FROM training_tag_links tl WHERE tl.ttlink_entity = 'course' AND tl.ttlink_entity_id = c.course_id AND tl.ttlink_tag_id IN (" . implode(',', array_fill(0, count($tagIds), '?')) . '))';
            $types .= str_repeat('i', count($tagIds));
            array_push($params, ...$tagIds);
        }
        if (!empty($filters['mine'])) {
            $where[] = 'c.course_responsible_user_id = ?';
            $types .= 'i';
            $params[] = $this->c->userId;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = Patch::like($q);
            $where[] = $reader
                ? '(' . self::REV_NAME_SQL . ' LIKE ? OR ' . self::REV_CODE_SQL . ' LIKE ? OR ' . self::REV_SUMMARY_SQL . ' LIKE ?)'
                : '(c.course_name LIKE ? OR c.course_code LIKE ? OR c.course_summary LIKE ?)';
            $types .= 'sss';
            array_push($params, $like, $like, $like);
        }
        $order = $reader
            ? match ($filters['sort'] ?? 'updated') {
                'name' => 'rev_name ASC, c.course_id ASC',
                'lessons' => 'rev_lessons DESC, rev_name ASC',
                default => 'r.revision_published_at_utc DESC, c.course_id DESC',
            }
            : match ($filters['sort'] ?? 'updated') {
                'name' => 'c.course_name ASC, c.course_id ASC',
                'lessons' => 'lessons_count DESC, c.course_name ASC',
                default => 'COALESCE(c.course_updated_at, c.course_created_at) DESC, c.course_id DESC',
            };

        $rows = Db::all($db, 'SELECT ' . self::summaryColumns($reader) . '
            FROM training_courses c
            LEFT JOIN training_revisions r ON r.revision_id = c.course_current_revision_id
            ' . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where)) . "
            ORDER BY $order
            LIMIT 1000", $types, $params);

        return ['courses' => $this->summaries($rows, $reader), 'facets' => $this->facets($reader)];
    }

    // =========================================================================================
    // Create
    // =========================================================================================

    /**
     * @param list<string> $languages
     * @param string|null $coverKey a CoverLibrary key; null = the template's (or kind's) default, 'none' = no cover
     * @param string|null $color    null = the chosen cover's default tint
     */
    public function create(string $kind, string $name, ?int $categoryId, array $languages, ?string $templateKey,
        ?string $coverKey = null, ?string $color = null): int
    {
        $db = $this->c->db;
        if (!in_array($kind, ['training', 'document'], true)) {
            throw ApiException::validation(['kind' => 'Choose a training course or a required document.']);
        }
        $name = Patch::text(['name' => $name], 'name', 200, true);
        if ($categoryId !== null) {
            CategoryService::requireLive($db, $categoryId);
        }
        [$default, $langs] = $this->normaliseLanguages($languages);
        if ($templateKey !== null) {
            $template = TemplateCatalog::get($templateKey);
            if ($template === null) {
                throw ApiException::validation(['template_key' => 'That template does not exist.']);
            }
            if ($template['kind'] !== $kind) {
                throw ApiException::validation(['template_key' => $template['kind'] === 'document'
                    ? 'This template is for a required document.' : 'This template is for a training course.']);
            }
        }

        // The cover is ingested BEFORE the transaction (MediaStore never runs inside one). It is
        // decoration: if storing it fails (media budget full, lock busy) the course is still created.
        $coverKey ??= CoverLibrary::defaultFor($kind, $templateKey);
        $coverId = null;
        if ($coverKey !== 'none') {
            $cover = CoverLibrary::get($coverKey);
            if ($cover === null) {
                throw ApiException::validation(['cover_key' => 'That cover does not exist.']);
            }
            $color ??= $cover['color'];
            try {
                $coverId = (int) CoverLibrary::ingest($this->c, $coverKey)['media_id'];
            } catch (MediaException | \RuntimeException $e) {
                error_log('Training: default cover ' . $coverKey . ' not stored: ' . $e->getMessage());
            }
        }

        // The document shape's statements are generated HTML; purify them like any author HTML.
        $statements = [];
        if ($kind === 'document') {
            foreach ($langs as $lang) {
                $parts = self::ACK_STATEMENT[$lang] ?? self::ACK_STATEMENT['en'];
                $html = '<p>' . htmlspecialchars($parts[0], ENT_QUOTES, 'UTF-8') . '<strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                    . '</strong>' . htmlspecialchars($parts[1], ENT_QUOTES, 'UTF-8') . '</p>';
                $clean = MediaRefs::purify($db, $html)['html'] ?? $html;
                $statements[$lang] = ['html' => $clean, 'words' => ArticleSanitizer::wordCount($clean)];
            }
        }

        return Db::tx($db, function () use ($db, $kind, $name, $categoryId, $default, $langs, $templateKey, $statements, $coverId, $color): int {
            $courseId = Db::insert(
                $db,
                'INSERT INTO training_courses (course_uid, course_kind, course_name, course_category_id, course_cover_media_id, course_color,
                    course_default_language, course_languages, course_required_languages, course_attestation_text, course_draft_updated_at_utc,
                    course_created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'sssiissssssi',
                [Uid::new('c'), $kind, $name, $categoryId, $coverId, $color, $default, implode(',', $langs), $default,
                 $this->c->settings->attestationDefault, Clock::nowUtc(), $this->c->userId]
            );

            if ($kind === 'document') {
                $docId = Rows::insertLesson($db, $courseId, null, 'document', 0, $this->c->userId);
                $ackId = Rows::insertLesson($db, $courseId, null, 'acknowledgment', 1, $this->c->userId);
                foreach ($langs as $lang) {
                    Rows::putVariant($db, $docId, $lang, ['lvar_title' => $name], $this->c->userId);
                    Rows::putVariant($db, $ackId, $lang, [
                        'lvar_title' => self::ACK_TITLE[$lang] ?? self::ACK_TITLE['en'],
                        'lvar_body_html' => $statements[$lang]['html'],
                        'lvar_word_count' => $statements[$lang]['words'],
                    ], $this->c->userId);
                }
            }
            if ($templateKey !== null) {
                (new TemplateApplier($this->c))->apply($courseId, $templateKey);
            }
            return $courseId;
        });
    }

    // =========================================================================================
    // Read
    // =========================================================================================

    /** The builder's CourseDetail (spec §6.1). */
    public function get(int $courseId): array
    {
        $db = $this->c->db;
        $course = Guard::course($db, $courseId);
        $languages = Guard::languages($course);
        $default = (string) $course['course_default_language'];

        $sections = Db::all($db, 'SELECT csection_id, csection_uid, csection_course_id, csection_title, csection_sort FROM training_course_sections
            WHERE csection_course_id = ? ORDER BY csection_sort, csection_id', 'i', [$courseId]);
        $lessons = Db::all($db, 'SELECT lesson_id, lesson_section_id FROM training_lessons
            WHERE lesson_course_id = ? AND lesson_archived_at IS NULL ORDER BY lesson_sort, lesson_id', 'i', [$courseId]);
        $lessonIds = array_map(static fn($l) => (int) $l['lesson_id'], $lessons);
        $facts = LessonFacts::load($db, $lessonIds, $languages, true);

        $bySection = [];
        $unsectioned = [];
        $sectionIds = array_map(static fn($s) => (int) $s['csection_id'], $sections);
        foreach ($lessons as $l) {
            $sid = $l['lesson_section_id'] === null ? null : (int) $l['lesson_section_id'];
            if ($sid !== null && in_array($sid, $sectionIds, true)) {
                $bySection[$sid][] = (int) $l['lesson_id'];
            } else {
                $unsectioned[] = (int) $l['lesson_id'];
            }
        }
        $sectionI18n = (new I18nService($this->c))->forEntities('section', $sectionIds);
        $sectionOut = array_map(static fn($s) => SectionService::shape($s, $sectionI18n[(int) $s['csection_id']] ?? [], $bySection[(int) $s['csection_id']] ?? []), $sections);

        $summaries = [];
        $total = 0;
        foreach ($lessonIds as $id) {
            $summary = LessonView::summary($facts, $id, $languages, $default);
            $total += $summary['duration_s'];
            $summaries[] = $summary;
        }

        return [
            'course' => $this->courseShape($course, (int) ceil($total / 60)),
            'tags' => TagService::forEntities($db, 'course', [$courseId])[$courseId] ?? [],
            'prereqs' => self::prereqs($db, $courseId),
            'sections' => $sectionOut,
            'unsectioned_lesson_ids' => $unsectioned,
            'lessons' => $summaries,
            'quiz_summaries' => (new QuizService($this->c))->summariesForCourse($courseId),
        ];
    }

    /** CourseDetail.course for one course (used by course_update responses and conflicts). */
    public function courseObject(int $courseId): array
    {
        $course = Guard::course($this->c->db, $courseId);
        return $this->courseShape($course, $this->autoMinutes([$courseId])[$courseId] ?? 0);
    }

    // =========================================================================================
    // Update
    // =========================================================================================

    /**
     * The settings autosave. $lang (optional) selects the language for translatable fields; a
     * non-default language stores them in training_i18n.
     *
     * @return array{course: array, version: int, warnings: list<array>}
     */
    public function update(int $courseId, int $version, array $fields, ?string $lang): array
    {
        $db = $this->c->db;
        $course = Guard::writableCourse($db, $courseId);
        $default = (string) $course['course_default_language'];
        if ($lang !== null) {
            Guard::courseLang($course, $lang);
        }
        $translated = $lang !== null && $lang !== $default;
        $warnings = [];

        $cols = [];      // column => value (base row)
        $i18n = [];      // field => value (non-default language)
        foreach ($fields as $k => $_) {
            switch ($k) {
                case 'name':
                    $val = Patch::text($fields, 'name', 200, !$translated);
                    if ($translated) {
                        $i18n['name'] = $val;
                    } else {
                        $cols['course_name'] = $val;
                    }
                    break;
                case 'summary':
                    $val = Patch::text($fields, 'summary', 500);
                    if ($translated) {
                        $i18n['summary'] = $val;
                    } else {
                        $cols['course_summary'] = $val;
                    }
                    break;
                case 'description_html':
                    $p = MediaRefs::purify($db, Patch::html($fields, 'description_html'));
                    $warnings = array_merge($warnings, $p['warnings']);
                    if ($translated) {
                        $i18n['description_html'] = $p['html'];
                    } else {
                        $cols['course_description_html'] = $p['html'];
                    }
                    break;
                case 'attestation_text':
                    $val = Patch::text($fields, 'attestation_text', 5000);
                    if ($translated) {
                        $i18n['attestation_text'] = $val;
                    } else {
                        $cols['course_attestation_text'] = $val;
                    }
                    break;
                case 'code':
                    $cols['course_code'] = Patch::text($fields, 'code', 40);
                    break;
                case 'category_id':
                    $id = Patch::id($fields, 'category_id');
                    if ($id !== null && !Patch::same($course['course_category_id'], $id)) {
                        CategoryService::requireLive($db, $id);
                    }
                    $cols['course_category_id'] = $id;
                    break;
                case 'cover_media_id':
                    $id = Patch::id($fields, 'cover_media_id');
                    if ($id !== null) {
                        MediaRefs::require($db, $id, 'cover', 'cover_media_id');
                    }
                    $cols['course_cover_media_id'] = $id;
                    break;
                case 'color':
                    $cols['course_color'] = Patch::color($fields, 'color');
                    break;
                case 'regulation_ref':
                    $cols['course_regulation_ref'] = Patch::text($fields, 'regulation_ref', 100);
                    break;
                case 'responsible_user_id':
                    $id = Patch::id($fields, 'responsible_user_id');
                    if ($id !== null) {
                        UserDirectory::requireActive($db, $id);
                    }
                    $cols['course_responsible_user_id'] = $id;
                    break;
                case 'est_minutes':
                    $cols['course_est_minutes'] = Patch::int($fields, 'est_minutes', 1, 6000);
                    break;
                case 'validity_months':
                    $cols['course_validity_months'] = Patch::int($fields, 'validity_months', 1, 600);
                    break;
                case 'renewal_lead_days':
                    $cols['course_renewal_lead_days'] = Patch::int($fields, 'renewal_lead_days', 0, 365, false);
                    break;
                case 'component_window_days':
                    $cols['course_component_window_days'] = Patch::int($fields, 'component_window_days', 1, 3650, false);
                    break;
                case 'eval_checklist':
                    $cols['course_eval_checklist'] = Patch::text($fields, 'eval_checklist', 10000);
                    break;
                case 'sequential': case 'requires_signature': case 'is_qualification': case 'needs_online':
                case 'needs_session': case 'needs_practical': case 'external_only': case 'allow_trainer_attest':
                    $cols['course_' . $k] = Patch::bool($fields, $k) ? 1 : 0;
                    break;
                default:
                    throw ApiException::validation([$k => 'This field cannot be changed here.']);
            }
        }

        Db::tx($db, function () use ($db, $courseId, $version, $cols, $i18n, $lang): void {
            $current = Guard::writableCourse($db, $courseId, true);
            if ((int) $current['course_version'] !== $version) {
                throw ApiException::conflict($this->courseShape($current, $this->autoMinutes([$courseId])[$courseId] ?? 0), 'These settings were changed in another tab.');
            }
            $changes = [];
            foreach ($cols as $col => $val) {
                if (!Patch::same($current[$col], $val)) {
                    $changes[$col] = $val;
                }
            }
            $i18nChanged = [];
            $svc = new I18nService($this->c);
            foreach ($i18n as $field => $val) {
                if ($svc->set('course', $courseId, (string) $lang, $field, $val)) {
                    $i18nChanged[] = $field;
                }
            }
            if ($changes === [] && $i18nChanged === []) {
                return;   // no-op patch
            }
            $sets = array_map(static fn($c) => "$c = ?", array_keys($changes));
            $sets[] = 'course_version = course_version + 1';
            Db::exec($db, 'UPDATE training_courses SET ' . implode(', ', $sets) . ' WHERE course_id = ?', str_repeat('s', count($changes)) . 'i', array_merge(array_values($changes), [$courseId]));

            $revisionRelevant = $i18nChanged !== [] || array_diff(array_keys($changes), array_map(static fn($f) => 'course_' . $f, self::NOT_REVISION)) !== [];
            if ($revisionRelevant) {
                CourseTouch::touch($db, $courseId);
            }
        });

        $out = $this->courseObject($courseId);
        return ['course' => $out, 'version' => $out['version'], 'warnings' => $warnings];
    }

    /**
     * Offered languages, required languages and (optionally) a new default in one call
     * (course_set_languages). The default is always offered and always required.
     *
     * @param list<string> $languages
     * @param list<string> $required
     */
    public function setLanguages(int $courseId, array $languages, array $required, ?string $default): array
    {
        $db = $this->c->db;
        $course = Guard::writableCourse($db, $courseId);
        if ($default !== null && $default !== $course['course_default_language']) {
            if (!in_array($default, $languages, true)) {
                $languages[] = $default;
            }
        }
        $default ??= (string) $course['course_default_language'];
        $offered = [];
        foreach ($this->c->settings->languages as $l) {
            if ($l === $default || in_array($l, $languages, true)) {
                $offered[] = $l;
            }
        }
        foreach ($languages as $l) {
            if (!in_array($l, $this->c->settings->languages, true)) {
                throw ApiException::validation(['languages' => 'That language is not offered. An administrator can add it in Admin › Training.']);
            }
        }
        Db::tx($db, function () use ($db, $courseId, $offered, $required, $default, $course): void {
            if ($default !== $course['course_default_language']) {
                $this->lockLanguageChildren($courseId);   // entity rows before the course row (spec §0)
            }
            $current = Guard::writableCourse($db, $courseId, true);
            $csv = implode(',', array_merge([$default], array_values(array_diff($offered, [$default]))));
            if ($current['course_languages'] !== $csv) {
                Db::exec($db, 'UPDATE training_courses SET course_languages = ? WHERE course_id = ?', 'si', [$csv, $courseId]);
                CourseTouch::touch($db, $courseId);
            }
            if ($default !== $current['course_default_language']) {
                $this->setDefaultLanguage($courseId, $default);
            }
            $this->setRequiredLanguages($courseId, $required);
        });
        return $this->courseObject($courseId);
    }

    /**
     * Makes $lang the course default. The base columns always hold the default language, so the
     * course and section texts (and quiz intros) swap between the base columns and
     * training_i18n. Lesson variants and question texts are symmetric and need no swap. The new
     * default must already have a name.
     */
    public function setDefaultLanguage(int $courseId, string $lang): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $courseId, $lang): void {
            // The swap rewrites section titles and quiz intros: lock those entity rows first,
            // then the course row (spec §0 lock order, same as section_update / quiz_update).
            $this->lockLanguageChildren($courseId);
            $course = Guard::writableCourse($db, $courseId, true);
            $old = (string) $course['course_default_language'];
            if ($lang === $old) {
                return;
            }
            if (!in_array($lang, Guard::languages($course), true)) {
                throw ApiException::validation(['default_language' => 'Offer the language first, then make it the default.']);
            }
            $i18n = new I18nService($this->c);
            $tr = $i18n->forEntity('course', $courseId)[$lang] ?? [];
            if (trim((string) ($tr['name'] ?? '')) === '') {
                throw ApiException::validation(['default_language' => 'Give the course a name in that language first.']);
            }

            // Course texts.
            $map = ['name' => 'course_name', 'summary' => 'course_summary', 'description_html' => 'course_description_html', 'attestation_text' => 'course_attestation_text'];
            foreach ($map as $field => $col) {
                $i18n->set('course', $courseId, $old, $field, $course[$col]);
                $i18n->set('course', $courseId, $lang, $field, null);
            }
            Db::exec($db, 'UPDATE training_courses SET course_name = ?, course_summary = ?, course_description_html = ?, course_attestation_text = ?,
                    course_default_language = ?, course_version = course_version + 1 WHERE course_id = ?', 'sssssi',
                [(string) $tr['name'], $tr['summary'] ?? null, $tr['description_html'] ?? null, $tr['attestation_text'] ?? null, $lang, $courseId]);

            // Section titles (a section without a translation keeps its current title).
            $sections = Db::all($db, 'SELECT csection_id, csection_title FROM training_course_sections WHERE csection_course_id = ?', 'i', [$courseId]);
            $sectionTr = $i18n->forEntities('section', array_map(static fn($s) => (int) $s['csection_id'], $sections));
            foreach ($sections as $s) {
                $sid = (int) $s['csection_id'];
                $newTitle = $sectionTr[$sid][$lang]['title'] ?? null;
                if ($newTitle === null) {
                    continue;
                }
                $i18n->set('section', $sid, $old, 'title', (string) $s['csection_title']);
                $i18n->set('section', $sid, $lang, 'title', null);
                Db::exec($db, 'UPDATE training_course_sections SET csection_title = ? WHERE csection_id = ?', 'si', [$newTitle, $sid]);
            }

            // Quiz intros of this course's lessons.
            $quizzes = Db::all($db, 'SELECT q.quiz_id, q.quiz_intro FROM training_quizzes q JOIN training_lessons l ON l.lesson_id = q.quiz_lesson_id WHERE l.lesson_course_id = ?', 'i', [$courseId]);
            $quizTr = $i18n->forEntities('quiz', array_map(static fn($q) => (int) $q['quiz_id'], $quizzes));
            foreach ($quizzes as $q) {
                $qid = (int) $q['quiz_id'];
                $newIntro = $quizTr[$qid][$lang]['intro'] ?? null;
                $i18n->set('quiz', $qid, $old, 'intro', $q['quiz_intro']);
                $i18n->set('quiz', $qid, $lang, 'intro', null);
                Db::exec($db, 'UPDATE training_quizzes SET quiz_intro = ? WHERE quiz_id = ?', 'si', [$newIntro, $qid]);
            }

            $required = array_values(array_unique(array_merge([$lang], Guard::requiredLanguages($course))));
            Db::exec($db, 'UPDATE training_courses SET course_required_languages = ? WHERE course_id = ?', 'si', [implode(',', $required), $courseId]);
            $offered = array_values(array_unique(array_merge([$lang], Guard::languages($course))));
            Db::exec($db, 'UPDATE training_courses SET course_languages = ? WHERE course_id = ?', 'si', [implode(',', $offered), $courseId]);
            CourseTouch::touch($db, $courseId);
        });
    }

    /** "Spanish required for this course": the languages that must be complete to publish. Always includes the default. */
    public function setRequiredLanguages(int $courseId, array $langs): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $courseId, $langs): void {
            $course = Guard::writableCourse($db, $courseId, true);
            $offered = Guard::languages($course);
            $out = [(string) $course['course_default_language']];
            foreach ($langs as $l) {
                if (!is_string($l) || !in_array($l, $offered, true)) {
                    throw ApiException::validation(['required' => 'A required language must also be offered.']);
                }
                if (!in_array($l, $out, true)) {
                    $out[] = $l;
                }
            }
            $csv = implode(',', $out);
            if ($csv !== $course['course_required_languages']) {
                // Not revision content: it decides which languages may publish, checked at publish.
                Db::exec($db, 'UPDATE training_courses SET course_required_languages = ? WHERE course_id = ?', 'si', [$csv, $courseId]);
            }
        });
    }

    /** Replaces the course's tags. Live configuration: no version bump, no touch. */
    public function setTags(int $courseId, array $tagNames): array
    {
        $db = $this->c->db;
        Guard::writableCourse($db, $courseId);
        $names = Patch::tagNames($tagNames);
        return Db::tx($db, function () use ($courseId, $names): array {
            Guard::writableCourse($this->c->db, $courseId, true);
            return (new TagService($this->c))->setFor('course', $courseId, $names);
        });
    }

    // =========================================================================================
    // Archive / restore / delete
    // =========================================================================================

    public function archive(int $courseId, ?string $reason): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $courseId, $reason): void {
            $course = Guard::course($db, $courseId, true);
            if ($course['course_archived_at'] !== null) {
                return;
            }
            Db::exec($db, 'UPDATE training_courses SET course_archived_at = NOW(), course_archived_by = ? WHERE course_id = ?', 'ii', [$this->c->userId, $courseId]);
            Ledger::append($db, [
                'type' => 'course.archived',
                'actor_user_id' => $this->c->userId,
                'course_id' => $courseId,
                'entity_type' => 'course',
                'entity_id' => $courseId,
                'payload' => ['course_uid' => (string) $course['course_uid'], 'reason' => $reason],
                'user_agent' => $this->c->userAgent,
            ]);
        });
    }

    public function restore(int $courseId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $courseId): void {
            $course = Guard::course($db, $courseId, true);
            if ($course['course_archived_at'] === null) {
                return;
            }
            Db::exec($db, 'UPDATE training_courses SET course_archived_at = NULL, course_archived_by = NULL WHERE course_id = ?', 'i', [$courseId]);
            Ledger::append($db, [
                'type' => 'course.restored',
                'actor_user_id' => $this->c->userId,
                'course_id' => $courseId,
                'entity_type' => 'course',
                'entity_id' => $courseId,
                'payload' => ['course_uid' => (string) $course['course_uid'], 'reason' => null],
                'user_agent' => $this->c->userAgent,
            ]);
        });
    }

    /**
     * Deletes a never-published draft and everything in it: sections, lessons, variants,
     * resources, translations, tag links, path memberships, prerequisite rows (both directions)
     * and its quizzes and banks (QuizService::purgeCourseDraft). Refused once any revision
     * exists (archive instead), and 422 bank_in_use while another course's quiz draws from one of
     * its banks.
     */
    public function deleteDraft(int $courseId): void
    {
        $db = $this->c->db;
        $course = Guard::writableCourse($db, $courseId);
        if (Db::one($db, 'SELECT revision_id FROM training_revisions WHERE revision_course_id = ? LIMIT 1', 'i', [$courseId]) !== null) {
            throw ApiException::validation(['course_id' => 'This course has been published. Archive it instead.'], 'Published courses can only be archived.');
        }
        $this->assertBanksUnused($courseId);

        Db::tx($db, function () use ($db, $courseId, $course): void {
            // Lock order (spec §0): every entity row this deletes, then the course row - the
            // order lesson_update, section_update, quiz and question edits and bank moves use
            // (they lock their own row, then touch the course).
            // Lessons before sections, like OutlineService and SectionService::delete.
            $lessonIds = array_map(static fn($r) => (int) $r['lesson_id'],
                Db::all($db, 'SELECT lesson_id FROM training_lessons WHERE lesson_course_id = ? ORDER BY lesson_id FOR UPDATE', 'i', [$courseId]));
            Db::all($db, 'SELECT csection_id FROM training_course_sections WHERE csection_course_id = ? ORDER BY csection_id FOR UPDATE', 'i', [$courseId]);
            foreach (array_chunk($lessonIds, 500) as $chunk) {
                Db::all($db, 'SELECT quiz_id FROM training_quizzes WHERE quiz_lesson_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY quiz_id FOR UPDATE',
                    str_repeat('i', count($chunk)), $chunk);
            }
            $bankIds = array_map(static fn($r) => (int) $r['qbank_id'], Db::all($db, 'SELECT qbank_id FROM training_question_banks WHERE qbank_course_id = ? ORDER BY qbank_id FOR UPDATE', 'i', [$courseId]));
            foreach (array_chunk($bankIds, 500) as $chunk) {
                Db::all($db, 'SELECT question_id FROM training_questions WHERE question_bank_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY question_id FOR UPDATE',
                    str_repeat('i', count($chunk)), $chunk);
            }
            Guard::writableCourse($db, $courseId, true);
            if (Db::one($db, 'SELECT revision_id FROM training_revisions WHERE revision_course_id = ? LIMIT 1', 'i', [$courseId]) !== null) {
                throw ApiException::validation(['course_id' => 'This course has been published. Archive it instead.'], 'Published courses can only be archived.');
            }
            (new QuizService($this->c))->purgeCourseDraft($courseId);

            $lessonIds = array_map(static fn($r) => (int) $r['lesson_id'], Db::all($db, 'SELECT lesson_id FROM training_lessons WHERE lesson_course_id = ?', 'i', [$courseId]));
            $sectionIds = array_map(static fn($r) => (int) $r['csection_id'], Db::all($db, 'SELECT csection_id FROM training_course_sections WHERE csection_course_id = ?', 'i', [$courseId]));
            foreach (array_chunk($lessonIds, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $t = str_repeat('i', count($chunk));
                Db::exec($db, "DELETE FROM training_lesson_resources WHERE lres_lesson_id IN ($in)", $t, $chunk);
                Db::exec($db, "DELETE FROM training_lesson_variants WHERE lvar_lesson_id IN ($in)", $t, $chunk);
                Db::exec($db, "DELETE FROM training_tag_links WHERE ttlink_entity = 'lesson' AND ttlink_entity_id IN ($in)", $t, $chunk);
            }
            $i18n = new I18nService($this->c);
            $i18n->deleteFor('section', $sectionIds);
            $i18n->deleteFor('course', [$courseId]);
            Db::exec($db, 'DELETE FROM training_lessons WHERE lesson_course_id = ?', 'i', [$courseId]);
            Db::exec($db, 'DELETE FROM training_course_sections WHERE csection_course_id = ?', 'i', [$courseId]);
            Db::exec($db, "DELETE FROM training_tag_links WHERE ttlink_entity = 'course' AND ttlink_entity_id = ?", 'i', [$courseId]);
            Db::exec($db, 'DELETE FROM training_path_courses WHERE tpcourse_course_id = ?', 'i', [$courseId]);
            Db::exec($db, 'DELETE FROM training_course_prereqs WHERE prereq_course_id = ? OR prereq_requires_course_id = ?', 'ii', [$courseId, $courseId]);
            Db::exec($db, 'DELETE FROM training_courses WHERE course_id = ?', 'i', [$courseId]);
        });
    }

    // =========================================================================================
    // Helpers
    // =========================================================================================

    /**
     * Locks the rows a default-language swap rewrites (sections, then quizzes) before the course
     * row. Lesson rows are only read (not locked), so this never takes lessons after sections -
     * the reverse of OutlineService's order.
     */
    private function lockLanguageChildren(int $courseId): void
    {
        $db = $this->c->db;
        Db::all($db, 'SELECT csection_id FROM training_course_sections WHERE csection_course_id = ? ORDER BY csection_id FOR UPDATE', 'i', [$courseId]);
        $lessonIds = array_map(static fn($r) => (int) $r['lesson_id'], Db::all($db, 'SELECT lesson_id FROM training_lessons WHERE lesson_course_id = ?', 'i', [$courseId]));
        foreach (array_chunk($lessonIds, 500) as $chunk) {
            Db::all($db, 'SELECT quiz_id FROM training_quizzes WHERE quiz_lesson_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY quiz_id FOR UPDATE',
                str_repeat('i', count($chunk)), $chunk);
        }
    }

    /** [{course_id, name}] the course requires. */
    public static function prereqs(\mysqli $db, int $courseId): array
    {
        return array_map(static fn($r) => ['course_id' => (int) $r['course_id'], 'name' => (string) $r['course_name']], Db::all(
            $db,
            'SELECT c.course_id, c.course_name FROM training_course_prereqs p JOIN training_courses c ON c.course_id = p.prereq_requires_course_id
             WHERE p.prereq_course_id = ? ORDER BY c.course_name',
            'i',
            [$courseId]
        ));
    }

    /**
     * Auto time estimate (minutes, rounded up) of each course's live lessons.
     *
     * @param list<int> $courseIds
     * @return array<int, int>
     */
    public function autoMinutes(array $courseIds): array
    {
        $db = $this->c->db;
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
        if ($courseIds === []) {
            return [];
        }
        $out = array_fill_keys($courseIds, 0);
        $seconds = array_fill_keys($courseIds, 0);
        foreach (array_chunk($courseIds, 200) as $chunk) {
            $rows = Db::all($db, 'SELECT l.lesson_id, l.lesson_course_id, c.course_default_language FROM training_lessons l
                JOIN training_courses c ON c.course_id = l.lesson_course_id
                WHERE l.lesson_course_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') AND l.lesson_archived_at IS NULL', str_repeat('i', count($chunk)), $chunk);
            if ($rows === []) {
                continue;
            }
            $facts = LessonFacts::load($db, array_map(static fn($r) => (int) $r['lesson_id'], $rows), [], false);
            foreach ($rows as $r) {
                $seconds[(int) $r['lesson_course_id']] += LessonFacts::duration($facts, (int) $r['lesson_id'], (string) $r['course_default_language'])['effective'];
            }
        }
        foreach ($seconds as $cid => $s) {
            $out[$cid] = (int) ceil($s / 60);
        }
        return $out;
    }

    private function courseShape(array $c, int $autoMinutes): array
    {
        $db = $this->c->db;
        $id = (int) $c['course_id'];
        $category = null;
        if ($c['course_category_id'] !== null) {
            $cat = Db::one($db, 'SELECT tcat_id, tcat_name, tcat_color, tcat_icon, tcat_sort, tcat_archived_at FROM training_categories WHERE tcat_id = ?', 'i', [(int) $c['course_category_id']]);
            $category = $cat === null ? null : CategoryService::shape($cat);
        }
        $rev = null;
        if ($c['course_current_revision_id'] !== null) {
            $r = Db::one($db, 'SELECT r.revision_id, r.revision_number, r.revision_published_at_utc, r.revision_sha256, u.user_name
                FROM training_revisions r LEFT JOIN users u ON u.user_id = r.revision_published_by WHERE r.revision_id = ?', 'i', [(int) $c['course_current_revision_id']]);
            if ($r !== null) {
                $rev = [
                    'id' => (int) $r['revision_id'],
                    'number' => (int) $r['revision_number'],
                    'published_at' => Clock::toIso($r['revision_published_at_utc'], true),
                    'published_at_utc' => $r['revision_published_at_utc'],
                    'published_by_name' => $r['user_name'],
                    'sha12' => substr((string) $r['revision_sha256'], 0, 12),
                ];
            }
        }
        $responsibleName = $c['course_responsible_user_id'] !== null
            ? (UserDirectory::names($db, [(int) $c['course_responsible_user_id']])[(int) $c['course_responsible_user_id']] ?? null) : null;
        $i18n = (new I18nService($this->c))->forEntity('course', $id);
        $override = $c['course_est_minutes'] === null ? null : (int) $c['course_est_minutes'];
        $archived = $c['course_archived_at'] !== null;

        return [
            'id' => $id,
            'uid' => (string) $c['course_uid'],
            'kind' => (string) $c['course_kind'],
            'code' => $c['course_code'],
            'name' => (string) $c['course_name'],
            'summary' => $c['course_summary'],
            'description_html' => $c['course_description_html'],
            'category_id' => $c['course_category_id'] === null ? null : (int) $c['course_category_id'],
            'category' => $category,
            'cover_media_id' => $c['course_cover_media_id'] === null ? null : (int) $c['course_cover_media_id'],
            'cover_url' => $c['course_cover_media_id'] === null ? null : MediaStore::url((int) $c['course_cover_media_id']),
            'color' => $c['course_color'],
            'default_language' => (string) $c['course_default_language'],
            'languages' => Guard::languages($c),
            'required_languages' => Guard::requiredLanguages($c),
            'regulation_ref' => $c['course_regulation_ref'],
            'responsible_user_id' => $c['course_responsible_user_id'] === null ? null : (int) $c['course_responsible_user_id'],
            'responsible_name' => $responsibleName,
            'sequential' => (int) $c['course_sequential'] === 1,
            'est_minutes' => $override,
            'est_minutes_auto' => $autoMinutes,
            'est_minutes_effective' => $override ?? $autoMinutes,
            'validity_months' => $c['course_validity_months'] === null ? null : (int) $c['course_validity_months'],
            'renewal_lead_days' => (int) $c['course_renewal_lead_days'],
            'requires_signature' => (int) $c['course_requires_signature'] === 1,
            'attestation_text' => $c['course_attestation_text'],
            'is_qualification' => (int) $c['course_is_qualification'] === 1,
            'needs_online' => (int) $c['course_needs_online'] === 1,
            'needs_session' => (int) $c['course_needs_session'] === 1,
            'needs_practical' => (int) $c['course_needs_practical'] === 1,
            'external_only' => (int) $c['course_external_only'] === 1,
            'component_window_days' => (int) $c['course_component_window_days'],
            'allow_trainer_attest' => (int) $c['course_allow_trainer_attest'] === 1,
            'eval_checklist' => $c['course_eval_checklist'],
            'template_key' => $c['course_template_key'],
            'version' => (int) $c['course_version'],
            'status' => $archived ? 'archived' : ($rev !== null ? 'published' : 'draft'),
            'has_changes' => self::hasChanges($c['course_draft_updated_at_utc'], $rev['published_at_utc'] ?? null, $rev !== null),
            'archived' => $archived,
            'archived_at' => Clock::toIso($c['course_archived_at'], false),
            'current_revision' => $rev === null ? null : array_diff_key($rev, ['published_at_utc' => 1]),
            'draft_updated_at' => Clock::toIso($c['course_draft_updated_at_utc'], true),
            'created_at' => Clock::toIso($c['course_created_at'], false),
            'updated_at' => Clock::toIso($c['course_updated_at'] ?? $c['course_created_at'], false),
            'i18n' => $i18n === [] ? new \stdClass() : $i18n,
        ];
    }

    /** The cheap "unpublished changes" signal (the nav badge uses the same rule). */
    private static function hasChanges(?string $draftUtc, ?string $publishedUtc, bool $hasRevision): bool
    {
        if (!$hasRevision) {
            return $draftUtc !== null;
        }
        return $draftUtc !== null && $publishedUtc !== null && strcmp($draftUtc, $publishedUtc) > 0;
    }

    private static function summaryColumns(bool $reader): string
    {
        $cols = 'c.course_id, c.course_uid, c.course_kind, c.course_code, c.course_name, c.course_summary, c.course_category_id,
            c.course_cover_media_id, c.course_color, c.course_default_language, c.course_languages, c.course_required_languages,
            c.course_responsible_user_id, c.course_est_minutes, c.course_current_revision_id, c.course_draft_updated_at_utc,
            c.course_created_at, c.course_updated_at, c.course_archived_at,
            r.revision_number, r.revision_published_at_utc, r.revision_languages,
            (SELECT COUNT(*) FROM training_lessons l WHERE l.lesson_course_id = c.course_id AND l.lesson_archived_at IS NULL) AS lessons_count';
        if ($reader) {
            // Level 1 sees the PUBLISHED version's text and shape, never the draft's.
            $cols .= ",
            " . self::REV_NAME_SQL . " AS rev_name,
            " . self::REV_SUMMARY_SQL . " AS rev_summary,
            " . self::REV_CODE_SQL . " AS rev_code,
            JSON_VALUE(r.revision_json, '$.course.est_minutes') AS rev_est_minutes,
            JSON_VALUE(r.revision_json, '$.course.cover_media_id') AS rev_cover_media_id,
            JSON_VALUE(r.revision_json, '$.course.color') AS rev_color,
            JSON_LENGTH(r.revision_json, '$.lessons') AS rev_lessons,
            JSON_LENGTH(JSON_EXTRACT(r.revision_json, '$.lessons[*].quiz.uid')) AS rev_quizzes,
            JSON_SEARCH(r.revision_json, 'one', 'exam', NULL, '$.lessons[*].quiz.role') IS NOT NULL AS rev_has_exam";
        }
        return $cols;
    }

    /** @return list<array> CourseSummary rows */
    private function summaries(array $rows, bool $reader): array
    {
        $db = $this->c->db;
        $ids = array_map(static fn($r) => (int) $r['course_id'], $rows);
        if ($ids === []) {
            return [];
        }
        $tags = TagService::forEntities($db, 'course', $ids);
        $cats = [];
        foreach ((new CategoryService($this->c))->list(true) as $cat) {
            $cats[$cat['id']] = ['id' => $cat['id'], 'name' => $cat['name'], 'color' => $cat['color'], 'icon' => $cat['icon']];
        }
        $quiz = [];
        $auto = [];
        if (!$reader) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $qrows = Db::all($db, "SELECT l.lesson_course_id, COUNT(*) AS n, MAX(q.quiz_role = 'exam') AS has_exam FROM training_quizzes q
                    JOIN training_lessons l ON l.lesson_id = q.quiz_lesson_id
                    WHERE l.lesson_archived_at IS NULL AND l.lesson_course_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
                    GROUP BY l.lesson_course_id', str_repeat('i', count($chunk)), $chunk);
                foreach ($qrows as $q) {
                    $quiz[(int) $q['lesson_course_id']] = ['n' => (int) $q['n'], 'exam' => (int) $q['has_exam'] === 1];
                }
            }
            $auto = $this->autoMinutes($ids);
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['course_id'];
            $hasRev = $r['course_current_revision_id'] !== null && $r['revision_number'] !== null;
            $archived = $r['course_archived_at'] !== null;
            if ($reader) {
                $name = (string) ($r['rev_name'] ?? $r['course_name']);
                $summary = $r['rev_summary'] ?? null;
                $coverId = $r['rev_cover_media_id'] !== null ? (int) $r['rev_cover_media_id'] : null;
                $color = $r['rev_color'] ?? null;
                $lessonsCount = (int) ($r['rev_lessons'] ?? 0);
                $quizCount = (int) ($r['rev_quizzes'] ?? 0);
                $hasExam = (int) ($r['rev_has_exam'] ?? 0) === 1;
                $est = (int) ($r['rev_est_minutes'] ?? 0);
                $languages = array_values(array_filter(explode(',', (string) $r['revision_languages'])));
                $code = $r['rev_code'] ?? null;
                $updatedAt = Clock::toIso($r['revision_published_at_utc'], true);   // the version's date, not the draft's
            } else {
                $name = (string) $r['course_name'];
                $summary = $r['course_summary'];
                $coverId = $r['course_cover_media_id'] !== null ? (int) $r['course_cover_media_id'] : null;
                $color = $r['course_color'];
                $lessonsCount = (int) $r['lessons_count'];
                $quizCount = $quiz[$id]['n'] ?? 0;
                $hasExam = $quiz[$id]['exam'] ?? false;
                $est = $r['course_est_minutes'] !== null ? (int) $r['course_est_minutes'] : ($auto[$id] ?? 0);
                $languages = Guard::languages($r);
                $code = $r['course_code'];
                $updatedAt = Clock::toIso($r['course_updated_at'] ?? $r['course_created_at'], false);
            }
            $out[] = [
                'id' => $id,
                'uid' => (string) $r['course_uid'],
                'kind' => (string) $r['course_kind'],
                'code' => $code,
                'name' => $name,
                'summary' => $summary,
                'category' => $r['course_category_id'] !== null ? ($cats[(int) $r['course_category_id']] ?? null) : null,
                'tags' => $tags[$id] ?? [],
                'cover_url' => $coverId === null ? null : MediaStore::url($coverId),
                'color' => $color,
                'status' => $archived ? 'archived' : ($hasRev ? 'published' : 'draft'),
                'has_changes' => $reader ? false : self::hasChanges($r['course_draft_updated_at_utc'], $r['revision_published_at_utc'], $hasRev),
                'current_revision_number' => $hasRev ? (int) $r['revision_number'] : null,
                'lessons_count' => $lessonsCount,
                'quiz_count' => $quizCount,
                'has_exam' => $hasExam,
                'est_minutes' => $est,
                'languages' => $languages,
                'required_languages' => $reader ? $languages : Guard::requiredLanguages($r),
                'responsible_user_id' => $r['course_responsible_user_id'] === null ? null : (int) $r['course_responsible_user_id'],
                'updated_at' => $updatedAt,
                'counts' => null,
            ];
        }
        // Phase 2 (S15): required / current / overdue pairs per course over the caller's fail-closed
        // people scope. A course nobody is required to take keeps counts null (the card shows no slot);
        // any failure leaves every row null.
        if ($out !== [] && class_exists(\ITFlow\Training\Compliance\ComplianceService::class)) {
            try {
                $counts = (new \ITFlow\Training\Compliance\ComplianceService($this->c, \ITFlow\Training\People\Scope::forCtx($this->c)))
                    ->courseCounts(array_column($out, 'id'));
                foreach ($out as $i => $row) {
                    $n = $counts[$row['id']] ?? null;
                    if ($n !== null && ($n['required'] > 0 || $n['overdue'] > 0)) {
                        $out[$i]['counts'] = ['required' => (int) $n['required'], 'current' => (int) $n['current'], 'overdue' => (int) $n['overdue']];
                    }
                }
            } catch (\Throwable $e) {
                error_log('Training: course counts failed: ' . $e->getMessage());
            }
        }
        return $out;
    }

    /** Counts for the stat strip and filter chips, over every course the caller may see. */
    private function facets(bool $reader): array
    {
        $db = $this->c->db;
        $visible = $reader ? 'c.course_archived_at IS NULL AND c.course_current_revision_id IS NOT NULL' : 'c.course_archived_at IS NULL';
        $s = Db::one($db, "SELECT
                SUM(c.course_current_revision_id IS NOT NULL) AS published,
                SUM(c.course_current_revision_id IS NULL OR c.course_draft_updated_at_utc > r.revision_published_at_utc) AS drafts_changes,
                SUM(c.course_kind = 'document') AS documents,
                SUM(c.course_kind = 'training') AS trainings,
                COUNT(*) AS total
            FROM training_courses c LEFT JOIN training_revisions r ON r.revision_id = c.course_current_revision_id
            WHERE $visible") ?? [];
        $stats = [
            'total' => (int) ($s['total'] ?? 0),
            'published' => (int) ($s['published'] ?? 0),
            'drafts_changes' => $reader ? 0 : (int) ($s['drafts_changes'] ?? 0),
            'documents' => (int) ($s['documents'] ?? 0),
            'trainings' => (int) ($s['trainings'] ?? 0),
        ];
        if (!$reader) {
            $stats['archived'] = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_courses WHERE course_archived_at IS NOT NULL')['n'] ?? 0);
            $stats['questions'] = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_questions q JOIN training_question_banks b ON b.qbank_id = q.question_bank_id
                WHERE q.question_archived_at IS NULL AND b.qbank_archived_at IS NULL')['n'] ?? 0);
        }
        $categories = array_map(static fn($r) => [
            'id' => (int) $r['tcat_id'], 'name' => (string) $r['tcat_name'], 'color' => (string) $r['tcat_color'], 'icon' => (string) $r['tcat_icon'], 'count' => (int) $r['n'],
        ], Db::all($db, "SELECT t.tcat_id, t.tcat_name, t.tcat_color, t.tcat_icon, COUNT(c.course_id) AS n
            FROM training_categories t LEFT JOIN training_courses c ON c.course_category_id = t.tcat_id AND $visible
            WHERE t.tcat_archived_at IS NULL GROUP BY t.tcat_id, t.tcat_name, t.tcat_color, t.tcat_icon, t.tcat_sort ORDER BY t.tcat_sort, t.tcat_name"));
        $tags = array_map(static fn($r) => [
            'id' => (int) $r['ttag_id'], 'name' => (string) $r['ttag_name'], 'color' => $r['ttag_color'], 'count' => (int) $r['n'],
        ], Db::all($db, "SELECT t.ttag_id, t.ttag_name, t.ttag_color, COUNT(c.course_id) AS n
            FROM training_tags t
            JOIN training_tag_links l ON l.ttlink_tag_id = t.ttag_id AND l.ttlink_entity = 'course'
            JOIN training_courses c ON c.course_id = l.ttlink_entity_id AND $visible
            WHERE t.ttag_archived_at IS NULL GROUP BY t.ttag_id, t.ttag_name, t.ttag_color ORDER BY t.ttag_name"));
        return ['stats' => $stats, 'categories' => $categories, 'tags' => $tags];
    }

    /** @return array{0:string, 1:list<string>} [default, offered languages in settings order] */
    private function normaliseLanguages(array $languages): array
    {
        $allowed = $this->c->settings->languages;
        foreach ($languages as $l) {
            if (!is_string($l) || !in_array($l, $allowed, true)) {
                throw ApiException::validation(['languages' => 'That language is not offered. An administrator can add it in Admin › Training.']);
            }
        }
        $langs = array_values(array_filter($allowed, static fn($l) => in_array($l, $languages, true)));
        if ($langs === []) {
            $langs = [$allowed[0]];
        }
        $default = in_array('en', $langs, true) ? 'en' : $langs[0];
        return [$default, array_values(array_unique(array_merge([$default], $langs)))];
    }

    /**
     * 422 bank_in_use when a quiz rule of ANOTHER course draws from one of this course's banks:
     * directly, or through an ancestor bank with "include sub-banks" on.
     */
    private function assertBanksUnused(int $courseId): void
    {
        $db = $this->c->db;
        $banks = Db::all($db, 'SELECT qbank_id, qbank_parent_id, qbank_course_id, qbank_quiz_lesson_id FROM training_question_banks');
        if ($banks === []) {
            return;
        }
        $lessonIds = array_map(static fn($r) => (int) $r['lesson_id'], Db::all($db, 'SELECT lesson_id FROM training_lessons WHERE lesson_course_id = ?', 'i', [$courseId]));
        $parent = [];
        $children = [];
        $own = [];
        foreach ($banks as $b) {
            $id = (int) $b['qbank_id'];
            $parent[$id] = $b['qbank_parent_id'] === null ? null : (int) $b['qbank_parent_id'];
            $children[(int) ($b['qbank_parent_id'] ?? 0)][] = $id;
            if (($b['qbank_course_id'] !== null && (int) $b['qbank_course_id'] === $courseId)
                || ($b['qbank_quiz_lesson_id'] !== null && in_array((int) $b['qbank_quiz_lesson_id'], $lessonIds, true))) {
                $own[$id] = true;
            }
        }
        if ($own === []) {
            return;
        }
        // Everything under an owned bank goes with it.
        $stack = array_keys($own);
        while ($stack !== []) {
            $id = array_pop($stack);
            foreach ($children[$id] ?? [] as $child) {
                if (!isset($own[$child])) {
                    $own[$child] = true;
                    $stack[] = $child;
                }
            }
        }
        // Ancestors whose "include sub-banks" rules reach into an owned bank.
        $ancestors = [];
        foreach (array_keys($own) as $id) {
            $p = $parent[$id] ?? null;
            $guard = 0;
            while ($p !== null && !isset($own[$p]) && $guard++ < 64) {
                $ancestors[$p] = true;
                $p = $parent[$p] ?? null;
            }
        }
        $rules = Db::all($db, 'SELECT r.qrule_bank_id, r.qrule_include_descendants, c.course_name FROM training_quiz_rules r
            JOIN training_quizzes q ON q.quiz_id = r.qrule_quiz_id
            JOIN training_lessons l ON l.lesson_id = q.quiz_lesson_id
            JOIN training_courses c ON c.course_id = l.lesson_course_id
            WHERE l.lesson_course_id <> ?', 'i', [$courseId]);
        foreach ($rules as $r) {
            $bank = (int) $r['qrule_bank_id'];
            if (isset($own[$bank]) || (isset($ancestors[$bank]) && (int) $r['qrule_include_descendants'] === 1)) {
                throw new ApiException(422, 'bank_in_use', 'A quiz in "' . $r['course_name'] . '" draws questions from this course. Remove that rule first.');
            }
        }
    }
}
