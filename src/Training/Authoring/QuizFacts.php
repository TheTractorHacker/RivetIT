<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Core\Db;

/**
 * Read-only quiz numbers the builder and the duration estimate need per lesson: the quiz id and
 * role, how many questions a learner will see, the pool size and per-language translation
 * counts. The authoritative pool/draw logic (overlaps, critical rules, publish checks) is
 * Lane D's PoolResolver; this is the light-weight count for lesson rows and estimates.
 *
 * Pools follow the draw rules (bank + optional descendants, archived banks and questions
 * excluded). A quiz without any rule counts its own auto bank.
 */
final class QuizFacts
{
    /**
     * @param list<int>    $lessonIds
     * @param list<string> $languages
     * @return array<int, array{quiz_id:int, uid:string, role:string, question_count:int, pool:int, translated:array<string,int>}>
     */
    public static function forLessons(\mysqli $db, array $lessonIds, array $languages): array
    {
        $lessonIds = array_values(array_unique(array_filter(array_map('intval', $lessonIds), static fn($i) => $i > 0)));
        if ($lessonIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($lessonIds), '?'));
        $quizzes = Db::all($db, "SELECT quiz_id, quiz_uid, quiz_lesson_id, quiz_role FROM training_quizzes WHERE quiz_lesson_id IN ($in)", str_repeat('i', count($lessonIds)), $lessonIds);
        if ($quizzes === []) {
            return [];
        }
        $quizIds = array_map(static fn($q) => (int) $q['quiz_id'], $quizzes);
        $qin = implode(',', array_fill(0, count($quizIds), '?'));
        $rules = Db::all($db, "SELECT qrule_quiz_id, qrule_bank_id, qrule_include_descendants, qrule_count FROM training_quiz_rules WHERE qrule_quiz_id IN ($qin) ORDER BY qrule_quiz_id, qrule_sort, qrule_id", str_repeat('i', count($quizIds)), $quizIds);
        $rulesByQuiz = [];
        foreach ($rules as $r) {
            $rulesByQuiz[(int) $r['qrule_quiz_id']][] = $r;
        }

        // Live bank tree (archived banks and everything under them are out of every pool).
        $children = [];
        $live = [];
        $ownBank = [];
        foreach (Db::all($db, 'SELECT qbank_id, qbank_parent_id, qbank_quiz_lesson_id FROM training_question_banks WHERE qbank_archived_at IS NULL') as $b) {
            $id = (int) $b['qbank_id'];
            $live[$id] = true;
            $children[(int) ($b['qbank_parent_id'] ?? 0)][] = $id;
            if ($b['qbank_quiz_lesson_id'] !== null) {
                $ownBank[(int) $b['qbank_quiz_lesson_id']] = $id;
            }
        }

        // Per quiz: list of [bankIds, count].
        $plans = [];
        $allBanks = [];
        foreach ($quizzes as $q) {
            $qid = (int) $q['quiz_id'];
            $plan = [];
            $qRules = $rulesByQuiz[$qid] ?? [];
            if ($qRules === [] && isset($ownBank[(int) $q['quiz_lesson_id']])) {
                $qRules = [['qrule_bank_id' => $ownBank[(int) $q['quiz_lesson_id']], 'qrule_include_descendants' => 0, 'qrule_count' => 0]];
            }
            foreach ($qRules as $r) {
                $root = (int) $r['qrule_bank_id'];
                $banks = [];
                if (isset($live[$root])) {
                    $banks = (int) $r['qrule_include_descendants'] === 1 ? self::subtree($root, $children) : [$root];
                }
                foreach ($banks as $b) {
                    $allBanks[$b] = true;
                }
                $plan[] = [$banks, (int) $r['qrule_count']];
            }
            $plans[$qid] = $plan;
        }

        $byBank = [];
        $bankIds = array_keys($allBanks);
        foreach (array_chunk($bankIds, 500) as $chunk) {
            foreach (Db::all($db, 'SELECT question_id, question_bank_id FROM training_questions WHERE question_archived_at IS NULL AND question_bank_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', str_repeat('i', count($chunk)), $chunk) as $row) {
                $byBank[(int) $row['question_bank_id']][] = (int) $row['question_id'];
            }
        }

