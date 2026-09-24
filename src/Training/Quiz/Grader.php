<?php

namespace ITFlow\Training\Quiz;

/**
 * Grades a submitted attempt against the key captured when it was drawn (spec §3.5, §6.2).
 *
 * Validation (anything else is \DomainException, which callers turn into 400 validation):
 *   - answers is a map questionUid => list of optionUids;
 *   - every question uid is in the draw; every option uid was presented for that question;
 *   - no option twice; single and true/false take at most one selection.
 * An unanswered question (missing, or an empty list) counts as wrong.
 *
 * Scoring is all-or-nothing per question (the selected set must equal the correct set),
 *   passed    = earned*100 >= pass_pct*possible  AND  no critical question missed
 *   score_pct = floor(earned*10000/possible)/100, formatted '%.2f' with integer arithmetic only.
 */
final class Grader
{
    /**
     * @param list<array{q:string, o:list<string>, rule?:string}> $draw
     * @param array<string, array{type:string, correct:list<string>, points:int, critical:bool}> $key
     * @param array<string, mixed> $answers
     * @return array{points_earned:int, points_possible:int, score_pct:string, passed:bool, pass_pct:int, critical_missed:int,
     *               questions:list<array{uid:string, correct:bool, points:int, earned:int, critical:bool, selected:list<string>}>}
     */
    public static function grade(array $draw, array $key, array $answers, int $passPct): array
    {
        $presented = [];
        foreach ($draw as $d) {
            $presented[$d['q']] = array_fill_keys($d['o'], true);
        }
        if ($answers !== [] && array_is_list($answers)) {
            throw new \DomainException('Answers must be keyed by question.');
        }
        $clean = [];
        foreach ($answers as $qUid => $sel) {
            $qUid = (string) $qUid;
            if (!isset($presented[$qUid], $key[$qUid])) {
                throw new \DomainException('An answer is for a question that was not asked.');
            }
            if ($sel === null) {
                $sel = [];
            }
            if (!is_array($sel) || !array_is_list($sel)) {
                throw new \DomainException('Each answer must be a list of choices.');
            }
            $seen = [];
            foreach ($sel as $o) {
                if (!is_string($o) || !isset($presented[$qUid][$o])) {
                    throw new \DomainException('A choice was not one of the answers shown.');
                }
                if (isset($seen[$o])) {
                    throw new \DomainException('A choice was sent twice.');
                }
                $seen[$o] = true;
            }
            if (in_array($key[$qUid]['type'] ?? 'single', ['single', 'truefalse'], true) && count($sel) > 1) {
                throw new \DomainException('Choose one answer for each single-choice question.');
            }
            $clean[$qUid] = array_values($sel);
        }

        $earned = 0;
        $possible = 0;
        $criticalMissed = 0;
        $questions = [];
        foreach ($draw as $d) {
            $uid = $d['q'];
            $k = $key[$uid] ?? null;
            if ($k === null) {
                throw new \DomainException('The attempt no longer matches its answer key.');
            }
            $points = (int) $k['points'];
            $selected = $clean[$uid] ?? [];
            $want = array_values(array_map('strval', $k['correct']));
            $got = $selected;
            sort($want, SORT_STRING);
            sort($got, SORT_STRING);
            $ok = $got !== [] && $got === $want;
            $possible += $points;
            if ($ok) {
                $earned += $points;
            } elseif (!empty($k['critical'])) {
                $criticalMissed++;
            }
            $questions[] = [
                'uid' => $uid,
                'correct' => $ok,
                'points' => $points,
                'earned' => $ok ? $points : 0,
                'critical' => !empty($k['critical']),
                'selected' => $selected,
            ];
        }
        $passed = $possible > 0 && $earned * 100 >= $passPct * $possible && $criticalMissed === 0;
        return [
            'points_earned' => $earned,
            'points_possible' => $possible,
            'score_pct' => self::scorePct($earned, $possible),
            'passed' => $passed,
            'pass_pct' => $passPct,
            'critical_missed' => $criticalMissed,
            'questions' => $questions,
        ];
    }

    /** floor(earned*10000/possible)/100 as '%.2f', without floats. */
    public static function scorePct(int $earned, int $possible): string
    {
        if ($possible <= 0) {
            return '0.00';
        }
        $hundredths = intdiv($earned * 10000, $possible);
        return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }
}
