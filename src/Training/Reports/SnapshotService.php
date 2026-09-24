<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\SystemCtx;
use ITFlow\Training\People\Scope;

/**
 * Daily compliance snapshots (M12, S7): `training_compliance_daily`, grain (date, client,
 * course). course_id = 0 rows are each department's people-level totals; client_id = 0 is
 * "No department" (spec §1.4 #10).
 *
 * Column meaning (every count is over ELIGIBLE people, all departments):
 *   eligible_people  people in the department (course 0 rows; per-course rows: people with a pair)
 *   required_pairs   required pairs EXCLUDING waived - the compliance denominator
 *   current_pairs    required pairs that count as current (current, expiring, retrain_due)
 *   overdue_pairs    required pairs whose status is overdue
 *   overdue_people   distinct people with at least one overdue required pair
 *   expiring_30      current pairs whose record expires within 30 days of the snapshot date
 *   waived_pairs     required pairs under an active waiver
 *   completions      non-voided completions completed ON the snapshot date
 * so compliance % for any slice = SUM(current_pairs) / SUM(required_pairs).
 *
 * capture() is idempotent per date (DELETE + INSERT in one transaction) and never overlaps
 * itself (named lock `trsnap`, 0 s wait). It runs from cron/training_cron.php step 4 and from
 * Admin › Training compliance "Capture today's snapshot".
 */
final class SnapshotService
{
    /**
     * @return array{skipped:bool, rows:int, date:string, people?:int, pairs?:int}
     */
    public static function capture(\mysqli $db, string $date): array
    {
        if (!Clock::isYmd($date)) {
            throw new \InvalidArgumentException('SnapshotService::capture: date must be Y-m-d');
        }
        if (Db::depth() !== 0) {
            throw new \LogicException('SnapshotService::capture must not run inside a transaction');
        }
        if (!Db::lock($db, 'trsnap', 0)) {
            return ['skipped' => true, 'rows' => 0, 'date' => $date];
        }
        try {
            $ctx = SystemCtx::make($db, 0, 'training_snapshot');
            $settings = RecordsSettings::fromDb($db);
            $src = new PairSource($ctx, Scope::all(), $settings);
            $pairs = $src->pairs();
            $people = $src->people();
            $rows = self::aggregate($pairs, $people, $date, self::completionsOn($db, $date));

            Db::tx($db, static function () use ($db, $date, $rows): void {
                Db::exec($db, 'DELETE FROM training_compliance_daily WHERE tdaily_date = ?', 's', [$date]);
                foreach ($rows as $r) {
                    Db::exec($db, 'INSERT INTO training_compliance_daily
                        (tdaily_date, tdaily_client_id, tdaily_course_id, tdaily_eligible_people, tdaily_required_pairs,
                         tdaily_current_pairs, tdaily_overdue_pairs, tdaily_overdue_people, tdaily_expiring_30,
                         tdaily_waived_pairs, tdaily_completions)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'siiiiiiiiii', [
                        $date, $r['client_id'], $r['course_id'], $r['eligible_people'], $r['required_pairs'],
                        $r['current_pairs'], $r['overdue_pairs'], $r['overdue_people'], $r['expiring_30'],
                        $r['waived_pairs'], $r['completions'],
                    ]);
                }
                Db::exec($db, 'UPDATE settings SET config_training_snapshot_last_on = ? WHERE company_id = 1', 's', [$date]);
            });
            return ['skipped' => false, 'rows' => count($rows), 'date' => $date, 'people' => count($people), 'pairs' => count($pairs)];
        } finally {
            Db::unlock($db, 'trsnap');
        }
    }

    /**
     * Pure aggregation (unit-testable): pairs + people + completions-on-date => snapshot rows.
     *
     * @param array<string, int> $completions "client:course" => count
     * @return list<array<string, int>>
     */
    public static function aggregate(array $pairs, array $people, string $date, array $completions = []): array
    {
        $soon = Clock::addDays($date, 30);
        $acc = [];
        $blank = ['eligible_people' => 0, 'required_pairs' => 0, 'current_pairs' => 0, 'overdue_pairs' => 0,
            'overdue_people' => 0, 'expiring_30' => 0, 'waived_pairs' => 0, 'completions' => 0];
        $pairPeople = [];
        $overduePeople = [];
        foreach ($people as $p) {
            $k = $p['client_id'] . ':0';
            $acc[$k] ??= ['client_id' => $p['client_id'], 'course_id' => 0] + $blank;
            $acc[$k]['eligible_people']++;
        }
        foreach ($pairs as $p) {
            if (!$p['required']) {
                continue;
            }
            foreach ([$p['client_id'] . ':' . $p['course_id'], $p['client_id'] . ':0'] as $i => $k) {
                $acc[$k] ??= ['client_id' => $p['client_id'], 'course_id' => $i === 0 ? $p['course_id'] : 0] + $blank;
                if ($i === 0) {
                    $pairPeople[$k][$p['contact_id']] = true;
                }
                if ($p['waived']) {
                    $acc[$k]['waived_pairs']++;
                    continue;
                }
                $acc[$k]['required_pairs']++;
                if ($p['counts_current']) {
                    $acc[$k]['current_pairs']++;
                    if ($p['expires_on'] !== null && $p['expires_on'] >= $date && $p['expires_on'] <= $soon) {
                        $acc[$k]['expiring_30']++;
                    }
                }
                if ($p['status'] === 'overdue') {
                    $acc[$k]['overdue_pairs']++;
                    $overduePeople[$k][$p['contact_id']] = true;
                }
            }
        }
        foreach ($pairPeople as $k => $set) {
            $acc[$k]['eligible_people'] = count($set);
        }
        foreach ($overduePeople as $k => $set) {
            $acc[$k]['overdue_people'] = count($set);
        }
        foreach ($completions as $k => $n) {
            [$client] = explode(':', $k);
            if (isset($acc[$k])) {
                $acc[$k]['completions'] = (int) $n;
            }
            if (isset($acc[$client . ':0'])) {
                $acc[$client . ':0']['completions'] += (int) $n;
            }
        }
        ksort($acc, SORT_NATURAL);
        return array_values($acc);
    }

