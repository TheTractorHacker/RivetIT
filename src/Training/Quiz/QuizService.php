<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Authoring\CourseTouch;
use ITFlow\Training\Authoring\I18nService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Uid;

/**
 * Quizzes, their settings and draw rules (spec §3.5, §5.5).
 *
 * One quiz per lesson. Roles:
 *   standalone  a Quiz-type lesson; must_pass is the author's choice
 *   exam        a Quiz-type lesson that is the course's final exam; must_pass is always on;
 *               at most one per course
 *   check       a knowledge check attached to a content lesson (article/document/video/image);
 *               defaults: must_pass off, unlimited attempts (max_attempts 0), missed-questions
 *               feedback, no review screen
 *
 * Every quiz has an auto bank ("written for this quiz", BankService::ensureQuizBank) and, when
 * created, one implicit rule drawing all of that bank. Detaching deletes the quiz and its rules
 * and archives the auto bank; re-attaching unarchives the same bank, so the questions return.
 *
 * quiz_version guards the settings fields; rules are separate rows and never bump it. Anything
 * that changes the revision JSON touches the course.
 */
final class QuizService
{
    public const ROLES = ['standalone', 'exam', 'check'];
    public const FEEDBACK_MODES = ['score_only', 'missed_questions', 'answers_after_pass'];
    public const CONTENT_TYPES = ['article', 'document', 'video', 'image'];
    public const PASS_MIN = 50;
    public const PASS_MAX = 100;
    public const ATTEMPTS_MAX = 10;
    public const TIME_MIN_S = 60;
    public const TIME_MAX_S = 14400;
    public const INTRO_MAX = 1000;
    public const RULE_COUNT_MAX = 500;
    public const RULES_MAX = 20;

