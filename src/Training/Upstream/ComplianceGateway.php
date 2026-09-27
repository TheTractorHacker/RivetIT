<?php

namespace ITFlow\Training\Upstream;

use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Compliance\ComplianceService;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Reports\Lookup;

/**
 * S4 reminders on P2's compliance engine (spec §3.1). Phase 5 recomputes no status: every count comes
 * from ComplianceService::pairs() statuses (overdue / due_soon / expiring), exactly what the P2
 * dashboard and reports show.
 */
final class ComplianceGateway
{
    public function __construct(private readonly \mysqli $db)
    {
    }

    /**
     * One ComplianceService($system, Scope::all())->pairs(['required_only' => true]) grouped by the
     * person's department (client_id 0 = "No department"). overdue = status 'overdue'; escalated =
     * overdue with days_overdue > $escalateDays; due_soon = 'due_soon'; renewal = 'expiring'.
     * Departments with nothing to report are left out; ordered by overdue desc, then name.
     * [] when P2 compliance or scope is unavailable.
     *
     * @return list<array{client_id:int, client_name:string, overdue:int, escalated:int, due_soon:int, renewal:int,
     *                    overdue_people:int, escalated_people:list<array{name:string, courses:list<string>}>, top_courses:list<string>}>
     */
    public function departmentCounts(Ctx $system, int $escalateDays): array
    {
        if (!P2::has($this->db, 'compliance') || !P2::has($this->db, 'scope')) {
            return [];
        }
        $pairs = (new ComplianceService($system, Scope::all()))->pairs(['required_only' => true]);
        $by = [];
        $escalatedIds = [];
        foreach ($pairs as $p) {
            $status = (string) ($p['status'] ?? '');
            if (!in_array($status, ['overdue', 'due_soon', 'expiring'], true)) {
                continue;
            }
            $cid = (int) ($p['client_id'] ?? 0);
            $by[$cid] ??= ['client_id' => $cid, 'client_name' => '', 'overdue' => 0, 'escalated' => 0, 'due_soon' => 0, 'renewal' => 0,
                           'overdue_people' => 0, 'escalated_people' => [], 'top_courses' => [], '_people' => [], '_esc' => [], '_courses' => []];
            $course = (string) ($p['course']['name'] ?? ('Course #' . (int) ($p['course_id'] ?? 0)));
            if ($status === 'overdue') {
                $by[$cid]['overdue']++;
                $by[$cid]['_people'][(int) $p['contact_id']] = true;
                $by[$cid]['_courses'][$course] = ($by[$cid]['_courses'][$course] ?? 0) + 1;
                if ((int) ($p['days_overdue'] ?? 0) > $escalateDays) {
                    $by[$cid]['escalated']++;
                    $by[$cid]['_esc'][(int) $p['contact_id']][$course] = true;
                    $escalatedIds[(int) $p['contact_id']] = true;
                }
            } elseif ($status === 'due_soon') {
                $by[$cid]['due_soon']++;
            } else {
                $by[$cid]['renewal']++;
            }
        }
        if ($by === []) {
            return [];
        }
        $names = Lookup::departmentNames($this->db, array_keys($by));
        $people = $this->contactNames(array_keys($escalatedIds));
        $out = [];
        foreach ($by as $cid => $row) {
            $row['client_name'] = (string) ($names[$cid] ?? ($cid === 0 ? Lookup::NO_DEPARTMENT : 'Department #' . $cid));
            $row['overdue_people'] = count($row['_people']);
            $esc = [];
            foreach ($row['_esc'] as $contactId => $courses) {
                $list = array_keys($courses);
                sort($list, SORT_NATURAL | SORT_FLAG_CASE);
                $esc[] = ['name' => (string) ($people[$contactId] ?? ('Person #' . $contactId)), 'courses' => $list];
            }
            usort($esc, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
            $row['escalated_people'] = $esc;
            $courses = $row['_courses'];
            uksort($courses, static fn($a, $b) => ($courses[$b] <=> $courses[$a]) ?: strcasecmp((string) $a, (string) $b));
            $row['top_courses'] = array_slice(array_map('strval', array_keys($courses)), 0, 3);
            unset($row['_people'], $row['_esc'], $row['_courses']);
            $out[] = $row;
        }
        usort($out, static fn($a, $b) => ($b['overdue'] <=> $a['overdue']) ?: strcasecmp($a['client_name'], $b['client_name']));
        return $out;
    }

    /**
     * Daily step 0 (spec §6.1): when P2's last reconcile (RecordsSettings.reconciledAtUtc) is older than
     * $maxAgeHours (or never ran), run P2's AssignmentService($system)->reconcile(null, 'cron'). P2 owns
     * the transaction, the `trrec` lock and its ledger events; this must not run inside a transaction.
     *
     * @return 'fresh'|'ran'|'busy'|'unavailable'|'error'
     */
    public function reconcileIfStale(Ctx $system, int $maxAgeHours = 20): string
    {
        if (!P2::has($this->db, 'reconcile') || !P2::has($this->db, 'compliance')) {
            return 'unavailable';
        }
        try {
            $s = RecordsSettings::fromDb($this->db);
            if (!$s->schemaReady) {
                return 'unavailable';
            }
            if ($s->reconciledAtUtc !== null) {
                $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s' . (str_contains($s->reconciledAtUtc, '.') ? '.u' : ''),
                    $s->reconciledAtUtc, new \DateTimeZone('UTC'));
                if ($at !== false && (time() - $at->getTimestamp()) < max(1, $maxAgeHours) * 3600) {
                    return 'fresh';
                }
            }
            if (Db::depth() !== 0) {
                throw new \LogicException('reconcileIfStale must not run inside a transaction');
            }
            $r = (new AssignmentService($system))->reconcile(null, 'cron');
            return !empty($r['skipped_busy']) ? 'busy' : 'ran';
        } catch (\Throwable $e) {
            error_log('Training Upstream reconcileIfStale: ' . get_class($e) . ': ' . $e->getMessage());
            return 'error';
        }
    }

    /** @param list<int> $ids @return array<int, string> */
    private function contactNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Db::all($this->db, 'SELECT contact_id, contact_name FROM contacts WHERE contact_id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ')', str_repeat('i', count($chunk)), $chunk) as $r) {
                $out[(int) $r['contact_id']] = (string) $r['contact_name'];
            }
        }
        return $out;
    }
}
