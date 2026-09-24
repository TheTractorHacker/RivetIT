<?php

namespace ITFlow\Training\Quiz;

/**
 * What makes a question valid, and complete in a language - one definition for the builder's
 * issue chips, pool statistics, the publish validator and language readiness.
 *
 * Questions are language-neutral rows (spec §1.3 #10): type, options, correct and pinned
 * flags, points, the critical flag and the image are shared; only texts are per language.
 *
 *   valid (structure, all languages):
 *     single      >= 2 options, exactly one correct
 *     multi       >= 2 options, at least one correct
 *     truefalse   exactly 2 options, exactly one correct (labels are fixed per language)
 *     points      1..10
 *   complete in language L:
 *     the question text in L is non-empty, and (except true/false) every option has a
 *     non-empty label in L. Explanation, topic and per-option feedback are optional.
 */
final class QuestionRules
{
    public const TYPES = ['single', 'multi', 'truefalse'];
    public const MAX_OPTIONS = 8;
    public const MIN_POINTS = 1;
    public const MAX_POINTS = 10;
    public const TEXT_MAX = 2000;
    public const EXPLANATION_MAX = 2000;
    public const TOPIC_MAX = 100;
    public const OPTION_TEXT_MAX = 1000;
    public const OPTION_FEEDBACK_MAX = 500;

    /** Fixed true/false labels; any other language falls back to English. */
    public const TF_LABELS = ['en' => ['True', 'False'], 'es' => ['Verdadero', 'Falso']];

    public const SUB_MESSAGES = [
        'no_correct' => 'Mark the correct answer.',
        'multiple_correct' => 'Only one answer can be correct for this question type.',
        'too_few_options' => 'Add at least two answers.',
        'tf_shape' => 'A true/false question needs exactly the two answers True and False.',
        'empty_option' => 'One of the answers is empty.',
        'text_missing' => 'Type the question.',
        'points_range' => 'Points must be between 1 and 10.',
    ];

    public static function tfLabel(string $lang, int $index): string
    {
        $labels = self::TF_LABELS[$lang] ?? self::TF_LABELS['en'];
        return $labels[$index === 0 ? 0 : 1];
    }

    public static function completeIn(array $q, string $lang): bool
    {
        if (trim((string) ($q['texts'][$lang]['text'] ?? '')) === '') {
            return false;
        }
        if ($q['type'] === 'truefalse') {
            return true;
        }
        if ($q['options'] === []) {
            return false;
        }
        foreach ($q['options'] as $o) {
            if (trim((string) ($o['texts'][$lang]['text'] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> sub-codes that apply in every language */
    public static function structuralProblems(array $q): array
    {
        $out = [];
        $n = count($q['options']);
        $correct = 0;
        foreach ($q['options'] as $o) {
            if ($o['correct']) {
                $correct++;
            }
        }
        if ($q['type'] === 'truefalse') {
            if ($n !== 2) {
                $out[] = 'tf_shape';
            }
            if ($correct === 0) {
                $out[] = 'no_correct';
            } elseif ($correct > 1) {
                $out[] = 'multiple_correct';
            }
        } else {
            if ($n < 2) {
                $out[] = 'too_few_options';
            }
            if ($correct === 0) {
                $out[] = 'no_correct';
            } elseif ($correct > 1 && $q['type'] === 'single') {
                $out[] = 'multiple_correct';
            }
        }
        if ($q['points'] < self::MIN_POINTS || $q['points'] > self::MAX_POINTS) {
            $out[] = 'points_range';
        }
        return $out;
    }

    /** @return list<string> sub-codes for the texts of one language */
    public static function textProblems(array $q, string $lang): array
    {
        $out = [];
        if (trim((string) ($q['texts'][$lang]['text'] ?? '')) === '') {
            $out[] = 'text_missing';
        }
        if ($q['type'] !== 'truefalse') {
            foreach ($q['options'] as $o) {
                if (trim((string) ($o['texts'][$lang]['text'] ?? '')) === '') {
                    $out[] = 'empty_option';
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Issues for the builder card and the publish check (texts judged in $defaultLang;
     * other languages show as incomplete rather than invalid).
     *
     * @return list<array<string, mixed>>
     */
    public static function issues(array $q, string $defaultLang, string $severity = 'error'): array
    {
        $out = [];
        foreach (array_merge(self::structuralProblems($q), self::textProblems($q, $defaultLang)) as $sub) {
            $out[] = [
                'code' => 'question_invalid',
                'sub' => $sub,
                'severity' => $severity,
                'message' => self::SUB_MESSAGES[$sub],
                'question_id' => $q['id'],
            ];
        }
        return $out;
    }

    /** Labels of the options in $lang (true/false: the fixed labels). @return list<string> */
    public static function optionLabels(array $q, string $lang): array
    {
        $out = [];
        foreach ($q['options'] as $i => $o) {
            $out[] = $q['type'] === 'truefalse' ? self::tfLabel($lang, $i) : (string) ($o['texts'][$lang]['text'] ?? '');
        }
        return $out;
    }

    /**
     * The question as it appears in revision JSON v1 (spec §3.6 "questions"), with texts for
     * $langs only (languages with no text row are left out, except that $langs[0] - the course
     * default - is always present so every question has a prompt entry).
     */
    public static function revisionDoc(array $q, array $langs): array
    {
        $text = [];
        foreach ($langs as $i => $lang) {
            $t = $q['texts'][$lang] ?? null;
            if ($t === null && $i !== 0) {
                continue;
            }
            $text[$lang] = [
                'q' => (string) ($t['text'] ?? ''),
                'explanation' => self::nullIfBlank($t['explanation'] ?? null),
                'topic' => self::nullIfBlank($t['topic'] ?? null),
                'media_id' => isset($t['media_id']) ? (int) $t['media_id'] : null,
            ];
        }
        $options = [];
        foreach ($q['options'] as $idx => $o) {
            $otext = [];
            foreach ($langs as $i => $lang) {
                if (!isset($text[$lang])) {
                    continue;
                }
                $ot = $o['texts'][$lang] ?? null;
                $label = $q['type'] === 'truefalse' ? self::tfLabel($lang, $idx) : (string) ($ot['text'] ?? '');
                $otext[$lang] = ['label' => $label, 'feedback' => self::nullIfBlank($ot['feedback'] ?? null)];
            }
            $options[] = [
                'uid' => $o['uid'],
                'correct' => (bool) $o['correct'],
                'pinned' => (bool) $o['pinned'],
                'text' => $otext,
            ];
        }
        return [
            'uid' => $q['uid'],
            'type' => $q['type'],
            'points' => (int) $q['points'],
            'critical' => (bool) $q['critical'],
            'media_id' => $q['media_id'] === null ? null : (int) $q['media_id'],
            'text' => $text,
            'options' => $options,
        ];
    }

    /** Every language that has a text row, sorted (used for language-independent bank hashes). @return list<string> */
    public static function textLanguages(array $q): array
    {
        $langs = array_map('strval', array_keys($q['texts']));
        sort($langs, SORT_STRING);
        return $langs;
    }

    public static function nullIfBlank(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        return trim($s) === '' ? null : $s;
    }
}