    /** @return array<string, int> "client:course" => non-voided completions completed on $date */
    private static function completionsOn(\mysqli $db, string $date): array
    {
        $rows = Db::all($db, "SELECT c.contact_client_id, tc.completion_course_id, COUNT(*) AS n
            FROM training_completions tc
            JOIN contacts c ON c.contact_id = tc.completion_contact_id
            WHERE tc.completion_completed_on = ?
              AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = tc.completion_id)
            GROUP BY c.contact_client_id, tc.completion_course_id", 's', [$date]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['contact_client_id'] . ':' . (int) $r['completion_course_id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Monthly compliance series: the LAST snapshot of each of the last $months months (the
     * current month included), for the scope's departments (client 0 only with all-scope),
     * optionally one course and one department. Months with no snapshot are null.
     *
     * @return array{labels:list<string>, months:list<string>, values:list<?int>, dates:list<?string>, points:int}
     */
    public static function trend(\mysqli $db, Scope $s, ?int $courseId, int $months, ?int $clientId = null): array
    {
        $months = max(1, min(24, $months));
        $today = Clock::todayLocal();
        $first = substr(Clock::addMonths(substr($today, 0, 7) . '-01', -($months - 1)), 0, 7) . '-01';
        $keys = [];
        for ($i = 0; $i < $months; $i++) {
            $keys[] = substr(Clock::addMonths($first, $i), 0, 7);
        }
        $byMonth = [];
        if (!$s->isNone()) {
            [$scopeSql, $scopeTypes, $scopeParams] = $s->sqlIn('d.tdaily_client_id');
            $sql = "SELECT d.tdaily_date, SUM(d.tdaily_current_pairs) AS cur, SUM(d.tdaily_required_pairs) AS req
                FROM training_compliance_daily d
                JOIN (SELECT MAX(tdaily_date) AS md FROM training_compliance_daily
                      WHERE tdaily_date >= ? AND tdaily_date <= ? GROUP BY LEFT(tdaily_date, 7)) m ON m.md = d.tdaily_date
                WHERE d.tdaily_course_id = ?" . $scopeSql;
            $types = 'ssi' . $scopeTypes;
            $params = array_merge([$first, $today, $courseId ?? 0], $scopeParams);
            if ($clientId !== null) {
                $sql .= ' AND d.tdaily_client_id = ?';
                $types .= 'i';
                $params[] = $clientId;
            }
            $sql .= ' GROUP BY d.tdaily_date ORDER BY d.tdaily_date';
            foreach (Db::all($db, $sql, $types, $params) as $r) {
                $date = (string) $r['tdaily_date'];
                $byMonth[substr($date, 0, 7)] = ['date' => $date, 'pct' => Labels::pct((int) $r['cur'], (int) $r['req'])];
            }
        }
        $labels = [];
        $values = [];
        $dates = [];
        $points = 0;
        foreach ($keys as $k) {
            $labels[] = (new \DateTimeImmutable($k . '-01'))->format('M');
            $v = $byMonth[$k]['pct'] ?? null;
            $values[] = $v;
            $dates[] = $byMonth[$k]['date'] ?? null;
            if ($v !== null) {
                $points++;
            }
        }
        return ['labels' => $labels, 'months' => $keys, 'values' => $values, 'dates' => $dates, 'points' => $points];
    }

    /**
     * Compliance % from the last snapshot on or before $onOrBefore (null if none), for the
     * same slice trend() would use. Drives the "vs 3 months ago" KPI delta.
     */
    public static function pctAsOf(\mysqli $db, Scope $s, ?int $courseId, string $onOrBefore, ?int $clientId = null): ?int
    {
        if ($s->isNone()) {
            return null;
        }
        $d = Db::one($db, 'SELECT MAX(tdaily_date) AS d FROM training_compliance_daily WHERE tdaily_date <= ?', 's', [$onOrBefore]);
        if ($d === null || $d['d'] === null) {
            return null;
        }
        [$scopeSql, $scopeTypes, $scopeParams] = $s->sqlIn('tdaily_client_id');
        $sql = 'SELECT SUM(tdaily_current_pairs) AS cur, SUM(tdaily_required_pairs) AS req FROM training_compliance_daily
                WHERE tdaily_date = ? AND tdaily_course_id = ?' . $scopeSql;
        $types = 'si' . $scopeTypes;
        $params = array_merge([(string) $d['d'], $courseId ?? 0], $scopeParams);
        if ($clientId !== null) {
            $sql .= ' AND tdaily_client_id = ?';
            $types .= 'i';
            $params[] = $clientId;
        }
        $r = Db::one($db, $sql, $types, $params);
        return $r === null ? null : Labels::pct((int) $r['cur'], (int) $r['req']);
    }
}
