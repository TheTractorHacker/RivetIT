<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Authoring\CourseTouch;
use ITFlow\Training\Core\Db;

/**
 * Which quiz rules draw from which banks, and which courses a bank or question edit affects.
 *
 * A rule on bank B draws B's questions, and with include_descendants also those of every live
 * bank below B. So bank X is "used" by a rule on X itself, or by a descendants rule on any
 * ancestor of X. Every question in a used bank is part of that quiz's pool and therefore of the
 * course's revision JSON, which is why an edit to such a question touches the course
 * (CourseTouch, spec §3.4) - including courses that draw from a shared bank.
 */
final class Usage
{
    /**
     * Rules whose pool includes any of $bankIds, with their quiz, lesson and course.
     *
     * @return list<array<string, mixed>> rows: qrule_*, quiz_id, quiz_uid, quiz_lesson_id, lesson_course_id,
     *         lesson_archived_at, course_name, course_archived_at, lesson_title
     */
    public static function rulesForBanks(\mysqli $db, array $bankIds, ?BankTree $tree = null): array
    {
        $bankIds = array_values(array_unique(array_map('intval', $bankIds)));
        if ($bankIds === []) {
            return [];
        }
        $tree ??= BankTree::load($db);
        $direct = array_fill_keys($bankIds, true);
        $ancestors = [];
        foreach ($bankIds as $b) {
            foreach ($tree->ancestors($b) as $a) {
                $ancestors[$a] = true;
            }
        }
        [$ph, $t, $p] = InList::ints(array_merge(array_keys($direct), array_keys($ancestors)));
        $rows = Db::all($db, "SELECT r.qrule_id, r.qrule_uid, r.qrule_quiz_id, r.qrule_bank_id, r.qrule_include_descendants,
                r.qrule_count, r.qrule_sort, z.quiz_id, z.quiz_uid, z.quiz_lesson_id, l.lesson_course_id, l.lesson_archived_at,
                c.course_name, c.course_archived_at,
                (SELECT v.lvar_title FROM training_lesson_variants v WHERE v.lvar_lesson_id = l.lesson_id
                   AND v.lvar_lang = c.course_default_language) AS lesson_title
            FROM training_quiz_rules r
            JOIN training_quizzes z ON z.quiz_id = r.qrule_quiz_id
            JOIN training_lessons l ON l.lesson_id = z.quiz_lesson_id
            JOIN training_courses c ON c.course_id = l.lesson_course_id
            WHERE r.qrule_bank_id IN ($ph)
            ORDER BY c.course_id, l.lesson_id, r.qrule_sort, r.qrule_id", $t, $p);
        $out = [];
        foreach ($rows as $r) {
            $rb = (int) $r['qrule_bank_id'];
            if (isset($direct[$rb]) || (isset($ancestors[$rb]) && (int) $r['qrule_include_descendants'] === 1)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Non-archived courses whose quizzes (on live lessons) draw from any of $bankIds.
     *
     * @return list<int> ascending
     */
    public static function coursesForBanks(\mysqli $db, array $bankIds, ?BankTree $tree = null): array
    {
        $ids = [];
        foreach (self::rulesForBanks($db, $bankIds, $tree) as $r) {
            if ($r['course_archived_at'] === null && $r['lesson_archived_at'] === null) {
                $ids[(int) $r['lesson_course_id']] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Marks each course's draft as changed (CourseTouch). Ascending id order, so two writers
     * touching overlapping sets lock course rows in the same order.
     */
    public static function touch(\mysqli $db, array $courseIds): void
    {
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
        sort($courseIds, SORT_NUMERIC);
        foreach ($courseIds as $cid) {
            if ($cid > 0) {
                CourseTouch::touch($db, $cid);
            }
        }
    }

    /** Touches every course affected by an edit to the questions of $bankIds. */
    public static function touchBanks(\mysqli $db, array $bankIds, ?BankTree $tree = null): void
    {
        self::touch($db, self::coursesForBanks($db, $bankIds, $tree));
    }
}
