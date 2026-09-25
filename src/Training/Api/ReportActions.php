<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Csv;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\CompletionView;
use ITFlow\Training\Records\EvidenceStrength;
use ITFlow\Training\Reports\CourseAnalytics;
use ITFlow\Training\Reports\CsvReports;
use ITFlow\Training\Reports\DashboardService;
use ITFlow\Training\Reports\DocAckReport;
use ITFlow\Training\Reports\ExpiringReport;
use ITFlow\Training\Reports\MatrixService;
use ITFlow\Training\Reports\OverdueReport;

/**
 * JSON handlers for the reports lane (Routes/reports.php, spec §4.2). All are GET, level 1,
 * read-only - apart from dash_summary's throttled system reconcile (S16), which is idempotent
 * bookkeeping (spec §8 CSRF row). Authorization order (spec §0 #2): the Router has enforced
 * the level; each handler builds Scope::forCtx() (fail-closed) and refuses an out-of-scope
 * department with 404.
 */
final class ReportActions
{
    public static function dashSummary(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $settings = RecordsSettings::fromDb($c->db);
        $f = self::deptCourse($a, $scope);
        $f['months'] = $a->has('months') ? (int) $a->enum('months', ['3', '6', '12'], false) : 12;
        if (DashboardService::maybeReconcile($c->db, $settings) !== null) {
            $settings = RecordsSettings::fromDb($c->db);
        }
        return (new DashboardService($c, $scope, $settings))->summary($f);
    }

    public static function cellPeople(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        return (new MatrixService($c, $scope, RecordsSettings::fromDb($c->db)))
            ->cellPeople((int) $a->int('client_id', true, 0), (int) $a->int('course_id', true, 1), self::jobLocation($a));
    }

    public static function matrix(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $f = self::deptCourse($a, $scope) + self::jobLocation($a);
        return (new MatrixService($c, $scope, RecordsSettings::fromDb($c->db)))->matrix($f) + ['scope_none' => $scope->isNone()];
    }

    public static function overdue(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $f = self::deptCourse($a, $scope) + self::jobLocation($a);
        return (new OverdueReport($c, $scope, RecordsSettings::fromDb($c->db)))->rows($f) + ['scope_none' => $scope->isNone()];
    }

    public static function expiring(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $days = (int) ($a->enum('days', ['30', '60', '90'], false) ?? 30);
        return (new ExpiringReport($c, $scope))->rows($days, self::deptCourse($a, $scope)) + ['scope_none' => $scope->isNone()];
    }

    public static function course(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        [$from, $to] = self::period($a);
        return (new CourseAnalytics($c, RecordsSettings::fromDb($c->db)))->build((int) $a->int('course_id', true, 1), $from, $to, $scope)
            + ['scope_none' => $scope->isNone()];
    }

    public static function docAcks(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $f = self::deptCourse($a, $scope);
        return (new DocAckReport($c, RecordsSettings::fromDb($c->db)))->build($f['course_id'], ['client_id' => $f['client_id']], $scope)
            + ['scope_none' => $scope->isNone()];
    }

    /** GET raw: streams a CSV (Core\Csv::send guards formula cells) and exits. */
    public static function csv(Ctx $c, ApiContext $a): never
    {
        $scope = Scope::forCtx($c);
        $report = (string) $a->enum('report', CsvReports::REPORTS);
        $f = self::deptCourse($a, $scope) + self::jobLocation($a);
        $f['days'] = (int) ($a->enum('days', ['30', '60', '90'], false) ?? 30);
        $f['method'] = $a->enum('method', ['online', 'session', 'blended', 'evaluation', 'external', 'legacy_paper'], false);
        // The records log's own filter values (include | exclude | only; 1/0 kept for API callers), so its CSV link
        // exports exactly the rows on screen.
        $f['voided'] = match (CompletionView::voidedFilter($a->enum('voided', ['include', 'exclude', 'only', '0', '1', 'true', 'false'], false))) {
            'only' => true,
            'exclude' => false,
            default => null,
        };
        $f['strength'] = $a->enum('strength', array_keys(EvidenceStrength::LABELS), false);
        $f['q'] = $a->str('q', 100, false);
        $f['contact_id'] = $a->int('contact_id', false, 1);
        $f['status'] = $a->enum('status', ['open', 'overdue', 'due_soon', 'completed', 'waived', 'cancelled', 'all'], false);
        if ($report === 'course') {
            [$f['from'], $f['to']] = self::period($a);
        } else {
            $f['from'] = $a->date('from', false);
            $f['to'] = $a->date('to', false);
        }
        [$name, $header, $rows] = (new CsvReports($c, $scope, RecordsSettings::fromDb($c->db)))->build($report, $f);
        CourseActions::log('Export', 'Exported training ' . str_replace('_', ' ', $report) . ' CSV (' . count($rows) . ' rows)', 0);
        Csv::send($name, $header, $rows);
    }

    /**
     * client_id (0 = "No department", which only all-scope users may ask for) and course_id.
     * A department outside the scope is a 404, never a 403 (spec §0 #3).
     *
     * @return array{client_id:?int, course_id:?int}
     */
    public static function deptCourse(ApiContext $a, Scope $scope): array
    {
        $client = $a->int('client_id', false, 0);
        if ($client !== null && !$scope->allows($client)) {
            throw new ApiException(404, 'not_found', 'That department was not found.');
        }
        return ['client_id' => $client, 'course_id' => $a->int('course_id', false, 1)];
    }

    /** @return array{job_id:?int, location_id:?int} */
    private static function jobLocation(ApiContext $a): array
    {
        return ['job_id' => $a->int('job_id', false, 1), 'location_id' => $a->int('location_id', false, 1)];
    }

    /** from / to (local dates); default the last 12 months ending today. @return array{0:string,1:string} */
    private static function period(ApiContext $a): array
    {
        $today = Clock::todayLocal();
        $to = $a->date('to', false) ?? $today;
        $from = $a->date('from', false) ?? Clock::addDays(Clock::addMonths($to, -12), 1);
        if ($from > $to) {
            throw ApiException::validation(['from' => 'The start must be on or before the end.']);
        }
        if (Clock::addMonths($from, 36) < $to) {
            throw ApiException::validation(['from' => 'Choose a period of three years or less.']);
        }
        return [$from, $to];
    }
}
