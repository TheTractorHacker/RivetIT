<?php

namespace ITFlow\Training\Quiz;

/**
 * Draws a quiz attempt from a revision's (or a draft build's) frozen pools (spec §3.5).
 *
 * Per rule: count 0 draws the whole pool; otherwise every critical question of the pool plus a
 * Fisher-Yates sample (random_int) of the non-critical ones up to `count` - critical questions
 * are never left out, even when they outnumber `count`. Drawn questions keep pool order unless
 * the quiz shuffles questions, in which case the whole attempt is shuffled. Options keep their
 * order unless the quiz shuffles options: then non-pinned options are shuffled among the
 * non-pinned positions (pinned options keep their index) and true/false is never shuffled.
 *
 * Output: [['q' => questionUid, 'o' => [optionUid, …as presented], 'rule' => ruleUid], …]
 * The same shape becomes the Phase 3 attempt's draw_json.
 */
final class QuizDraw
{
    /**
     * @param array $quizRev   revision quiz object: {rules:[{uid, count, pool:[uids]}], shuffle_questions, shuffle_options}
     * @param array $questions revision "questions" map: uid => {type, critical, options:[{uid, pinned}]}
     * @param callable|null $rand fn(int $min, int $max): int, inclusive (default random_int)
     * @return list<array{q:string, o:list<string>, rule:string}>
     */
    public static function draw(array $quizRev, array $questions, ?callable $rand = null): array
    {
        $rand ??= static fn(int $min, int $max): int => random_int($min, $max);
        $drawn = [];
        $seen = [];
        foreach ($quizRev['rules'] ?? [] as $rule) {
            $pool = [];
            foreach ($rule['pool'] ?? [] as $uid) {
                if (isset($questions[$uid]) && !isset($seen[$uid])) {
                    $pool[] = $uid;
                }
            }
            $count = (int) ($rule['count'] ?? 0);
            if ($count <= 0 || $count >= count($pool)) {
                $selected = $pool;
            } else {
                $critical = [];
                $other = [];
                foreach ($pool as $uid) {
                    if (!empty($questions[$uid]['critical'])) {
                        $critical[] = $uid;
                    } else {
                        $other[] = $uid;
                    }
                }
                $pick = array_fill_keys($critical, true);
                foreach (self::sample($other, max(0, $count - count($critical)), $rand) as $uid) {
                    $pick[$uid] = true;
                }
                $selected = array_values(array_filter($pool, static fn($u) => isset($pick[$u])));
            }
            foreach ($selected as $uid) {
                $seen[$uid] = true;
                $drawn[] = ['q' => $uid, 'rule' => (string) ($rule['uid'] ?? '')];
            }
        }
        if (!empty($quizRev['shuffle_questions'])) {
            $drawn = self::shuffle($drawn, $rand);
        }
        $out = [];
        foreach ($drawn as $d) {
            $q = $questions[$d['q']];
            $options = $q['options'] ?? [];
            $uids = array_map(static fn($o) => (string) $o['uid'], $options);
            if (!empty($quizRev['shuffle_options']) && ($q['type'] ?? '') !== 'truefalse') {
                $uids = self::shuffleUnpinned($options, $rand);
            }
            $out[] = ['q' => $d['q'], 'o' => $uids, 'rule' => $d['rule']];
        }
        return $out;
    }

    /** How many questions draw() returns for this quiz (critical questions always count). */
    public static function expectedCount(array $quizRev, array $questions): int
    {
        $n = 0;
        $seen = [];
        foreach ($quizRev['rules'] ?? [] as $rule) {
            $pool = [];
            $critical = 0;
            foreach ($rule['pool'] ?? [] as $uid) {
                if (isset($questions[$uid]) && !isset($seen[$uid])) {
                    $pool[] = $uid;
                    $seen[$uid] = true;
                    if (!empty($questions[$uid]['critical'])) {
                        $critical++;
                    }
                }
            }
            $n += PoolResolver::willDraw((int) ($rule['count'] ?? 0), count($pool), $critical);
        }
        return $n;
    }

    /** $k items of $items chosen uniformly (partial Fisher-Yates). @return list */
    private static function sample(array $items, int $k, callable $rand): array
    {
        $items = array_values($items);
        $n = count($items);
        $k = min($k, $n);
        for ($i = 0; $i < $k; $i++) {
            $j = $rand($i, $n - 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return array_slice($items, 0, $k);
    }

    /** Full Fisher-Yates. @return list */
    private static function shuffle(array $items, callable $rand): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }

    /** @return list<string> option uids with pinned options at their original index */
    private static function shuffleUnpinned(array $options, callable $rand): array
    {
        $out = [];
        $freePos = [];
        $free = [];
        foreach (array_values($options) as $i => $o) {
            $out[$i] = (string) $o['uid'];
            if (empty($o['pinned'])) {
                $freePos[] = $i;
                $free[] = (string) $o['uid'];
            }
        }
        $free = self::shuffle($free, $rand);
        foreach ($freePos as $k => $pos) {
            $out[$pos] = $free[$k];
        }
        ksort($out);
        return array_values($out);
    }
}
