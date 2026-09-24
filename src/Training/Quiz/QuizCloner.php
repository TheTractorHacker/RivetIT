<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Uid;

/**
 * Copies the quiz side of a course for CourseDuplicator (spec §3.4, §3.5).
 *
 *   - the source course's banks (its root, sub-banks and the auto banks of mapped lessons) are
 *     copied with new uids into the target course, keeping the tree shape; questions, texts and
 *     options are copied with new uids (archived questions stay archived, so a restore in the
 *     copy works like in the original);
 *   - each mapped lesson's quiz is copied with its settings and translated intro;
 *   - rules follow the copy: a rule on a copied bank points at the copy; a rule on a shared
 *     bank (or another course's bank) keeps pointing at that bank.
 *
 * Auto banks of lessons that are not in $lessonIdMap (e.g. deleted lessons) are not copied.
 * Runs inside the caller's transaction when there is one.
 */
final class QuizCloner
{
    /**
     * @param array<int, int> $lessonIdMap source lesson id => new lesson id
     *
     * With $from === $to (a lesson duplicated inside its own course) only the mapped lessons'
     * quizzes and their own banks are copied; the course's other banks are shared, not copied.
     */
    public static function cloneCourse(Ctx $c, int $from, int $to, array $lessonIdMap): void
    {
        $db = $c->db;
        if ($from === $to) {
            Db::tx($db, function () use ($c, $db, $lessonIdMap): void {
                foreach ($lessonIdMap as $old => $new) {
                    self::copyLessonQuiz($c, $db, (int) $old, (int) $new);
                }
            });
            return;
        }
        Db::tx($db, function () use ($c, $db, $from, $to, $lessonIdMap): void {
            $tree = BankTree::load($db);
            $bankMap = [];
            $target = Guard::course($db, $to);
            $targetRoot = null;

            // Banks of the source course, parents before children (subtree order from each root).
            $roots = [];
            foreach ($tree->all() as $id => $b) {
                if ($b['qbank_course_id'] !== null && (int) $b['qbank_course_id'] === $from
                    && ($b['qbank_parent_id'] === null || (int) ($tree->get((int) $b['qbank_parent_id'])['qbank_course_id'] ?? 0) !== $from)) {
                    $roots[] = $id;
                }
            }
            foreach ($roots as $root) {
                foreach ($tree->subtree($root, false) as $bid) {
                    $b = $tree->get($bid);
                    $quizLesson = $b['qbank_quiz_lesson_id'] === null ? null : (int) $b['qbank_quiz_lesson_id'];
                    if ($quizLesson !== null && !isset($lessonIdMap[$quizLesson])) {
                        continue;
                    }
                    $parent = $b['qbank_parent_id'] === null ? null : ($bankMap[(int) $b['qbank_parent_id']] ?? null);
                    if ($b['qbank_parent_id'] !== null && $parent === null) {
                        continue; // parent was skipped (should not happen: auto banks have no children)
                    }
                    $isRoot = $tree->isCourseRoot($bid);
                    if ($isRoot && $targetRoot === null) {
                        // The target may already have a root (e.g. created with the course): reuse it.
                        $existing = Db::one($db, 'SELECT qbank_id FROM training_question_banks WHERE qbank_course_id = ? AND qbank_parent_id IS NULL
                            AND qbank_quiz_lesson_id IS NULL ORDER BY qbank_id LIMIT 1', 'i', [$to]);
                        if ($existing !== null) {
                            $targetRoot = (int) $existing['qbank_id'];
                            $bankMap[$bid] = $targetRoot;
                            self::copyQuestions($db, $bid, $targetRoot, $c->userId);
                            continue;
                        }
                    }
                    $name = $isRoot ? mb_substr(trim((string) $target['course_name']), 0, BankService::NAME_MAX) : (string) $b['qbank_name'];
                    $newId = Db::insert($db, 'INSERT INTO training_question_banks (qbank_uid, qbank_parent_id, qbank_name, qbank_description,
                            qbank_course_id, qbank_quiz_lesson_id, qbank_sort, qbank_created_by, qbank_archived_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . ($b['qbank_archived_at'] !== null ? 'NOW()' : 'NULL') . ')',
                        'sissiiii', [Uid::new('b'), $parent, $name !== '' ? $name : 'Course questions', $b['qbank_description'], $to,
                            $quizLesson === null ? null : $lessonIdMap[$quizLesson], (int) $b['qbank_sort'], $c->userId]);
                    if ($isRoot && $targetRoot === null) {
                        $targetRoot = $newId;
                    }
                    $bankMap[$bid] = $newId;
                    self::copyQuestions($db, $bid, $newId, $c->userId);
                }
            }

            foreach ($lessonIdMap as $oldLesson => $newLesson) {
                self::copyQuiz($db, (int) $oldLesson, (int) $newLesson, $bankMap);
            }
        });
    }

    /**
     * Copies one lesson's quiz onto another lesson of the same course (lesson duplicate): the
     * auto bank and its questions are copied, rules on it follow the copy, other rules are kept.
     */
    public static function cloneLessonQuiz(Ctx $c, int $fromLessonId, int $toLessonId): void
    {
        $db = $c->db;
        Db::tx($db, function () use ($c, $db, $fromLessonId, $toLessonId): void {
            self::copyLessonQuiz($c, $db, $fromLessonId, $toLessonId);
        });
    }

    private static function copyLessonQuiz(Ctx $c, \mysqli $db, int $fromLessonId, int $toLessonId): void
    {
        $quiz = Db::one($db, 'SELECT quiz_id FROM training_quizzes WHERE quiz_lesson_id = ?', 'i', [$fromLessonId]);
        if ($quiz === null) {
            return;
        }
        $bankMap = [];
        $own = Db::one($db, 'SELECT ' . Guard::BANK_COLS . ' FROM training_question_banks WHERE qbank_quiz_lesson_id = ? ORDER BY qbank_id LIMIT 1',
            'i', [$fromLessonId]);
        if ($own !== null && Db::one($db, 'SELECT qbank_id FROM training_question_banks WHERE qbank_quiz_lesson_id = ?', 'i', [$toLessonId]) === null) {
            $title = Db::one($db, 'SELECT v.lvar_title FROM training_lessons l JOIN training_courses c ON c.course_id = l.lesson_course_id
                LEFT JOIN training_lesson_variants v ON v.lvar_lesson_id = l.lesson_id AND v.lvar_lang = c.course_default_language
                WHERE l.lesson_id = ?', 'i', [$toLessonId]);
            $label = trim((string) ($title['lvar_title'] ?? ''));
            $newBank = Db::insert($db, 'INSERT INTO training_question_banks (qbank_uid, qbank_parent_id, qbank_name, qbank_description,
                    qbank_course_id, qbank_quiz_lesson_id, qbank_sort, qbank_created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                'sissiiii', [Uid::new('b'), $own['qbank_parent_id'] === null ? null : (int) $own['qbank_parent_id'],
                    mb_substr($label === '' ? (string) $own['qbank_name'] : 'Quiz: ' . $label, 0, BankService::NAME_MAX),
                    $own['qbank_description'], $own['qbank_course_id'] === null ? null : (int) $own['qbank_course_id'], $toLessonId,
                    (int) $own['qbank_sort'] + 1, $c->userId]);
            self::copyQuestions($db, (int) $own['qbank_id'], $newBank, $c->userId);
            $bankMap[(int) $own['qbank_id']] = $newBank;
        }
        // A course has at most one final exam: the copy of an exam inside the same course is a plain quiz.
        self::copyQuiz($db, $fromLessonId, $toLessonId, $bankMap, true);
    }

    private static function copyQuestions(\mysqli $db, int $fromBank, int $toBank, int $userId): void
    {
        $ids = array_map(static fn($r) => (int) $r['question_id'], Db::all($db,
            'SELECT question_id FROM training_questions WHERE question_bank_id = ? ORDER BY question_sort, question_id', 'i', [$fromBank]));
        foreach (QuestionData::load($db, $ids) as $q) {
            QuestionService::insertCopy($db, $q, $toBank, $q['sort'], $userId, $q['archived']);
        }
    }

    /** @param array<int, int> $bankMap source bank id => copied bank id */
    private static function copyQuiz(\mysqli $db, int $oldLesson, int $newLesson, array $bankMap, bool $demoteExam = false): void
    {
        $z = Db::one($db, 'SELECT quiz_id, quiz_role, quiz_pass_pct, quiz_max_attempts, quiz_time_limit_s, quiz_shuffle_questions,
                quiz_shuffle_options, quiz_feedback_mode, quiz_show_review, quiz_must_pass, quiz_intro
            FROM training_quizzes WHERE quiz_lesson_id = ?', 'i', [$oldLesson]);
        if ($z === null) {
            return;
        }
        if (Db::one($db, 'SELECT quiz_id FROM training_quizzes WHERE quiz_lesson_id = ?', 'i', [$newLesson]) !== null) {
            return; // already has a quiz (idempotent)
        }
        $newQuiz = Db::insert($db, 'INSERT INTO training_quizzes (quiz_uid, quiz_lesson_id, quiz_role, quiz_pass_pct, quiz_max_attempts,
                quiz_time_limit_s, quiz_shuffle_questions, quiz_shuffle_options, quiz_feedback_mode, quiz_show_review, quiz_must_pass, quiz_intro)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'sisiiiiisiis', [Uid::new('z'), $newLesson, $demoteExam && $z['quiz_role'] === 'exam' ? 'standalone' : (string) $z['quiz_role'],
                (int) $z['quiz_pass_pct'], (int) $z['quiz_max_attempts'],
                $z['quiz_time_limit_s'] === null ? null : (int) $z['quiz_time_limit_s'], (int) $z['quiz_shuffle_questions'],
                (int) $z['quiz_shuffle_options'], (string) $z['quiz_feedback_mode'], (int) $z['quiz_show_review'], (int) $z['quiz_must_pass'],
                $z['quiz_intro']]);
        foreach (Db::all($db, "SELECT ti18n_lang, ti18n_field, ti18n_value, ti18n_updated_by FROM training_i18n
                WHERE ti18n_entity = 'quiz' AND ti18n_entity_id = ?", 'i', [(int) $z['quiz_id']]) as $r) {
            Db::exec($db, "INSERT INTO training_i18n (ti18n_entity, ti18n_entity_id, ti18n_lang, ti18n_field, ti18n_value, ti18n_updated_by)
                VALUES ('quiz', ?, ?, ?, ?, ?)", 'isssi', [$newQuiz, (string) $r['ti18n_lang'], (string) $r['ti18n_field'],
                (string) $r['ti18n_value'], (int) $r['ti18n_updated_by']]);
        }
        foreach (Db::all($db, 'SELECT qrule_bank_id, qrule_include_descendants, qrule_count, qrule_sort FROM training_quiz_rules
                WHERE qrule_quiz_id = ? ORDER BY qrule_sort, qrule_id', 'i', [(int) $z['quiz_id']]) as $r) {
            $bank = (int) $r['qrule_bank_id'];
            Db::exec($db, 'INSERT INTO training_quiz_rules (qrule_uid, qrule_quiz_id, qrule_bank_id, qrule_include_descendants, qrule_count, qrule_sort)
                VALUES (?, ?, ?, ?, ?, ?)', 'siiiii', [Uid::new('u'), $newQuiz, $bankMap[$bank] ?? $bank, (int) $r['qrule_include_descendants'],
                (int) $r['qrule_count'], (int) $r['qrule_sort']]);
        }
    }
}
