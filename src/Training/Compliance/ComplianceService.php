<?php

namespace ITFlow\Training\Compliance;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Assign\AssignmentStore;
use ITFlow\Training\Assign\CourseCards;
use ITFlow\Training\Assign\RecordFacts;
use ITFlow\Training\Assign\RuleMatcher;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Directory;
use ITFlow\Training\People\Scope;

/**
 * Pair statuses for eligible, in-scope people (M9, Phase 2 spec §3.3). Read-only: nothing here
 * writes, locks or authorizes (the caller has run Access + Scope::forCtx + assertContact).
 *
 * A pair is (person, course) where the person is desired by an active rule (RuleMatcher) or holds
 * an open assignment. Its status comes from PairRules::pairStatus over RecordFacts (non-locking),
 * so the dashboard, reports, transcript, contact card and snapshots all agree with reconcile.
 *
 * PairRow (pairs()) = PairStatus (§4.1) + {contact_id, client_id, course_id, counts_current, in_denominator}:
 *   {contact_id, client_id, course_id, course:{id,name,kind,code,is_qualification}, required, status, label,
 *    due_on, expires_on, days_overdue, assignment_id, completion_id, cert_number, anchor, counts_current, in_denominator}
 * Compliance % = pairs counting as current / required pairs excluding `waived` (null when 0).
 */
final class ComplianceService
{
    /** personStatus() order: what needs action first. */
    private const ORDER = ['overdue' => 0, 'due_soon' => 1, 'due' => 2, 'not_started' => 3, 'retrain_due' => 4, 'expiring' => 5,
                           'expired' => 6, 'current' => 7, 'waived' => 8];

    private RecordsSettings $s;
    private ?string $today;

    public function __construct(private readonly Ctx $c, private readonly Scope $scope, ?RecordsSettings $s = null, ?string $today = null)
    {
        $this->s = $s ?? RecordsSettings::fromDb($c->db);
        $this->today = $today;
    }