    private const QUIZ_COLS = 'quiz_id, quiz_uid, quiz_lesson_id, quiz_role, quiz_pass_pct, quiz_max_attempts, quiz_time_limit_s,
        quiz_shuffle_questions, quiz_shuffle_options, quiz_feedback_mode, quiz_show_review, quiz_must_pass, quiz_intro, quiz_version';

    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * The lesson's quiz, created when missing (with its auto bank and implicit rule). An
     * existing quiz is returned as is, or switched to $role (with that role's defaults) when
     * $role differs and fits the lesson type.
     */
    public function ensureForLesson(int $lessonId, string $role): array
    {
        if (!in_array($role, self::ROLES, true)) {
            throw ApiException::validation(['role' => 'Not a valid choice.']);
        }
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $lessonId, $role): void {
            [$lesson, $course] = Guard::writableLesson($db, $lessonId);
            self::assertRoleFits((string) $lesson['lesson_type'], $role);
            $quiz = Db::one($db, 'SELECT ' . self::QUIZ_COLS . ' FROM training_quizzes WHERE quiz_lesson_id = ? FOR UPDATE', 'i', [$lessonId]);
            if ($quiz !== null) {
                if ($quiz['quiz_role'] !== $role) {
                    if ($role === 'exam') {
                        $this->assertNoOtherExam($db, (int) $course['course_id'], $lessonId, false);
                    }
                    $d = $this->roleDefaults($role);
                    Db::exec($db, 'UPDATE training_quizzes SET quiz_role = ?, quiz_must_pass = ?, quiz_max_attempts = ?, quiz_show_review = ?,
                        quiz_feedback_mode = ?, quiz_version = quiz_version + 1 WHERE quiz_id = ?',
                        'siiisi', [$role, $d['must_pass'], $d['max_attempts'], $d['show_review'], $d['feedback_mode'], (int) $quiz['quiz_id']]);
                    CourseTouch::touch($db, (int) $course['course_id']);
                }
                return;
            }
            if ($role === 'exam') {
                $this->assertNoOtherExam($db, (int) $course['course_id'], $lessonId, false);
            }
            $bankId = (new BankService($this->c))->ensureQuizBank($lessonId);
            $d = $this->roleDefaults($role);
            $quizId = Db::insert($db, 'INSERT INTO training_quizzes (quiz_uid, quiz_lesson_id, quiz_role, quiz_pass_pct, quiz_max_attempts,
                    quiz_shuffle_questions, quiz_shuffle_options, quiz_feedback_mode, quiz_show_review, quiz_must_pass)
                    VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?, ?)',
                'sisiisii', [Uid::new('z'), $lessonId, $role, $d['pass_pct'], $d['max_attempts'], $d['feedback_mode'], $d['show_review'], $d['must_pass']]);
            Db::exec($db, 'INSERT INTO training_quiz_rules (qrule_uid, qrule_quiz_id, qrule_bank_id, qrule_include_descendants, qrule_count, qrule_sort)
                VALUES (?, ?, ?, 0, 0, 0)', 'sii', [Uid::new('u'), $quizId, $bankId]);
            CourseTouch::touch($db, (int) $course['course_id']);
        });
        return $this->get($lessonId);
    }

    /** Deletes the lesson's quiz and rules and archives its auto bank (questions are kept). */
    public function detachFromLesson(int $lessonId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $lessonId): void {
            $lesson = Db::one($db, 'SELECT lesson_id, lesson_course_id FROM training_lessons WHERE lesson_id = ?', 'i', [$lessonId]);
            if ($lesson === null) {
                throw ApiException::notFound('That lesson no longer exists.');
            }
            Guard::writableCourse($db, (int) $lesson['lesson_course_id']);
            $quiz = Db::one($db, 'SELECT quiz_id FROM training_quizzes WHERE quiz_lesson_id = ? FOR UPDATE', 'i', [$lessonId]);
            if ($quiz === null) {
                return;
            }
            $quizId = (int) $quiz['quiz_id'];
            Db::exec($db, 'DELETE FROM training_quiz_rules WHERE qrule_quiz_id = ?', 'i', [$quizId]);
            Db::exec($db, "DELETE FROM training_i18n WHERE ti18n_entity = 'quiz' AND ti18n_entity_id = ?", 'i', [$quizId]);
            Db::exec($db, 'DELETE FROM training_quizzes WHERE quiz_id = ?', 'i', [$quizId]);
            Db::exec($db, 'UPDATE training_question_banks SET qbank_archived_at = NOW() WHERE qbank_quiz_lesson_id = ? AND qbank_archived_at IS NULL',
                'i', [$lessonId]);
            CourseTouch::touch($db, (int) $lesson['lesson_course_id']);
        });
    }

    /** The §6.1 Quiz shape, or null when the lesson has no quiz. */
    public function get(int $lessonId): ?array
    {
        $db = $this->c->db;
        $quiz = Db::one($db, 'SELECT ' . self::QUIZ_COLS . ' FROM training_quizzes WHERE quiz_lesson_id = ?', 'i', [$lessonId]);
        if ($quiz === null) {
            return null;
        }
        $lesson = Db::one($db, 'SELECT ' . Guard::LESSON_COLS . ' FROM training_lessons WHERE lesson_id = ?', 'i', [$lessonId]);
        $course = Guard::course($db, (int) $lesson['lesson_course_id']);
        $langs = Guard::languages($course);
        $tree = BankTree::load($db);

        $ownBank = Db::one($db, 'SELECT qbank_id FROM training_question_banks WHERE qbank_quiz_lesson_id = ? AND qbank_archived_at IS NULL
            ORDER BY qbank_id LIMIT 1', 'i', [$lessonId]);
        $ownBankId = $ownBank === null ? null : (int) $ownBank['qbank_id'];

        $rules = [];
        foreach (Db::all($db, 'SELECT qrule_id, qrule_uid, qrule_bank_id, qrule_include_descendants, qrule_count, qrule_sort
                FROM training_quiz_rules WHERE qrule_quiz_id = ? ORDER BY qrule_sort, qrule_id', 'i', [(int) $quiz['quiz_id']]) as $r) {
            $rules[] = $this->ruleShape($r, $tree, $ownBankId);
        }

        $questions = [];
        if ($ownBankId !== null) {
            $qs = QuestionData::load($db, QuestionData::idsForBanks($db, [$ownBankId]));
            $media = MediaRefs::rows($db, QuestionService::mediaIds($qs));
            $svc = new QuestionService($this->c);
            foreach ($qs as $q) {
                $questions[] = $svc->shape($q, ['default' => $langs['default'], 'offered' => $langs['offered']], $media);
            }
        }

        $introI18n = [];
        foreach (Db::all($db, "SELECT ti18n_lang, ti18n_value FROM training_i18n WHERE ti18n_entity = 'quiz' AND ti18n_entity_id = ?
                AND ti18n_field = 'intro' ORDER BY ti18n_lang", 'i', [(int) $quiz['quiz_id']]) as $r) {
            if ($r['ti18n_lang'] !== $langs['default']) {
                $introI18n[(string) $r['ti18n_lang']] = (string) $r['ti18n_value'];
            }
        }

        return [
            'id' => (int) $quiz['quiz_id'],
            'uid' => (string) $quiz['quiz_uid'],
            'lesson_id' => $lessonId,
            'course_id' => (int) $course['course_id'],
            'role' => (string) $quiz['quiz_role'],
            'pass_pct' => (int) $quiz['quiz_pass_pct'],
            'max_attempts' => (int) $quiz['quiz_max_attempts'],
            'time_limit_s' => $quiz['quiz_time_limit_s'] === null ? null : (int) $quiz['quiz_time_limit_s'],
            'shuffle_questions' => (int) $quiz['quiz_shuffle_questions'] === 1,
            'shuffle_options' => (int) $quiz['quiz_shuffle_options'] === 1,
            'feedback_mode' => (string) $quiz['quiz_feedback_mode'],
            'show_review' => (int) $quiz['quiz_show_review'] === 1,
            'must_pass' => (int) $quiz['quiz_must_pass'] === 1,
            'intro' => $quiz['quiz_intro'],
            'intro_i18n' => $introI18n === [] ? new \stdClass() : $introI18n,
            'own_bank_id' => $ownBankId,
            'version' => (int) $quiz['quiz_version'],
            'default_language' => $langs['default'],
            'languages' => $langs['offered'],
            'archived' => $course['course_archived_at'] !== null,
            'rules' => $rules,
            'questions' => $questions,
        ];
    }

    /** Quiz by its own id (lesson id lookup + get). */
    public function getById(int $quizId): array
    {
        $row = Db::one($this->c->db, 'SELECT quiz_lesson_id FROM training_quizzes WHERE quiz_id = ?', 'i', [$quizId]);
        if ($row === null) {
            throw ApiException::notFound('That quiz no longer exists.');
        }
        return $this->get((int) $row['quiz_lesson_id']) ?? throw ApiException::notFound('That quiz no longer exists.');
    }

    /**
     * Allowed fields: role, pass_pct (50..100), max_attempts (0 = unlimited .. 10), time_limit_s
     * (null or 60..14400), shuffle_questions, shuffle_options, feedback_mode, show_review,
     * must_pass, intro (for $lang; the course default language is the base column). The pseudo-field
     * replace_exam=true lets role=exam demote the course's current exam to standalone.
     */
    public function update(int $quizId, int $version, array $fields, ?string $lang): array
    {
        $db = $this->c->db;
        $replaceExam = !empty($fields['replace_exam']);
        unset($fields['replace_exam']);
        $lessonId = Db::tx($db, function () use ($db, $quizId, $version, $fields, $lang, $replaceExam): int {
            $quiz = Db::one($db, 'SELECT ' . self::QUIZ_COLS . ' FROM training_quizzes WHERE quiz_id = ? FOR UPDATE', 'i', [$quizId]);
            if ($quiz === null) {
                throw ApiException::notFound('That quiz no longer exists.');
            }
            $lessonId = (int) $quiz['quiz_lesson_id'];
            [$lesson, $course] = Guard::writableLesson($db, $lessonId);
            if ((int) $quiz['quiz_version'] !== $version) {
                throw ApiException::conflict($this->get($lessonId));
            }
            $langs = Guard::languages($course);
            $lang ??= $langs['default'];
            if (!in_array($lang, $langs['offered'], true)) {
                throw ApiException::validation(['lang' => 'This course is not offered in that language.']);
            }

            $cur = [
                'role' => (string) $quiz['quiz_role'],
                'pass_pct' => (int) $quiz['quiz_pass_pct'],
                'max_attempts' => (int) $quiz['quiz_max_attempts'],
                'time_limit_s' => $quiz['quiz_time_limit_s'] === null ? null : (int) $quiz['quiz_time_limit_s'],
                'shuffle_questions' => (int) $quiz['quiz_shuffle_questions'] === 1,
                'shuffle_options' => (int) $quiz['quiz_shuffle_options'] === 1,
                'feedback_mode' => (string) $quiz['quiz_feedback_mode'],
                'show_review' => (int) $quiz['quiz_show_review'] === 1,
                'must_pass' => (int) $quiz['quiz_must_pass'] === 1,
            ];
            $new = $cur;
            foreach ($fields as $k => $v) {
                switch ($k) {
                    case 'role':
                        if (!is_string($v) || !in_array($v, self::ROLES, true)) {
                            throw ApiException::validation(['role' => 'Not a valid choice.']);
                        }
                        self::assertRoleFits((string) $lesson['lesson_type'], $v);
                        $new['role'] = $v;
                        break;
                    case 'pass_pct':
                        $new['pass_pct'] = self::intIn($v, self::PASS_MIN, self::PASS_MAX, 'pass_pct');
                        break;
                    case 'max_attempts':
                        $new['max_attempts'] = self::intIn($v, 0, self::ATTEMPTS_MAX, 'max_attempts');
                        break;
                    case 'time_limit_s':
                        $new['time_limit_s'] = ($v === null || $v === '' || $v === 0) ? null : self::intIn($v, self::TIME_MIN_S, self::TIME_MAX_S, 'time_limit_s');
                        break;
                    case 'shuffle_questions':
                    case 'shuffle_options':
                    case 'show_review':
                    case 'must_pass':
                        if (!is_bool($v) && !in_array($v, [0, 1], true)) {
                            throw ApiException::validation([$k => 'Must be true or false.']);
                        }
                        $new[$k] = (bool) $v;
                        break;
                    case 'feedback_mode':
                        if (!is_string($v) || !in_array($v, self::FEEDBACK_MODES, true)) {
                            throw ApiException::validation(['feedback_mode' => 'Not a valid choice.']);
                        }
                        $new['feedback_mode'] = $v;
                        break;
                    case 'intro':
                        break; // handled below (per language)
                    default:
                        throw ApiException::validation([$k => 'This field cannot be changed here.']);
                }
            }
            if ($new['role'] !== $cur['role']) {
                $d = $this->roleDefaults($new['role']);
                foreach (['must_pass', 'max_attempts', 'show_review', 'feedback_mode'] as $k) {
                    if (!array_key_exists($k, $fields)) {
                        $new[$k] = $k === 'feedback_mode' ? $d[$k] : ($k === 'max_attempts' ? $d[$k] : (bool) $d[$k]);
                    }
                }
                if ($new['role'] === 'exam') {
                    $this->assertNoOtherExam($db, (int) $course['course_id'], $lessonId, $replaceExam);
                }
            }
            if ($new['role'] === 'exam') {
                $new['must_pass'] = true;
            }

            $changed = false;
            if ($new !== $cur) {
                Db::exec($db, 'UPDATE training_quizzes SET quiz_role = ?, quiz_pass_pct = ?, quiz_max_attempts = ?, quiz_time_limit_s = ?,
                        quiz_shuffle_questions = ?, quiz_shuffle_options = ?, quiz_feedback_mode = ?, quiz_show_review = ?, quiz_must_pass = ?
                        WHERE quiz_id = ?',
                    'siiiiisiii', [$new['role'], $new['pass_pct'], $new['max_attempts'], $new['time_limit_s'], $new['shuffle_questions'] ? 1 : 0,
                        $new['shuffle_options'] ? 1 : 0, $new['feedback_mode'], $new['show_review'] ? 1 : 0, $new['must_pass'] ? 1 : 0, $quizId]);
                $changed = true;
            }
            if (array_key_exists('intro', $fields)) {
                $intro = QuestionService::cleanText($fields['intro'], self::INTRO_MAX, 'intro');
                $intro = ($intro === null || $intro === '') ? null : $intro;
                if ($lang === $langs['default']) {
                    if ($intro !== $quiz['quiz_intro']) {
                        Db::exec($db, 'UPDATE training_quizzes SET quiz_intro = ? WHERE quiz_id = ?', 'si', [$intro, $quizId]);
                        $changed = true;
                    }
                } elseif ((new I18nService($this->c))->set('quiz', $quizId, $lang, 'intro', $intro)) {
                    $changed = true;
                }
            }
            if ($changed) {
                Db::exec($db, 'UPDATE training_quizzes SET quiz_version = quiz_version + 1 WHERE quiz_id = ?', 'i', [$quizId]);
                CourseTouch::touch($db, (int) $course['course_id']);
            }
            return $lessonId;
        });
        return $this->get($lessonId);
    }

    public function ruleAdd(int $quizId, int $bankId, bool $includeDescendants, int $count): array
    {
        $db = $this->c->db;
        $ruleId = Db::tx($db, function () use ($db, $quizId, $bankId, $includeDescendants, $count): int {
            [$quiz, $course] = $this->lockQuiz($db, $quizId);
            $n = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_quiz_rules WHERE qrule_quiz_id = ?', 'i', [$quizId])['n'] ?? 0);
            if ($n >= self::RULES_MAX) {
                throw ApiException::validation(['bank_id' => 'A quiz can draw from at most ' . self::RULES_MAX . ' sources.']);
            }
            $count = self::intIn($count, 0, self::RULE_COUNT_MAX, 'count');
            $this->assertRuleBank($db, $quiz, $bankId, $includeDescendants, null);
            $sort = (int) (Db::one($db, 'SELECT COALESCE(MAX(qrule_sort), -1) + 1 AS s FROM training_quiz_rules WHERE qrule_quiz_id = ?',
                'i', [$quizId])['s'] ?? 0);
            $id = Db::insert($db, 'INSERT INTO training_quiz_rules (qrule_uid, qrule_quiz_id, qrule_bank_id, qrule_include_descendants, qrule_count, qrule_sort)
                VALUES (?, ?, ?, ?, ?, ?)', 'siiiii', [Uid::new('u'), $quizId, $bankId, $includeDescendants ? 1 : 0, $count, $sort]);
            CourseTouch::touch($db, (int) $course['course_id']);
            return $id;
        });
        return $this->ruleById($ruleId);
    }

    /** Allowed fields: bank_id, include_descendants, count. */
    public function ruleUpdate(int $ruleId, array $f): array
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $ruleId, $f): void {
            $rule = $this->lockRule($db, $ruleId);
            [$quiz, $course] = $this->lockQuiz($db, (int) $rule['qrule_quiz_id']);
            $bankId = array_key_exists('bank_id', $f) ? self::intIn($f['bank_id'], 1, PHP_INT_MAX, 'bank_id') : (int) $rule['qrule_bank_id'];
            $desc = array_key_exists('include_descendants', $f) ? (bool) $f['include_descendants'] : (int) $rule['qrule_include_descendants'] === 1;
            $count = array_key_exists('count', $f) ? self::intIn($f['count'], 0, self::RULE_COUNT_MAX, 'count') : (int) $rule['qrule_count'];
            foreach (array_keys($f) as $k) {
                if (!in_array($k, ['bank_id', 'include_descendants', 'count'], true)) {
                    throw ApiException::validation([$k => 'This field cannot be changed here.']);
                }
            }
            if ($bankId === (int) $rule['qrule_bank_id'] && $desc === ((int) $rule['qrule_include_descendants'] === 1) && $count === (int) $rule['qrule_count']) {
                return;
            }
            $this->assertRuleBank($db, $quiz, $bankId, $desc, $ruleId);
            Db::exec($db, 'UPDATE training_quiz_rules SET qrule_bank_id = ?, qrule_include_descendants = ?, qrule_count = ? WHERE qrule_id = ?',
                'iiii', [$bankId, $desc ? 1 : 0, $count, $ruleId]);
            CourseTouch::touch($db, (int) $course['course_id']);
        });
        return $this->ruleById($ruleId);
    }

    public function ruleDelete(int $ruleId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $ruleId): void {
            $rule = $this->lockRule($db, $ruleId);
            [, $course] = $this->lockQuiz($db, (int) $rule['qrule_quiz_id']);
            Db::exec($db, 'DELETE FROM training_quiz_rules WHERE qrule_id = ?', 'i', [$ruleId]);
            CourseTouch::touch($db, (int) $course['course_id']);
        });
    }

    /** $ruleIds must be exactly the quiz's rules, in the new order. */
    public function rulesReorder(int $quizId, array $ruleIds): void
    {
        $db = $this->c->db;
        $ruleIds = array_values(array_map('intval', $ruleIds));
        Db::tx($db, function () use ($db, $quizId, $ruleIds): void {
            [, $course] = $this->lockQuiz($db, $quizId);
            $rows = Db::all($db, 'SELECT qrule_id, qrule_sort FROM training_quiz_rules WHERE qrule_quiz_id = ? FOR UPDATE', 'i', [$quizId]);
            $cur = [];
            foreach ($rows as $r) {
                $cur[(int) $r['qrule_id']] = (int) $r['qrule_sort'];
            }
            $a = $ruleIds;
            sort($a);
            $b = array_keys($cur);
            sort($b);
            if ($a !== $b || count($ruleIds) !== count(array_unique($ruleIds))) {
                throw ApiException::validation(['ids' => 'The list must contain every source of this quiz exactly once.']);
            }
            $changed = false;
            foreach ($ruleIds as $i => $rid) {
                if ($cur[$rid] !== $i) {
                    Db::exec($db, 'UPDATE training_quiz_rules SET qrule_sort = ? WHERE qrule_id = ?', 'ii', [$i, $rid]);
                    $changed = true;
                }
            }
            if ($changed) {
                CourseTouch::touch($db, (int) $course['course_id']);
            }
        });
    }

    /** The §6.1 PoolStats shape. */
    public function poolStats(int $quizId): array
    {
        $db = $this->c->db;
        $quiz = Db::one($db, 'SELECT quiz_id, quiz_lesson_id FROM training_quizzes WHERE quiz_id = ?', 'i', [$quizId]);
        if ($quiz === null) {
            throw ApiException::notFound('That quiz no longer exists.');
        }
        $lesson = Db::one($db, 'SELECT lesson_course_id FROM training_lessons WHERE lesson_id = ?', 'i', [(int) $quiz['quiz_lesson_id']]);
        $langs = Guard::languages(Guard::course($db, (int) $lesson['lesson_course_id']));
        $resolved = PoolResolver::resolve($db, $quizId, $langs['offered']);
        $rules = [];
        foreach ($resolved['rules'] as $r) {
            $available = count($r['pool_ids']);
            $critical = count($r['critical_ids']);
            $rules[] = [
                'rule_id' => $r['id'],
                'available' => $available,
                'critical' => $critical,
                'will_draw' => PoolResolver::willDraw($r['count'], $available, $critical),
                'translated' => $r['translated'] === [] ? new \stdClass() : $r['translated'],
                'issues' => self::ruleIssues($r, $quizId),
            ];
        }
        return [
            'quiz_id' => $quizId,
            'rules' => $rules,
            'overlaps' => $resolved['overlaps'],
            'total_draw' => PoolResolver::totalDraw($resolved),
            'languages_ready' => $resolved['languages_ready'] === [] ? new \stdClass() : $resolved['languages_ready'],
        ];
    }

    /**
     * Summaries for the course builder (CourseDetail.quiz_summaries / LessonSummary.quiz), keyed
     * by lesson id: {id, uid, lesson_id, role, question_count (what a learner is shown), pool_count,
     * translated:{lang:n}, must_pass, pass_pct, max_attempts, version}.
     *
     * @return array<int, array>
     */
    public function summariesForCourse(int $courseId): array
    {
        $db = $this->c->db;
        $langs = Guard::languages(Guard::course($db, $courseId));
        $tree = BankTree::load($db);
        $out = [];
        foreach (Db::all($db, 'SELECT z.quiz_id, z.quiz_uid, z.quiz_lesson_id, z.quiz_role, z.quiz_must_pass, z.quiz_pass_pct,
                z.quiz_max_attempts, z.quiz_version
                FROM training_quizzes z JOIN training_lessons l ON l.lesson_id = z.quiz_lesson_id
                WHERE l.lesson_course_id = ? AND l.lesson_archived_at IS NULL ORDER BY z.quiz_lesson_id', 'i', [$courseId]) as $z) {
            $resolved = PoolResolver::resolve($db, (int) $z['quiz_id'], $langs['offered'], $tree);
            $pool = [];
            $translated = array_fill_keys($langs['offered'], 0);
            foreach ($resolved['rules'] as $r) {
                foreach ($r['pool_ids'] as $qid) {
                    if (isset($pool[$qid])) {
                        continue;
                    }
                    $pool[$qid] = true;
                    foreach ($langs['offered'] as $l) {
                        if (QuestionRules::completeIn($resolved['questions'][$qid], $l)) {
                            $translated[$l]++;
                        }
                    }
                }
            }
            $out[(int) $z['quiz_lesson_id']] = [
                'id' => (int) $z['quiz_id'],
                'uid' => (string) $z['quiz_uid'],
                'lesson_id' => (int) $z['quiz_lesson_id'],
                'role' => (string) $z['quiz_role'],
                'question_count' => PoolResolver::totalDraw($resolved),
                'pool_count' => count($pool),
                'translated' => $translated,
                'must_pass' => (int) $z['quiz_must_pass'] === 1,
                'pass_pct' => (int) $z['quiz_pass_pct'],
                'max_attempts' => (int) $z['quiz_max_attempts'],
                'version' => (int) $z['quiz_version'],
            ];
        }
        return $out;
    }

    /**
     * Removes every quiz-lane row of a draft course: rules, quizzes, quiz i18n, the course's
     * banks (course and quiz banks, archived or not), their questions, texts and options.
     * Refuses with 422 bank_in_use when another course's rule draws from one of its banks.
     * Runs in the caller's transaction (CourseService::deleteDraft).
     */
    public function purgeCourseDraft(int $courseId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $courseId): void {
            $uses = (new BankService($this->c))->usedByOtherCourses($courseId);
            if ($uses !== []) {
                throw new ApiException(422, 'bank_in_use', "Other courses draw questions from this course's banks. Remove those sources first.",
                    [], ['used_by' => $uses]);
            }
            $quizIds = array_map(static fn($r) => (int) $r['quiz_id'], Db::all($db, 'SELECT z.quiz_id FROM training_quizzes z
                JOIN training_lessons l ON l.lesson_id = z.quiz_lesson_id WHERE l.lesson_course_id = ?', 'i', [$courseId]));
            if ($quizIds !== []) {
                [$ph, $t, $p] = InList::ints($quizIds);
                Db::exec($db, "DELETE FROM training_quiz_rules WHERE qrule_quiz_id IN ($ph)", $t, $p);
                Db::exec($db, "DELETE FROM training_i18n WHERE ti18n_entity = 'quiz' AND ti18n_entity_id IN ($ph)", $t, $p);
                Db::exec($db, "DELETE FROM training_quizzes WHERE quiz_id IN ($ph)", $t, $p);
            }
            $bankIds = array_map(static fn($r) => (int) $r['qbank_id'], Db::all($db, 'SELECT qbank_id FROM training_question_banks
                WHERE qbank_course_id = ? OR qbank_quiz_lesson_id IN (SELECT lesson_id FROM training_lessons WHERE lesson_course_id = ?)',
                'ii', [$courseId, $courseId]));
            if ($bankIds === []) {
                return;
            }
            [$bph, $bt, $bp] = InList::ints($bankIds);
            $questionIds = array_map(static fn($r) => (int) $r['question_id'],
                Db::all($db, "SELECT question_id FROM training_questions WHERE question_bank_id IN ($bph)", $bt, $bp));
            foreach (array_chunk($questionIds, 500) as $chunk) {
                [$qph, $qt, $qp] = InList::ints($chunk);
                Db::exec($db, "DELETE ot FROM training_option_texts ot JOIN training_question_options o ON o.option_id = ot.otext_option_id
                    WHERE o.option_question_id IN ($qph)", $qt, $qp);
                Db::exec($db, "DELETE FROM training_question_options WHERE option_question_id IN ($qph)", $qt, $qp);
                Db::exec($db, "DELETE FROM training_question_texts WHERE qtext_question_id IN ($qph)", $qt, $qp);
                Db::exec($db, "DELETE FROM training_questions WHERE question_id IN ($qph)", $qt, $qp);
            }
            Db::exec($db, "DELETE FROM training_question_banks WHERE qbank_id IN ($bph)", $bt, $bp);
        });
    }

    // ------------------------------------------------------------------------------------------

    public function ruleById(int $ruleId): array
    {
        $db = $this->c->db;
        $r = Db::one($db, 'SELECT qrule_id, qrule_uid, qrule_quiz_id, qrule_bank_id, qrule_include_descendants, qrule_count, qrule_sort
            FROM training_quiz_rules WHERE qrule_id = ?', 'i', [$ruleId]);
        if ($r === null) {
            throw ApiException::notFound('That source no longer exists.');
        }
        $quiz = Db::one($db, 'SELECT quiz_lesson_id FROM training_quizzes WHERE quiz_id = ?', 'i', [(int) $r['qrule_quiz_id']]);
        $own = $quiz === null ? null : Db::one($db, 'SELECT qbank_id FROM training_question_banks WHERE qbank_quiz_lesson_id = ?
            AND qbank_archived_at IS NULL ORDER BY qbank_id LIMIT 1', 'i', [(int) $quiz['quiz_lesson_id']]);
        return $this->ruleShape($r, BankTree::load($db), $own === null ? null : (int) $own['qbank_id']);
    }

    /** Issues for one resolved rule (pool stats, publish validator). @return list<array> */
    public static function ruleIssues(array $r, int $quizId): array
    {
        $out = [];
        $available = count($r['pool_ids']);
        $critical = count($r['critical_ids']);
        if (!$r['bank_live']) {
            $out[] = ['code' => 'quiz_pool_short', 'severity' => 'error', 'message' => 'The bank this source draws from was archived.',
                'quiz_id' => $quizId, 'rule_id' => $r['id']];
        } elseif ($available === 0 || ($r['count'] > 0 && $r['count'] > $available)) {
            $out[] = ['code' => 'quiz_pool_short', 'severity' => 'error', 'message' => $available === 0
                ? 'This source has no questions yet.'
                : "This source draws {$r['count']} but only has $available questions.",
                'quiz_id' => $quizId, 'rule_id' => $r['id']];
        }
        if ($r['count'] > 0 && $critical > $r['count']) {
            $out[] = ['code' => 'quiz_critical_exceeds', 'severity' => 'error',
                'message' => "This source has $critical critical questions but draws only {$r['count']}; every critical question is always asked.",
                'quiz_id' => $quizId, 'rule_id' => $r['id']];
        }
        return $out;
    }

    public static function assertRoleFits(string $lessonType, string $role): void
    {
        if ($lessonType === 'quiz' && in_array($role, ['standalone', 'exam'], true)) {
            return;
        }
        if (in_array($lessonType, self::CONTENT_TYPES, true) && $role === 'check') {
            return;
        }
        throw ApiException::validation(['role' => $lessonType === 'acknowledgment'
            ? "An acknowledgment can't have a quiz."
            : "That kind of quiz doesn't fit this lesson."]);
    }

    /** @return array{pass_pct:int, max_attempts:int, must_pass:int, show_review:int, feedback_mode:string} */
    private function roleDefaults(string $role): array
    {
        $pass = max(self::PASS_MIN, min(self::PASS_MAX, $this->c->settings->defaultPassPct));
        $attempts = max(0, min(self::ATTEMPTS_MAX, $this->c->settings->defaultMaxAttempts));
        if ($role === 'check') {
            return ['pass_pct' => $pass, 'max_attempts' => 0, 'must_pass' => 0, 'show_review' => 0, 'feedback_mode' => 'missed_questions'];
        }
        return ['pass_pct' => $pass, 'max_attempts' => $attempts, 'must_pass' => 1, 'show_review' => 1, 'feedback_mode' => 'missed_questions'];
    }

    private function assertNoOtherExam(\mysqli $db, int $courseId, int $lessonId, bool $replace): void
    {
        $other = Db::one($db, "SELECT z.quiz_id, z.quiz_lesson_id FROM training_quizzes z JOIN training_lessons l ON l.lesson_id = z.quiz_lesson_id
            WHERE l.lesson_course_id = ? AND l.lesson_archived_at IS NULL AND z.quiz_role = 'exam' AND z.quiz_lesson_id <> ?
            LIMIT 1 FOR UPDATE", 'ii', [$courseId, $lessonId]);
        if ($other === null) {
            return;
        }
        if (!$replace) {
            throw new ApiException(422, 'validation', 'Another lesson is already the final exam.',
                ['role' => 'Another lesson is already the final exam.'], ['exam_lesson_id' => (int) $other['quiz_lesson_id']]);
        }
        Db::exec($db, "UPDATE training_quizzes SET quiz_role = 'standalone', quiz_version = quiz_version + 1 WHERE quiz_id = ?",
            'i', [(int) $other['quiz_id']]);
    }

    /** @return array{0:array, 1:array} [quiz row, course row] for a quiz whose course is writable, row-locked */
    private function lockQuiz(\mysqli $db, int $quizId): array
    {
        $quiz = Db::one($db, 'SELECT ' . self::QUIZ_COLS . ' FROM training_quizzes WHERE quiz_id = ? FOR UPDATE', 'i', [$quizId]);
        if ($quiz === null) {
            throw ApiException::notFound('That quiz no longer exists.');
        }
        [, $course] = Guard::writableLesson($db, (int) $quiz['quiz_lesson_id']);
        return [$quiz, $course];
    }

    private function lockRule(\mysqli $db, int $ruleId): array
    {
        $rule = Db::one($db, 'SELECT qrule_id, qrule_quiz_id, qrule_bank_id, qrule_include_descendants, qrule_count
            FROM training_quiz_rules WHERE qrule_id = ? FOR UPDATE', 'i', [$ruleId]);
        if ($rule === null) {
            throw ApiException::notFound('That source no longer exists.');
        }
        return $rule;
    }

    /**
     * A rule's bank must be live, must not be another quiz's own bank, and its pool must not
     * overlap another rule of the same quiz (same bank, or one inside the other's subtree when
     * that rule includes sub-banks).
     */
    private function assertRuleBank(\mysqli $db, array $quiz, int $bankId, bool $desc, ?int $exceptRuleId): void
    {
        $bank = Guard::bank($db, $bankId);
        if ($bank['qbank_quiz_lesson_id'] !== null && (int) $bank['qbank_quiz_lesson_id'] !== (int) $quiz['quiz_lesson_id']) {
            throw ApiException::validation(['bank_id' => "Those questions were written for another quiz. Move them to a Question Library bank first."]);
        }
        $tree = BankTree::load($db);
        $mine = $tree->subtree($bankId, false);
        foreach (Db::all($db, 'SELECT qrule_id, qrule_bank_id, qrule_include_descendants FROM training_quiz_rules WHERE qrule_quiz_id = ?',
                'i', [(int) $quiz['quiz_id']]) as $r) {
            if ($exceptRuleId !== null && (int) $r['qrule_id'] === $exceptRuleId) {
                continue;
            }
            $other = (int) $r['qrule_bank_id'];
            $overlap = $other === $bankId
                || ((int) $r['qrule_include_descendants'] === 1 && in_array($bankId, $tree->subtree($other, false), true))
                || ($desc && in_array($other, $mine, true));
            if ($overlap) {
                throw ApiException::validation(['bank_id' => 'This quiz already draws these questions from another source.']);
            }
        }
    }

    private function ruleShape(array $r, BankTree $tree, ?int $ownBankId): array
    {
        $bankId = (int) $r['qrule_bank_id'];
        return [
            'id' => (int) $r['qrule_id'],
            'uid' => (string) $r['qrule_uid'],
            'bank_id' => $bankId,
            'bank_path' => $tree->path($bankId),
            'bank_live' => $tree->isLive($bankId),
            'include_descendants' => (int) $r['qrule_include_descendants'] === 1,
            'count' => (int) $r['qrule_count'],
            'sort' => (int) $r['qrule_sort'],
            'implicit' => $ownBankId !== null && $bankId === $ownBankId,
        ];
    }

    private static function intIn(mixed $v, int $min, int $max, string $field): int
    {
        if (is_string($v) && preg_match('/^-?[0-9]{1,9}$/D', trim($v))) {
            $v = (int) trim($v);
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw ApiException::validation([$field => "Must be a whole number from $min to $max."]);
        }
        return $v;
    }
}
