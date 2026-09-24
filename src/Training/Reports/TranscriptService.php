<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Compliance\ComplianceService;
use ITFlow\Training\Compliance\PairRules;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Directory;
use ITFlow\Training\People\Scope;

/**
 * One person's training transcript (M10, mockup Admin-Transcript; also the data source of
 * Phase 5's transcript PDF).
 *
 * The caller has already authorized the person (Access guard + Scope::assertContact). The
 * transcript reads:
 *   - person facts through People\Directory (archived people included);
 *   - pair status and the compliance summary through ComplianceService::personStatus();
 *   - every completion of the person, with its void, straight from the records tables
 *     (explicit column lists; the hashed rows are never modified);
 *   - certificate status through Compliance\PairRules::certStatus() - never stored.
 *
 * Qualifications = the latest record per training course (latest non-voided by completed_on
 * then id; a course whose every record is voided shows its latest voided record as revoked).
 * Document acknowledgments are not qualifications; they appear in History.
 */
final class TranscriptService
{
    private Scope $scope;
    private RecordsSettings $settings;

    public function __construct(private readonly Ctx $c, ?Scope $scope = null, ?RecordsSettings $settings = null)
    {
        $this->scope = $scope ?? Scope::forCtx($c);
        $this->settings = $settings ?? RecordsSettings::fromDb($c->db);
    }

    /** @return array<string, mixed> */
    public function build(int $contactId, bool $withStatus = true): array
    {
        $db = $this->c->db;
        $today = Clock::todayLocal();
        $person = $this->person($contactId);

        $status = ['pairs' => [], 'summary' => ['required' => 0, 'current' => 0, 'overdue' => 0, 'expiring_60' => 0, 'pct' => null]];
        if ($withStatus) {
            try {
                $ps = (new ComplianceService($this->c, $this->scope, $this->settings))->personStatus($contactId);
                $status['pairs'] = is_array($ps['pairs'] ?? null) ? $ps['pairs'] : [];
                $status['summary'] = array_merge($status['summary'], is_array($ps['summary'] ?? null) ? $ps['summary'] : []);
            } catch (\ITFlow\Training\Api\ApiException $e) {
                // Out of scope for the compliance engine (e.g. an archived or ineligible person):
                // the records still render; the compliance chips read "not on the roster".
                $status['summary']['pct'] = null;
            }
        }

        $records = $this->records($contactId, $today);
        $quals = [];
        foreach ($records as $r) {
            if ($r['course']['kind'] !== 'training') {
                continue;
            }
            $cid = $r['course']['id'];
            if (!isset($quals[$cid]) || ($quals[$cid]['voided'] !== null && $r['voided'] === null)) {
                // records are newest first: the first non-voided one per course wins
                $quals[$cid] = $r;
            }
        }
        $quals = array_values($quals);
        usort($quals, static fn($a, $b) => strcasecmp($a['course']['name'], $b['course']['name']));

        $soon60 = Clock::addDays($today, 60);
        $valid = 0;
        $onFile = 0;
        $exp60 = [];
        foreach ($quals as $q) {
            if ($q['voided'] !== null) {
                continue;
            }
            $onFile++;
            if (in_array($q['status'], ['valid', 'expiring'], true)) {
                $valid++;
                if ($q['expires_on'] !== null && $q['expires_on'] >= $today && $q['expires_on'] <= $soon60) {
                    $exp60[] = $q;
                }
            }
        }
        usort($exp60, static fn($a, $b) => strcmp($a['expires_on'], $b['expires_on']));

        $year = substr($today, 0, 4);
        $minutes = 0;
        $last = null;
        foreach ($records as $r) {
            if ($r['voided'] !== null) {
                continue;
            }
            if (substr($r['completed_on'], 0, 4) === $year) {
                $minutes += (int) ($r['duration_minutes'] ?? 0);
            }
            if ($r['course']['kind'] === 'training' && ($last === null || $r['completed_on'] > $last)) {
                $last = $r['completed_on'];
            }
        }

        $assignments = $this->assignments($contactId, $today);
        $open = array_values(array_filter($assignments, static fn($a) => $a['status'] === 'open'));
        $openOverdue = count(array_filter($open, static fn($a) => $a['display_status'] === 'overdue'));

        return [
            'contact' => $person,
            'as_of' => $today,
            'generated_at' => Clock::toIso(Clock::nowUtc(), true),
            'summary' => [
                'pct' => isset($status['summary']['pct']) ? (int) $status['summary']['pct'] : null,
                'required' => (int) $status['summary']['required'],
                'current' => (int) $status['summary']['current'],
                'overdue' => (int) $status['summary']['overdue'],
                'expiring_60' => count($exp60),
                'first_expiring' => $exp60 !== [] ? ['course' => $exp60[0]['course'], 'expires_on' => $exp60[0]['expires_on']] : null,
                'valid' => $valid,
                'on_file' => $onFile,
                'open_assignments' => count($open),
                'open_overdue' => $openOverdue,
                'hours_year' => $year,
                'hours' => round($minutes / 60, 1),
                'last_trained_on' => $last,
            ],
            'pairs' => $status['pairs'],
            'qualifications' => $quals,
            'history' => $records,
            'assignments' => $assignments,
            'open_assignments' => $open,
            'achievements' => $this->achievements($contactId),
            'legend' => Labels::STRENGTH,
            'ledger' => Lookup::ledgerStamp($db),
        ];
    }

