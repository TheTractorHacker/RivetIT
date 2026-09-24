<?php

namespace ITFlow\Training\Quiz;

/**
 * CSV rows for the question template and for a bank export (spec §5.5 "CSV columns").
 *
 *   uid, lang, type(single|multi|truefalse), question, a..h, correct (letters, e.g. "B" or "A,C"),
 *   points, critical (y/n), topic, explanation
 *
 * One row per question and language. A bank export includes the answer key, which is why the
 * bank_export_csv action is level 3. Rows are written with Core\Csv::send, whose formula guard
 * the importer undoes, so export -> import restores every text exactly.
 */
final class QuestionExporter
{
    public const HEADER = ['uid', 'lang', 'type', 'question', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'correct', 'points', 'critical', 'topic', 'explanation'];
    public const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    /** Example rows for the downloadable template (the header is separate). @return list<list<string>> */
    public static function templateRows(): array
    {
        return [
            ['', 'en', 'single', 'Where must you store oily rags?', 'In a closed metal container', 'In a cardboard box', 'On the workbench', '', '', '', '', '',
                'A', '1', 'n', 'Housekeeping', 'Oily rags can heat up and catch fire on their own.'],
            ['', 'en', 'multi', 'Which of these are PPE?', 'Safety glasses', 'Hard hat', 'Coffee mug', 'Gloves', '', '', '', '',
                'A,B,D', '2', 'n', 'PPE', ''],
            ['', 'en', 'truefalse', 'You may remove a lockout lock that is not yours.', 'True', 'False', '', '', '', '', '', '',
                'B', '1', 'y', 'Lockout/Tagout', 'Only the person who applied a lock may remove it.'],
        ];
    }

    /** @return list<list<string>> */
    public static function bankRows(\mysqli $db, int $bankId): array
    {
        $out = [];
        foreach (QuestionData::load($db, QuestionData::idsForBanks($db, [$bankId])) as $q) {
            $langs = QuestionRules::textLanguages($q);
            if ($langs === []) {
                $langs = [''];
            }
            $letters = [];
            foreach ($q['options'] as $i => $o) {
                if ($o['correct'] && isset(self::LETTERS[$i])) {
                    $letters[] = self::LETTERS[$i];
                }
            }
            foreach ($langs as $lang) {
                $t = $q['texts'][$lang] ?? ['text' => '', 'explanation' => null, 'topic' => null];
                $labels = QuestionRules::optionLabels($q, $lang === '' ? 'en' : $lang);
                $cells = [];
                for ($i = 0; $i < 8; $i++) {
                    $cells[] = $labels[$i] ?? '';
                }
                $out[] = array_merge(
                    [$q['uid'], $lang, $q['type'], (string) $t['text']],
                    $cells,
                    [implode(',', $letters), (string) $q['points'], $q['critical'] ? 'y' : 'n', (string) ($t['topic'] ?? ''), (string) ($t['explanation'] ?? '')]
                );
            }
        }
        return $out;
    }
}