    /**
     * Eligible in-scope people (Directory person shape). f: client_id, job_id, location_id, q.
     *
     * @return list<array>
     */
    public function people(array $f = []): array
    {
        if ($this->scope->isNone()) {
            return [];
        }
        $q = isset($f['q']) ? mb_strtolower(trim((string) $f['q']), 'UTF-8') : '';
        $out = [];
        foreach (Directory::load($this->c->db, $this->scope) as $p) {
            if (isset($f['client_id']) && $f['client_id'] !== null && (int) $f['client_id'] !== $p['client_id']) {
                continue;
            }
            if (isset($f['job_id']) && $f['job_id'] !== null && (int) $f['job_id'] !== $p['job_id']) {
                continue;
            }
            if (isset($f['location_id']) && $f['location_id'] !== null && (int) $f['location_id'] !== $p['location_id']) {
                continue;
            }
            if ($q !== '' && !str_contains(mb_strtolower($p['name'] . ' ' . ($p['title'] ?? '') . ' ' . ($p['client_name'] ?? ''), 'UTF-8'), $q)) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * PairRows for eligible in-scope people: desired pairs ∪ open assignments.
     * f: client_id, course_id, job_id, location_id, q, required_only (default true).
     *
     * @return list<array>
     */
    public function pairs(array $f = []): array
    {
        $people = $this->people($f);
        if ($people === []) {
            return [];
        }
        $byId = [];
        foreach ($people as $p) {
            $byId[$p['contact_id']] = $p;
        }
        $courseFilter = isset($f['course_id']) && $f['course_id'] !== null ? [(int) $f['course_id']] : ($f['course_ids'] ?? null);
        return $this->compute($byId, $courseFilter, !array_key_exists('required_only', $f) || !empty($f['required_only']));
    }

    /**
     * One person's pairs (required and optional) and required-pair summary.
     * 404 not_found when the contact is missing or out of scope; an ineligible person gets no pairs.
     *
     * @return array{person:array, pairs:list<array>, summary:array{required:int, current:int, overdue:int, expiring_60:int, pct:?int}}
     */
    public function personStatus(int $contactId): array
    {
        $person = $this->scope->isNone() ? null : (Directory::load($this->c->db, $this->scope, [$contactId], true)[$contactId] ?? null);
        if ($person === null) {
            throw ApiException::notFound('That person was not found.');
        }
        $today = $this->today();
        $pairs = $person['eligible'] ? $this->compute([$contactId => $person], null, false) : [];
        usort($pairs, static function ($a, $b) {
            $d = (self::ORDER[$a['status']] ?? 9) <=> (self::ORDER[$b['status']] ?? 9);
            if ($d !== 0) {
                return $d;
            }
            if ($a['required'] !== $b['required']) {
                return $a['required'] ? -1 : 1;
            }
            return strcasecmp($a['course']['name'], $b['course']['name']);
        });
        $in60 = Clock::addDays($today, 60);
        $sum = ['required' => 0, 'current' => 0, 'overdue' => 0, 'expiring_60' => 0, 'pct' => null];
        $out = [];
        foreach ($pairs as $p) {
            if ($p['required'] && $p['in_denominator']) {
                $sum['required']++;
                if ($p['counts_current']) {
                    $sum['current']++;
                }
                if ($p['status'] === 'overdue') {
                    $sum['overdue']++;
                }
                if ($p['counts_current'] && $p['expires_on'] !== null && $p['expires_on'] >= $today && $p['expires_on'] <= $in60) {
                    $sum['expiring_60']++;
                }
            }
            $out[] = self::toStatus($p);
        }
        $sum['pct'] = PairRules::pct($sum['current'], $sum['required']);
        return ['person' => Directory::ref($person) + ['eligible' => $person['eligible']], 'pairs' => $out, 'summary' => $sum];
    }

    /**
     * S15: {course_id => {required, current, overdue}} over required, non-waived pairs in scope
     * (every requested id is present, zeros when nobody needs it).
     *
     * @param list<int> $courseIds
     * @return array<int, array{required:int, current:int, overdue:int}>
     */
    public function courseCounts(array $courseIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $courseIds), static fn($i) => $i > 0)));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['required' => 0, 'current' => 0, 'overdue' => 0];
        }
        if ($ids === []) {
            return $out;
        }
        foreach ($this->pairs(['course_ids' => $ids]) as $p) {
            if (!$p['in_denominator'] || !isset($out[$p['course_id']])) {
                continue;
            }
            $out[$p['course_id']]['required']++;
            if ($p['counts_current']) {
                $out[$p['course_id']]['current']++;
            }
            if ($p['status'] === 'overdue') {
                $out[$p['course_id']]['overdue']++;
            }
        }
        return $out;
    }

    /** The PairStatus (§4.1) subset of a PairRow. */
    public static function toStatus(array $p): array
    {
        return [
            'course' => $p['course'],
            'required' => $p['required'],
            'status' => $p['status'],
            'label' => $p['label'],
            'due_on' => $p['due_on'],
            'expires_on' => $p['expires_on'],
            'days_overdue' => $p['days_overdue'],
            'lapsed' => !empty($p['lapsed']),
            'assignment_id' => $p['assignment_id'],
            'completion_id' => $p['completion_id'],
            'cert_number' => $p['cert_number'],
            'anchor' => $p['anchor'],
        ];
    }

    // ------------------------------------------------------------------------------------------

    /**
     * @param array<int, array> $people eligible persons keyed by contact id
     * @param list<int>|null $courseFilter
     * @return list<array> PairRows, ascending (contact_id, course_id)
     */
    private function compute(array $people, ?array $courseFilter, bool $requiredOnly): array
    {
        $db = $this->c->db;
        $today = $this->today();
        ksort($people);
        $ids = array_keys($people);
        $filter = $courseFilter === null ? null : array_fill_keys(array_map('intval', $courseFilter), true);

        $matcher = new RuleMatcher(RuleMatcher::loadRules($db, true));
        $desired = [];
        foreach ($people as $cid => $p) {
            foreach ($matcher->desiredFor($p, $today) as $k => $d) {
                if ($filter === null || isset($filter[$k])) {
                    $desired[$cid][$k] = $d;
                }
            }
        }
        $open = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Db::all($db, 'SELECT ' . AssignmentStore::COLUMNS . " FROM training_assignments WHERE tassign_status = 'open'
                    AND tassign_contact_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', str_repeat('i', count($chunk)), $chunk) as $r) {
                $a = AssignmentStore::normalize($r);
                if ($filter === null || isset($filter[$a['course_id']])) {
                    $open[$a['contact_id']][$a['course_id']] = $a;
                }
            }
        }
        $courseIds = [];
        $since = [];
        foreach ($people as $cid => $_) {
            foreach ($desired[$cid] ?? [] as $k => $d) {
                $courseIds[$k] = $k;
                if ($d['onboarding_since'] !== null) {
                    $since[$cid][$k] = $d['onboarding_since'];
                }
            }
            foreach ($open[$cid] ?? [] as $k => $o) {
                $courseIds[$k] = $k;
                if (!isset($desired[$cid][$k]) && $o['onboarding_from_on'] !== null) {
                    $since[$cid][$k] = $o['onboarding_from_on'];
                }
            }
        }
        if ($courseIds === []) {
            return [];
        }
        $courses = CourseCards::load($db, array_values($courseIds));
        $facts = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $facts += RecordFacts::load($db, $chunk, array_values($courseIds), false, $since, $this->s, $today);
        }

        $rows = [];
        foreach ($people as $cid => $p) {
            $ks = array_unique(array_merge(array_keys($desired[$cid] ?? []), array_keys($open[$cid] ?? [])));
            sort($ks);
            foreach ($ks as $k) {
                $d = $desired[$cid][$k] ?? null;
                $o = $open[$cid][$k] ?? null;
                $required = $d !== null ? (bool) $d['required'] : (bool) $o['required'];
                if ($requiredOnly && !$required) {
                    continue;
                }
                $f = $facts[$cid][$k] ?? RecordFacts::none();
                $st = PairRules::pairStatus($d, $o, $f, $today, $this->s);
                $course = $courses[$k] ?? null;
                $L = $f['latest'];
                $rows[] = [
                    'contact_id' => $cid,
                    'client_id' => (int) $p['client_id'],
                    'course_id' => $k,
                    'course' => $course !== null ? CourseCards::brief($course, true)
                        : ['id' => $k, 'name' => 'Course #' . $k, 'kind' => 'training', 'code' => null, 'is_qualification' => false],
                    'required' => $required,
                    'status' => $st['status'],
                    'label' => $st['label'],
                    'due_on' => $st['due_on'],
                    'expires_on' => $st['expires_on'],
                    'days_overdue' => $st['days_overdue'],
                    'lapsed' => $st['lapsed'],
                    'assignment_id' => $o['id'] ?? null,
                    'completion_id' => $L['completion_id'] ?? null,
                    'cert_number' => $L['cert_number'] ?? null,
                    'anchor' => $st['anchor'],
                    'counts_current' => $st['counts_current'],
                    'in_denominator' => $st['in_denominator'],
                ];
            }
        }
        return $rows;
    }

    private function today(): string
    {
        return $this->today ??= Clock::todayLocal();
    }
}
