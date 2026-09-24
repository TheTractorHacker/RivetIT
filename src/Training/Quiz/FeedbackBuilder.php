<?php

namespace ITFlow\Training\Quiz;

/**
 * What a learner is told after a graded attempt (spec §3.5, §6.2).
 *
 *   score_only          the score, nothing about individual questions
 *   missed_questions    each missed question's prompt, topic and explanation, plus the feedback of
 *                       the options the learner chose - never the correct option
 *   answers_after_pass  as missed_questions, plus the correct options - only when the attempt passed
 *
 * The key entries are captured at start for one language, so text values are plain strings;
 * a {lang: text} map is also accepted and resolved with $lang.
 */
final class FeedbackBuilder
{
    /**
     * @param array $graded Grader::grade() result
     * @param array $key    uid => {correct, text, topic, explanation, feedback:{optionUid: text}}
     * @return array{mode:string, missed:list<array>, answers?:array<string, list<string>>}
     */
    public static function build(string $mode, bool $passed, array $graded, array $key, string $lang): array
    {
        $out = ['mode' => $mode, 'missed' => []];
        if ($mode === 'score_only') {
            return $out;
        }
        foreach ($graded['questions'] as $g) {
            if ($g['correct']) {
                continue;
            }
            $k = $key[$g['uid']] ?? [];
            $item = [
                'uid' => $g['uid'],
                'text' => (string) (self::pick($k['text'] ?? null, $lang) ?? ''),
                'topic' => self::pick($k['topic'] ?? null, $lang),
            ];
            $explanation = self::pick($k['explanation'] ?? null, $lang);
            if ($explanation !== null) {
                $item['explanation'] = $explanation;
            }
            $chosen = [];
            foreach ($g['selected'] as $o) {
                $fb = self::pick($k['feedback'][$o] ?? null, $lang);
                if ($fb !== null) {
                    $chosen[] = $fb;
                }
            }
            if ($chosen !== []) {
                $item['chosen_feedback'] = $chosen;
            }
            $out['missed'][] = $item;
        }
        if ($mode === 'answers_after_pass' && $passed) {
            $answers = [];
            foreach ($graded['questions'] as $g) {
                $answers[$g['uid']] = array_values(array_map('strval', $key[$g['uid']]['correct'] ?? []));
            }
            $out['answers'] = $answers;
        }
        return $out;
    }

    /**
     * Distinct topics of missed questions, in attempt order (none for score_only).
     *
     * @return list<string>
     */
    public static function topicsMissed(string $mode, array $graded, array $key, string $lang): array
    {
        if ($mode === 'score_only') {
            return [];
        }
        $out = [];
        foreach ($graded['questions'] as $g) {
            if ($g['correct']) {
                continue;
            }
            $t = self::pick($key[$g['uid']]['topic'] ?? null, $lang);
            if ($t !== null && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    private static function pick(mixed $v, string $lang): ?string
    {
        if (is_array($v)) {
            $v = $v[$lang] ?? null;
        }
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        return trim($s) === '' ? null : $s;
    }
}
