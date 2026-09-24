<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Core\Db;

/**
 * Resolves a quiz's draw rules to their question pools (spec §3.5).
 *
 * Per rule the pool is the ordered list of live questions in the rule's bank - and, with
 * include_descendants, in its live sub-banks - in "bank DFS, question_sort, id" order. Pools are
 * language-neutral: the same questions and key for every language (§1.3 #10). Per language,
 * `translated` counts the pool questions complete in that language; a language is usable for
 * the quiz only if every rule's pool is fully translated. Overlaps (one question in two rules'
 * pools) are reported for the validator (quiz_pools_overlap).
 */
final class PoolResolver
{
    /**
     * @param list<string> $languages
     * @return array{
     *   quiz_id:int,
     *   rules:list<array{id:int, uid:string, bank_id:int, bank_uid:?string, bank_live:bool, include_descendants:bool,
     *                    count:int, sort:int, pool_ids:list<int>, pool:list<string>, critical_ids:list<int>,
     *                    translated:array<string,int>}>,
     *   overlaps:list<array{rule_ids:list<int>, question_count:int}>,
     *   languages_ready:array<string,bool>,
     *   questions:array<int,array>
     * }
     */
    public static function resolve(\mysqli $db, int $quizId, array $languages, ?BankTree $tree = null): array
    {
        $tree ??= BankTree::load($db);
        $rules = Db::all($db, 'SELECT qrule_id, qrule_uid, qrule_bank_id, qrule_include_descendants, qrule_count, qrule_sort
            FROM training_quiz_rules WHERE qrule_quiz_id = ? ORDER BY qrule_sort, qrule_id', 'i', [$quizId]);

        $poolIds = [];
        $allIds = [];
        foreach ($rules as $r) {
            $ids = QuestionData::idsForBanks($db, $tree->poolBanks((int) $r['qrule_bank_id'], (int) $r['qrule_include_descendants'] === 1));
            $poolIds[(int) $r['qrule_id']] = $ids;
            foreach ($ids as $id) {
                $allIds[$id] = true;
            }
        }
        $questions = QuestionData::load($db, array_keys($allIds));

        $outRules = [];
        $ready = array_fill_keys($languages, true);
        foreach ($rules as $r) {
            $rid = (int) $r['qrule_id'];
            $ids = array_values(array_filter($poolIds[$rid], static fn($id) => isset($questions[$id])));
            $translated = array_fill_keys($languages, 0);
            $critical = [];
            $uids = [];
            foreach ($ids as $id) {
                $q = $questions[$id];
                $uids[] = $q['uid'];
                if ($q['critical']) {
                    $critical[] = $id;
                }
                foreach ($languages as $l) {
                    if (QuestionRules::completeIn($q, $l)) {
                        $translated[$l]++;
                    }
                }
            }
            foreach ($languages as $l) {
                if ($translated[$l] !== count($ids)) {
                    $ready[$l] = false;
                }
            }
            $bank = $tree->get((int) $r['qrule_bank_id']);
            $outRules[] = [
                'id' => $rid,
                'uid' => (string) $r['qrule_uid'],
                'bank_id' => (int) $r['qrule_bank_id'],
                'bank_uid' => $bank === null ? null : (string) $bank['qbank_uid'],
                'bank_live' => $tree->isLive((int) $r['qrule_bank_id']),
                'include_descendants' => (int) $r['qrule_include_descendants'] === 1,
                'count' => (int) $r['qrule_count'],
                'sort' => (int) $r['qrule_sort'],
                'pool_ids' => $ids,
                'pool' => $uids,
                'critical_ids' => $critical,
                'translated' => $translated,
            ];
        }

        $overlaps = [];
        for ($i = 0; $i < count($outRules); $i++) {
            for ($j = $i + 1; $j < count($outRules); $j++) {
                $shared = count(array_intersect($outRules[$i]['pool_ids'], $outRules[$j]['pool_ids']));
                if ($shared > 0) {
                    $overlaps[] = ['rule_ids' => [$outRules[$i]['id'], $outRules[$j]['id']], 'question_count' => $shared];
                }
            }
        }

        return [
            'quiz_id' => $quizId,
            'rules' => $outRules,
            'overlaps' => $overlaps,
            'languages_ready' => $ready,
            'questions' => $questions,
        ];
    }

    /**
     * Questions a learner is shown for one rule: all when count is 0, else count - but never
     * fewer than the rule's critical questions (all of which are always drawn), never more than
     * the pool.
     */
    public static function willDraw(int $count, int $available, int $critical): int
    {
        if ($count <= 0) {
            return $available;
        }
        return min($available, max($count, $critical));
    }

    /** Total questions a learner is shown across all rules of a resolved quiz (overlaps counted once). */
    public static function totalDraw(array $resolved): int
    {
        $total = 0;
        $seen = [];
        foreach ($resolved['rules'] as $r) {
            $fresh = array_values(array_filter($r['pool_ids'], static fn($id) => !isset($seen[$id])));
            foreach ($fresh as $id) {
                $seen[$id] = true;
            }
            $crit = count(array_intersect($r['critical_ids'], $fresh));
            $total += self::willDraw($r['count'], count($fresh), $crit);
        }
        return $total;
    }
}
