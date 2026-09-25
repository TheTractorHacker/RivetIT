<?php

namespace ITFlow\Training\Reports;

/**
 * Quiz-attempt statistics for course analytics (S8). Phase 3 creates the attempt tables; until
 * then AttemptStats::provider() returns NullAttemptStats and the page falls back to completion
 * scores (spec §3.6).
 */
interface AttemptStatsProvider
{
    public function available(): bool;

    /**
     * Exam statistics for one course over [$fromUtc, $toUtc] ('Y-m-d H:i:s.v' UTC), restricted to
     * $contactIds (the caller's in-scope people).
     *
     * @param list<int> $contactIds
     * @return ?array{started_people:int, attempts:int, attempt_people:int, passed_people:int,
     *   first_try_pass:int, locked_people:int, score_buckets:list<int>, median_score:?string}
     */
    public function courseSummary(int $courseId, string $fromUtc, string $toUtc, array $contactIds): ?array;

    /**
     * The questions answered correctly least often (at least 5 answers each), hardest first.
     *
     * @return list<array{question_uid:string, answered:int, correct:int, top_wrong:?array{selected:string, count:int},
     *   revision_min:int, revision_max:int}>
     */
    public function hardestQuestions(int $courseId, string $fromUtc, string $toUtc, int $limit = 5): array;
}
