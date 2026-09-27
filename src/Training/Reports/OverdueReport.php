<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\RetakeVoids;

/**
 * Overdue required training, grouped by department (department name, "No department" last),
 * rows by days overdue, most overdue first. Pair status comes from ComplianceService, so this
 * is exactly the dashboard's "Overdue" KPI broken out (spec §3.6, §5.1).
 */
final class OverdueReport
{
    public const AGE_BUCKETS = ['1-7' => [1, 7], '8-30' => [8, 30], '31-60' => [31, 60], '60+' => [61, PHP_INT_MAX]];

    private PairSource $src;

    public function __construct(
        private readonly Ctx $c,
        private readonly Scope $scope,
        private readonly RecordsSettings $settings,
    ) {
        $this->src = new PairSource($c, $scope, $settings);
    }

    /**
     * @param array{client_id?:?int, course_id?:?int, job_id?:?int, location_id?:?int} $f
     * @return array{groups:list<array>, total:int, people:int, ageing:list<array>, lapsed:list<array>, lapsed_total:int, lapsed_people:int}
     */
    public function rows(array $f = []): array
    {
        $pairs = $this->src->pairs($f);
        $people = [];
        foreach ($this->src->people($f) as $p) {
            $people[$p['contact_id']] = $p;
        }
        return self::fromPairs($this->c->db, $pairs, $people);
    }

