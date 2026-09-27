<?php

namespace ITFlow\Training\Insight;

use ITFlow\Training\Api\ApiContext;
use ITFlow\Training\Api\CourseActions;
use ITFlow\Training\Core\Csv;
use ITFlow\Training\Core\Ctx;

/**
 * Router handlers for item analysis (Phase 5 spec §4.1, S7; Routes/p5_insight.php). Both are GET at
 * level 2 - the report reveals the answer key (options[].correct), which authors already see in the
 * builder - and both are limited to the caller's people scope inside ItemAnalysis. No person data.
 *
 * Request: course_id; revision_id? (must belong to the course, else 422 validation); lang? (a
 * language offered in Admin > Training); kind = exam | check | all (default exam); since? Y-m-d.
 */
final class InsightActions
{
    public const CSV_HEADER = ['#', 'Question', 'Type', 'Critical', 'Answered', '% correct', 'Discrimination', 'Most-chosen wrong answer', '%', 'Flags'];
    public const FLAG_LABELS = ['few' => 'Fewer than 10 answers', 'hard' => 'Hard', 'easy' => 'Very easy', 'check_key' => 'Check the answer key'];

    /** GET insight_items (L2) => ItemReport. */
    public static function items(Ctx $c, ApiContext $a): array
    {
        [$courseId, $revisionId, $lang, $kind, $since] = self::filter($a);
        return (new ItemAnalysis($c))->forCourse($courseId, $revisionId, $lang, $kind, $since);
    }

    /** GET insight_revisions (L2, L1 "Compare versions"): course_id, a, b (two versions of the course), kind? => RevisionCompare. */
    public static function revisions(Ctx $c, ApiContext $a): array
    {
        return (new RevisionCompare($c))->compare(
            (int) $a->int('course_id', true, 1),
            (int) $a->int('a', true, 1),
            (int) $a->int('b', true, 1),
            $a->enum('kind', ItemAnalysis::KINDS, false) ?? 'exam',
        );
    }

    /** GET raw insight_items_csv (L2): the same report as a CSV (Core\Csv guards every cell) and exits. */
    public static function itemsCsv(Ctx $c, ApiContext $a): never
    {
        [$courseId, $revisionId, $lang, $kind, $since] = self::filter($a);
        $report = (new ItemAnalysis($c))->forCourse($courseId, $revisionId, $lang, $kind, $since);
        $rows = self::csvRows($report);
        CourseActions::log('Export', 'Exported training item analysis CSV for course #' . $courseId . ' (' . count($rows) . ' questions)', $courseId);
        Csv::send('item-analysis-' . $report['course']['uid'] . '.csv', self::CSV_HEADER, $rows);
    }

    /**
     * The CSV rows of a report (numbers as plain text; empty cell = not shown).
     *
     * @return list<list<string|int>>
     */
    public static function csvRows(array $report): array
    {
        $rows = [];
        foreach ($report['questions'] as $q) {
            $rows[] = [
                (int) $q['number'],
                (string) $q['text'],
                ItemAnalysis::TYPE_LABELS[$q['type']] ?? (string) $q['type'],
                $q['critical'] ? 'Yes' : '',
                (int) $q['n'],
                $q['correct_pct'] === null ? '' : self::num($q['correct_pct'], 1),
                $q['discrimination'] === null ? '' : self::num($q['discrimination'], 2),
                $q['wrong_top'] === null ? '' : (string) $q['wrong_top']['label'],
                $q['wrong_top'] === null ? '' : self::num($q['wrong_top']['pct'], 1),
                implode('; ', array_map(static fn($f) => self::FLAG_LABELS[$f] ?? $f, $q['flags'])),
            ];
        }
        return $rows;
    }

    /** @return array{0:int, 1:?int, 2:?string, 3:string, 4:?string} */
    private static function filter(ApiContext $a): array
    {
        return [
            (int) $a->int('course_id', true, 1),
            $a->int('revision_id', false, 1),
            $a->lang('lang', false),
            $a->enum('kind', ItemAnalysis::KINDS, false) ?? 'exam',
            $a->date('since', false),
        ];
    }

    private static function num(float|int $v, int $decimals): string
    {
        return number_format((float) $v, $decimals, '.', '');
    }
}