        $poolByQuiz = [];
        $drawByQuiz = [];
        $allQuestions = [];
        foreach ($plans as $qid => $plan) {
            $pool = [];
            $draw = 0;
            foreach ($plan as [$banks, $count]) {
                $rulePool = [];
                foreach ($banks as $b) {
                    foreach ($byBank[$b] ?? [] as $qq) {
                        $rulePool[$qq] = true;
                    }
                }
                $n = count($rulePool);
                $draw += $count > 0 ? min($count, $n) : $n;
                $pool += $rulePool;
            }
            $poolByQuiz[$qid] = array_keys($pool);
            $drawByQuiz[$qid] = $draw;
            foreach ($pool as $qq => $_) {
                $allQuestions[$qq] = true;
            }
        }

        $complete = self::completeByLang($db, array_keys($allQuestions), $languages);

        $out = [];
        foreach ($quizzes as $q) {
            $qid = (int) $q['quiz_id'];
            $translated = [];
            foreach ($languages as $lang) {
                $n = 0;
                foreach ($poolByQuiz[$qid] as $qq) {
                    if (isset($complete[$lang][$qq])) {
                        $n++;
                    }
                }
                $translated[$lang] = $n;
            }
            $out[(int) $q['quiz_lesson_id']] = [
                'quiz_id' => $qid,
                'uid' => (string) $q['quiz_uid'],
                'role' => (string) $q['quiz_role'],
                'question_count' => $drawByQuiz[$qid],
                'pool' => count($poolByQuiz[$qid]),
                'translated' => $translated,
            ];
        }
        return $out;
    }

    /** @return list<int> */
    private static function subtree(int $root, array $children): array
    {
        $out = [];
        $stack = [$root];
        $seen = [];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }
        return $out;
    }

    /**
     * Questions that are fully written in each language: question text plus every option's text.
     *
     * @return array<string, array<int, true>>
     */
    private static function completeByLang(\mysqli $db, array $questionIds, array $languages): array
    {
        $out = [];
        foreach ($languages as $lang) {
            $out[$lang] = [];
        }
        if ($questionIds === [] || $languages === []) {
            return $out;
        }
        foreach (array_chunk($questionIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $has = [];
            foreach (Db::all($db, "SELECT qtext_question_id, qtext_lang FROM training_question_texts WHERE qtext_question_id IN ($in) AND TRIM(qtext_text) <> ''", str_repeat('i', count($chunk)), $chunk) as $r) {
                $has[(string) $r['qtext_lang']][(int) $r['qtext_question_id']] = true;
            }
            $optCount = [];
            foreach (Db::all($db, "SELECT option_question_id, COUNT(*) AS n FROM training_question_options WHERE option_question_id IN ($in) GROUP BY option_question_id", str_repeat('i', count($chunk)), $chunk) as $r) {
                $optCount[(int) $r['option_question_id']] = (int) $r['n'];
            }
            $optText = [];
            foreach (Db::all($db, "SELECT o.option_question_id, t.otext_lang, COUNT(*) AS n FROM training_question_options o
                    JOIN training_option_texts t ON t.otext_option_id = o.option_id AND TRIM(t.otext_text) <> ''
                    WHERE o.option_question_id IN ($in) GROUP BY o.option_question_id, t.otext_lang", str_repeat('i', count($chunk)), $chunk) as $r) {
                $optText[(string) $r['otext_lang']][(int) $r['option_question_id']] = (int) $r['n'];
            }
            foreach ($languages as $lang) {
                foreach ($chunk as $qid) {
                    if (isset($has[$lang][$qid]) && ($optText[$lang][$qid] ?? 0) >= ($optCount[$qid] ?? 0)) {
                        $out[$lang][$qid] = true;
                    }
                }
            }
        }
        return $out;
    }
}