    /** @param array<int, array> $people contact_id => person */
    public static function fromPairs(\mysqli $db, array $pairs, array $people): array
    {
        $overdue = array_values(array_filter($pairs, static fn($p) => $p['required'] && $p['status'] === 'overdue'));
        $assignments = self::assignmentDetails($db, array_values(array_filter(array_map(static fn($p) => $p['assignment_id'], $overdue))));
        $groups = [];
        $who = [];
        foreach ($overdue as $p) {
            $person = $people[$p['contact_id']] ?? ['name' => 'Contact #' . $p['contact_id'], 'title' => null, 'client_name' => null];
            $a = $p['assignment_id'] !== null ? ($assignments[$p['assignment_id']] ?? null) : null;
            $dep = $p['client_id'];
            $groups[$dep] ??= ['client_id' => $dep, 'name' => null, 'rows' => []];
            if ($groups[$dep]['name'] === null && !empty($person['client_name']) && $dep > 0) {
                $groups[$dep]['name'] = (string) $person['client_name'];
            }
            $who[$p['contact_id']] = true;
            $groups[$dep]['rows'][] = [
                'person' => ['contact_id' => $p['contact_id'], 'name' => (string) $person['name'], 'title' => $person['title'] ?? null,
                    'initials' => Labels::initials((string) $person['name'])],
                'course' => $p['course'],
                'due_on' => $p['due_on'],
                'original_due_on' => $a['original_due_on'] ?? null,
                'days_overdue' => $p['days_overdue'],
                'label' => $p['label'],
                'reason_label' => $a !== null ? $a['anchor_label'] : Labels::anchor($p['anchor'], null),
                'assignment_id' => $p['assignment_id'],
            ];
        }
        $names = Lookup::departmentNames($db, array_keys($groups));
        foreach ($groups as $dep => &$g) {
            $g['name'] ??= $names[$dep] ?? ('Department #' . $dep);
            usort($g['rows'], static fn($a, $b) => [$b['days_overdue'], $a['person']['name']] <=> [$a['days_overdue'], $b['person']['name']]);
            $g['count'] = count($g['rows']);
            $g['people'] = count(array_unique(array_map(static fn($r) => $r['person']['contact_id'], $g['rows'])));
        }
        unset($g);
        $groups = array_values($groups);
        usort($groups, static function ($a, $b) {
            if (($a['client_id'] === 0) !== ($b['client_id'] === 0)) {
                return $a['client_id'] === 0 ? 1 : -1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $ageing = [];
        foreach (self::AGE_BUCKETS as $label => [$lo, $hi]) {
            $n = 0;
            foreach ($overdue as $p) {
                $d = max(1, $p['days_overdue']);
                if ($d >= $lo && $d <= $hi) {
                    $n++;
                }
            }
            $ageing[] = ['bucket' => $label, 'count' => $n];
        }

        // Expired — not qualified, renewal open but not due yet: the certificate already lapsed (for example a card that
        // had expired before it was entered), so the renewal's due date is today + lead and it is not overdue. Listed on
        // its own so these people are found where overdue people are looked for; not part of the overdue counts.
        $lapsedPairs = array_values(array_filter($pairs, static fn($p) => $p['required'] && !empty($p['lapsed']) && $p['status'] !== 'overdue'));
        $lapsedDetails = self::assignmentDetails($db, array_values(array_filter(array_map(static fn($p) => $p['assignment_id'], $lapsedPairs))));
        $lapsed = [];
        $lapsedWho = [];
        foreach ($lapsedPairs as $p) {
            $person = $people[$p['contact_id']] ?? ['name' => 'Contact #' . $p['contact_id'], 'title' => null, 'client_name' => null];
            $a = $p['assignment_id'] !== null ? ($lapsedDetails[$p['assignment_id']] ?? null) : null;
            $lapsedWho[$p['contact_id']] = true;
            $lapsed[] = [
                'person' => ['contact_id' => $p['contact_id'], 'name' => (string) $person['name'], 'title' => $person['title'] ?? null,
                    'initials' => Labels::initials((string) $person['name'])],
                'client_id' => $p['client_id'],
                'department' => $p['client_id'] > 0 ? ($names[$p['client_id']] ?? ($person['client_name'] ?? null)) : null,
                'course' => $p['course'],
                'expires_on' => $p['expires_on'],
                'due_on' => $p['due_on'],
                'reason_label' => $a !== null ? $a['anchor_label'] : Labels::anchor($p['anchor'], null),
                'assignment_id' => $p['assignment_id'],
            ];
        }
        if ($lapsed !== []) {
            $missing = array_values(array_unique(array_filter(array_map(static fn($r) => $r['department'] === null && $r['client_id'] > 0 ? $r['client_id'] : 0, $lapsed))));
            $more = $missing !== [] ? Lookup::departmentNames($db, $missing) : [];
            foreach ($lapsed as &$r) {
                if ($r['department'] === null && $r['client_id'] > 0) {
                    $r['department'] = $more[$r['client_id']] ?? ('Department #' . $r['client_id']);
                }
            }
            unset($r);
            usort($lapsed, static fn($a, $b) => [$a['department'] === null ? 1 : 0, (string) $a['department'], (string) $a['expires_on'], $a['person']['name']]
                <=> [$b['department'] === null ? 1 : 0, (string) $b['department'], (string) $b['expires_on'], $b['person']['name']]);
        }
        return ['groups' => $groups, 'total' => count($overdue), 'people' => count($who), 'ageing' => $ageing,
                'lapsed' => $lapsed, 'lapsed_total' => count($lapsed), 'lapsed_people' => count($lapsedWho)];
    }

    /**
     * Reason label, requirement and original due date for a batch of assignment ids.
     *
     * @param list<int> $ids
     * @return array<int, array{reason:string, anchor:string, anchor_label:string, requirement_name:?string, original_due_on:?string}>
     */
    public static function assignmentDetails(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Db::all($db, "SELECT a.tassign_id, a.tassign_reason, a.tassign_anchor, a.tassign_original_due_on,
                    r.requirement_name, r.requirement_is_manual,
                    (SELECT tc.completion_expires_on FROM training_completions tc
                        WHERE a.tassign_anchor LIKE 'renew:c%' AND tc.completion_id = CAST(SUBSTRING(a.tassign_anchor, 8) AS UNSIGNED)) AS renew_expires_on,
                    (SELECT rv.revision_number FROM training_revisions rv
                        WHERE a.tassign_anchor LIKE 'retrain:r%' AND rv.revision_id = CAST(SUBSTRING(a.tassign_anchor, 10) AS UNSIGNED)) AS retrain_number,
                    (SELECT IFNULL(tc.completion_cert_number, '') FROM training_completions tc
                        JOIN training_completion_voids tv ON tv.cvoid_completion_id = tc.completion_id AND tv.cvoid_reason LIKE ?
                        WHERE a.tassign_anchor LIKE 'reissue:c%' AND tc.completion_id = CAST(SUBSTRING(a.tassign_anchor, 10) AS UNSIGNED)) AS retake_cert
                FROM training_assignments a
                LEFT JOIN training_requirements r ON r.requirement_id = a.tassign_requirement_id
                WHERE a.tassign_id IN ($in)", 's' . str_repeat('i', count($chunk)), array_merge([RetakeVoids::PREFIX . '%'], $chunk));
            foreach ($rows as $r) {
                $out[(int) $r['tassign_id']] = [
                    'reason' => (string) $r['tassign_reason'],
                    'anchor' => (string) $r['tassign_anchor'],
                    'anchor_label' => Labels::anchor((string) $r['tassign_anchor'], $r['requirement_name'],
                        $r['renew_expires_on'], $r['retrain_number'] !== null ? (int) $r['retrain_number'] : null, (bool) $r['requirement_is_manual'],
                        $r['retake_cert'] === null ? null : (string) $r['retake_cert']),
                    'requirement_name' => $r['requirement_name'],
                    'is_manual' => (bool) $r['requirement_is_manual'],
                    'original_due_on' => $r['tassign_original_due_on'],
                    'renew_expires_on' => $r['renew_expires_on'],
                ];
            }
        }
        return $out;
    }
}
