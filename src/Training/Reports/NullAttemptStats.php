<?php

namespace ITFlow\Training\Reports;

/** No attempt tables yet (before Phase 3): every statistic is unavailable. */
final class NullAttemptStats implements AttemptStatsProvider
{
    public function available(): bool
    {
        return false;
    }

    public function courseSummary(int $courseId, string $fromUtc, string $toUtc, array $contactIds): ?array
    {
        return null;
    }

    public function hardestQuestions(int $courseId, string $fromUtc, string $toUtc, int $limit = 5): array
    {
        return [];
    }
}