    /** @return array<string, mixed> */
    private function person(int $contactId): array
    {
        $db = $this->c->db;
        $d = [];
        try {
            $rows = Directory::load($db, Scope::all(), [$contactId], true);
            $d = $rows[$contactId] ?? (is_array(reset($rows)) ? reset($rows) : []);
        } catch (\Throwable $e) {
            error_log('Training transcript: Directory::load failed: ' . $e->getMessage());
        }
        $row = Db::one($db, 'SELECT c.contact_id, c.contact_name, c.contact_title, c.contact_client_id, c.contact_employee_id,
                c.contact_start_date, c.contact_manager_id, c.contact_archived_at, c.contact_user_id, cl.client_name, m.contact_name AS manager_name
            FROM contacts c
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            LEFT JOIN contacts m ON m.contact_id = c.contact_manager_id
            WHERE c.contact_id = ?', 'i', [$contactId]) ?? [];
        $name = (string) ($d['name'] ?? $row['contact_name'] ?? ('Contact #' . $contactId));
        $clientId = (int) ($d['client_id'] ?? $row['contact_client_id'] ?? 0);
        $hire = $d['hire_date'] ?? $row['contact_start_date'] ?? null;
        return [
            'id' => $contactId,
            'name' => $name,
            'initials' => Labels::initials($name),
            'title' => ($d['title'] ?? $row['contact_title'] ?? null) ?: null,
            'department' => $clientId > 0 ? ['id' => $clientId, 'name' => (string) ($d['client_name'] ?? $row['client_name'] ?? '')] : null,
            'job' => ($d['job_name'] ?? null) ?: null,
            'location' => ($d['location_name'] ?? null) ?: null,
            'employee_no' => ($d['employee_no'] ?? $row['contact_employee_id'] ?? null) ?: null,
            'hire_date' => $hire ?: null,
            'manager' => !empty($row['contact_manager_id']) && !empty($row['manager_name'])
                ? ['id' => (int) $row['contact_manager_id'], 'name' => (string) $row['manager_name']] : null,
            'archived' => !empty($row['contact_archived_at']),
            'eligible' => isset($d['eligible']) ? (bool) $d['eligible'] : null,
            'user_id' => isset($row['contact_user_id']) ? (int) $row['contact_user_id'] : 0,
        ];
    }

    /**
     * Every completion of the person, newest first (completed_on, then id), with certificate
     * status and the void when there is one.
     *
     * @return list<array<string, mixed>>
     */
    private function records(int $contactId, string $today): array
    {
        $db = $this->c->db;
        $rows = Db::all($db, "SELECT tc.completion_id, tc.completion_course_id, tc.completion_course_kind, tc.completion_revision_id,
                tc.completion_method, tc.completion_proof, tc.completion_completed_on, tc.completion_trained_on,
                tc.completion_evaluated_on, tc.completion_expires_on, tc.completion_language, tc.completion_score_pct,
                tc.completion_pass_mark_pct, tc.completion_duration_minutes, tc.completion_trainer_name,
                tc.completion_evaluator_name, tc.completion_external_issuer, tc.completion_external_ref,
                tc.completion_cert_number, tc.completion_snap_course_name, tc.completion_snap_course_code,
                tc.completion_snap_revision_number, tc.completion_supersedes_id, tc.completion_recorded_at_utc,
                tc.completion_tsession_id, tc.completion_evaluation_id,
                co.course_name, co.course_code, co.course_validity_months, co.course_archived_at,
                v.cvoid_reason, v.cvoid_at_utc, vu.user_name AS void_by
            FROM training_completions tc
            LEFT JOIN training_courses co ON co.course_id = tc.completion_course_id
            LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = tc.completion_id
            LEFT JOIN users vu ON vu.user_id = v.cvoid_by_user_id
            WHERE tc.completion_contact_id = ?
            ORDER BY tc.completion_completed_on DESC, tc.completion_id DESC", 'i', [$contactId]);
        $rr = Lookup::retrainRevisions($db, array_map(static fn($r) => (int) $r['completion_course_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::record($r, $rr[(int) $r['completion_course_id']] ?? null, $today, $this->settings->dueSoonDays);
        }
        return $out;
    }

    /** One completion row => the transcript record shape. */
    public static function record(array $r, ?array $rr, string $today, int $dueSoonDays): array
    {
        $method = (string) $r['completion_method'];
        $proof = (string) $r['completion_proof'];
        $grade = Labels::grade($method, $proof);
        $voidedAt = $r['cvoid_at_utc'] ?? null;
        $cert = PairRules::certStatus([
            'completion_id' => (int) $r['completion_id'],
            'completed_on' => (string) $r['completion_completed_on'],
            'expires_on' => $r['completion_expires_on'],
            'revision_number' => $r['completion_snap_revision_number'] !== null ? (int) $r['completion_snap_revision_number'] : null,
            'supersedes_id' => $r['completion_supersedes_id'] !== null ? (int) $r['completion_supersedes_id'] : null,
            'voided_at_utc' => $voidedAt,
        ], $rr, $today, $dueSoonDays);
        $external = in_array($method, ['external', 'legacy_paper'], true);
        $trainer = null;
        $trainerSub = null;
        if ($external) {
            $trainer = $r['completion_external_issuer'] ?: null;
            $trainerSub = $method === 'external' ? 'External card recorded' : 'Paper record on file';
        } elseif (!empty($r['completion_evaluator_name']) && $method === 'evaluation') {
            $trainer = (string) $r['completion_evaluator_name'];
            $trainerSub = 'Practical evaluated';
        } elseif (!empty($r['completion_trainer_name'])) {
            $trainer = (string) $r['completion_trainer_name'];
            $trainerSub = $method === 'blended' && !empty($r['completion_evaluator_name']) ? 'Practical evaluated' : ($method === 'session' ? 'Group session' : null);
        } elseif (!empty($r['completion_evaluator_name'])) {
            $trainer = (string) $r['completion_evaluator_name'];
            $trainerSub = 'Practical evaluated';
        }
        $name = (string) ($r['course_name'] ?? '') !== '' ? (string) $r['course_name'] : (string) $r['completion_snap_course_name'];
        $revNo = $r['completion_snap_revision_number'] !== null ? (int) $r['completion_snap_revision_number'] : null;
        return [
            'completion_id' => (int) $r['completion_id'],
            'course' => [
                'id' => (int) $r['completion_course_id'],
                'name' => $name,
                'code' => ($r['course_code'] ?? $r['completion_snap_course_code']) ?: null,
                'kind' => (string) $r['completion_course_kind'],
                'archived' => !empty($r['course_archived_at']),
            ],
            'snap_course_name' => (string) $r['completion_snap_course_name'],
            'method' => $method,
            'method_label' => Labels::methodLabel($method),
            'proof' => $proof,
            'grade' => $grade,
            'strength_label' => Labels::strengthLabel($grade, $method),
            'revision_number' => $revNo,
            'validity_label' => Labels::validity($r['course_validity_months'] !== null ? (int) $r['course_validity_months'] : null),
            'status' => (string) ($cert['status'] ?? 'valid'),
            'status_reason' => $cert['reason'] ?? null,
            'status_label' => (string) ($cert['label'] ?? ''),
            'score_pct' => $r['completion_score_pct'] !== null ? Labels::score((string) $r['completion_score_pct']) : null,
            'pass_mark_pct' => $r['completion_pass_mark_pct'] !== null ? (int) $r['completion_pass_mark_pct'] : null,
            'completed_on' => (string) $r['completion_completed_on'],
            'trained_on' => $r['completion_trained_on'] ?: null,
            'evaluated_on' => $r['completion_evaluated_on'] ?: null,
            'expires_on' => $r['completion_expires_on'] ?: null,
            'expires_days' => $r['completion_expires_on'] ? Labels::days($today, (string) $r['completion_expires_on']) : null,
            'duration_minutes' => $r['completion_duration_minutes'] !== null ? (int) $r['completion_duration_minutes'] : null,
            'language' => (string) $r['completion_language'],
            'trainer_or_evaluator' => $trainer,
            'trainer_sub' => $trainerSub,
            'external' => $external ? ['issuer' => $r['completion_external_issuer'], 'ref' => $r['completion_external_ref']] : null,
            'cert_number' => $r['completion_cert_number'] ?: null,
            'session_id' => $r['completion_tsession_id'] !== null ? (int) $r['completion_tsession_id'] : null,
            'evaluation_id' => $r['completion_evaluation_id'] !== null ? (int) $r['completion_evaluation_id'] : null,
            'recorded_at' => Clock::toIso((string) $r['completion_recorded_at_utc'], true),
            'voided' => $voidedAt !== null ? [
                'on' => Clock::localDate((string) $voidedAt),
                'at' => Clock::toIso((string) $voidedAt, true),
                'reason' => (string) $r['cvoid_reason'],
                'by' => $r['void_by'] ?? null,
            ] : null,
            // Revoked by a retrain revision: its number and the first day the record stopped
            // counting (PairRules: revoked once today > published_on + retrain_due_days).
            'rr' => $rr !== null && ($cert['reason'] ?? null) === 'retrain_required' ? [
                'revision_number' => $rr['revision_number'],
                'revoked_on' => Clock::addDays((string) $rr['published_on'], (int) $rr['retrain_due_days'] + 1),
            ] : null,
        ];
    }

    /**
     * Open assignments plus those closed in the last 12 months, newest due first within status.
     *
     * @return list<array<string, mixed>>
     */
    private function assignments(int $contactId, string $today): array
    {
        $db = $this->c->db;
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-12 months')->format('Y-m-d H:i:s.v');
        $rows = Db::all($db, "SELECT a.tassign_id, a.tassign_course_id, a.tassign_reason, a.tassign_anchor, a.tassign_requirement_id,
                a.tassign_required, a.tassign_due_on, a.tassign_original_due_on, a.tassign_status, a.tassign_waived_until,
                a.tassign_completion_id, a.tassign_created_at_utc, a.tassign_created_by, a.tassign_closed_at_utc,
                a.tassign_close_reason, a.tassign_close_note, a.tassign_reopened_count,
                co.course_name, co.course_code, co.course_kind, r.requirement_name, r.requirement_is_manual, u.user_name AS created_by_name
            FROM training_assignments a
            LEFT JOIN training_courses co ON co.course_id = a.tassign_course_id
            LEFT JOIN training_requirements r ON r.requirement_id = a.tassign_requirement_id
            LEFT JOIN users u ON u.user_id = a.tassign_created_by
            WHERE a.tassign_contact_id = ? AND (a.tassign_status = 'open' OR a.tassign_closed_at_utc >= ?)
            ORDER BY (a.tassign_status = 'open') DESC, a.tassign_due_on ASC, a.tassign_id DESC", 'is', [$contactId, $since]);
        $details = OverdueReport::assignmentDetails($db, array_map(static fn($r) => (int) $r['tassign_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['tassign_id'];
            $out[] = self::assignment($r, $details[$id] ?? null, $today, $this->settings->dueSoonDays);
        }
        return $out;
    }

    /** One assignment row => the Assignment shape of §4.1 (person omitted). */
    public static function assignment(array $r, ?array $detail, string $today, int $dueSoonDays): array
    {
        $status = (string) $r['tassign_status'];
        $due = (string) $r['tassign_due_on'];
        $display = $status;
        $daysOverdue = 0;
        if ($status === 'open') {
            if ($due < $today) {
                $display = 'overdue';
                $daysOverdue = Labels::days($due, $today);
            } elseif ($due <= Clock::addDays($today, $dueSoonDays)) {
                $display = 'due_soon';
            } else {
                $display = 'due';
            }
        }
        return [
            'id' => (int) $r['tassign_id'],
            'course' => ['id' => (int) $r['tassign_course_id'], 'name' => (string) ($r['course_name'] ?? ''),
                'code' => $r['course_code'] ?? null, 'kind' => (string) ($r['course_kind'] ?? 'training')],
            'reason' => (string) $r['tassign_reason'],
            'anchor' => (string) $r['tassign_anchor'],
            'anchor_label' => $detail['anchor_label'] ?? Labels::anchor((string) $r['tassign_anchor'], $r['requirement_name'] ?? null),
            'requirement' => $r['tassign_requirement_id'] !== null
                ? ['id' => (int) $r['tassign_requirement_id'], 'name' => $r['requirement_name'] ?? null, 'is_manual' => (bool) ($r['requirement_is_manual'] ?? false)]
                : null,
            'required' => (bool) $r['tassign_required'],
            'due_on' => $due,
            'original_due_on' => (string) $r['tassign_original_due_on'],
            'status' => $status,
            'display_status' => $display,
            'days_overdue' => $daysOverdue,
            'waived_until' => $r['tassign_waived_until'] ?: null,
            'completion_id' => $r['tassign_completion_id'] !== null ? (int) $r['tassign_completion_id'] : null,
            'created_at' => Clock::toIso((string) $r['tassign_created_at_utc'], true),
            'created_on' => Clock::localDate((string) $r['tassign_created_at_utc']),
            'created_by_name' => $r['created_by_name'] ?? null,
            'closed_at' => $r['tassign_closed_at_utc'] !== null ? Clock::toIso((string) $r['tassign_closed_at_utc'], true) : null,
            'close_reason' => $r['tassign_close_reason'] ?? null,
            'close_note' => $r['tassign_close_note'] ?? null,
            'reopened_count' => (int) $r['tassign_reopened_count'],
        ];
    }

    /** Phase 3 awards when that table exists; null otherwise (the tab is not rendered). */
    private function achievements(int $contactId): ?array
    {
        $db = $this->c->db;
        if (!Lookup::tableExists($db, 'training_achievement_awards')) {
            return null;
        }
        try {
            $rows = Db::all($db, 'SELECT taward_id, taward_snap_name, taward_snap_icon, taward_snap_color, taward_source, taward_reason, taward_awarded_at_utc
                FROM training_achievement_awards WHERE taward_contact_id = ? ORDER BY taward_awarded_at_utc DESC, taward_id DESC', 'i', [$contactId]);
        } catch (\mysqli_sql_exception $e) {
            error_log('Training transcript achievements: ' . $e->getMessage());
            return null;
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['taward_id'],
                'name' => (string) $r['taward_snap_name'],
                'icon' => (string) $r['taward_snap_icon'],
                'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $r['taward_snap_color']) ? (string) $r['taward_snap_color'] : null,
                'how' => (string) $r['taward_source'],
                'reason' => $r['taward_reason'],
                'awarded_on' => Clock::localDate((string) $r['taward_awarded_at_utc']),
            ];
        }
        return $out;
    }
}
