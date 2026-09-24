<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Compliance\PairRules;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashSpecs;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\RowHasher;
use ITFlow\Training\People\Scope;

/**
 * Read side of the records engine (Phase 2 spec §3.5, §4.1): the records log and the record
 * detail (evidence page). Read-only; never authorizes beyond the Scope it is handed, which the
 * Action built with Scope::forCtx (spec §0 #2).
 *
 * Scope is the person's CURRENT department (contacts.contact_client_id), like every other
 * Training list. A completion whose contact row no longer exists (contacts have no FKs, plan A8)
 * is visible only to an all-departments scope.
 *
 *   Completion = {id, person:{contact_id, name, current_name}, course:{id, name, code, kind},
 *     revision:{id,number}|null, method, proof, strength, strength_label, completed_on, trained_on,
 *     evaluated_on, expires_on, language, score_pct, pass_mark_pct, attempts_used, duration_minutes,
 *     trainer_name, evaluator_name, external:{issuer,ref}|null, evidence:{media_id,url,mime,original_name}|null,
 *     cert_number, cert_status, cert_status_label, revoked_reason, voided:{at,by_name,reason}|null,
 *     recorded_at, recorded_by_name, notes, assignment_id, session_id, evaluation_id, certificate_url}
 *   CompletionDetail = Completion + {hash_ok, events:[{seq,type,at,actor}],
 *     components:{session:{…}|null, evaluation:{…, checklist}|null}, verify_url}
 */
final class CompletionView
{
    public const PAGE_SIZE = 50;
    public const METHODS = CompletionService::METHODS;

    /** Explicit column list (hashed table: never a star select). */
    private const COLS = 'tc.completion_id, tc.completion_contact_id, tc.completion_course_id, tc.completion_course_kind, tc.completion_revision_id,
        tc.completion_assignment_id, tc.completion_method, tc.completion_proof, tc.completion_completed_on, tc.completion_trained_on,
        tc.completion_evaluated_on, tc.completion_expires_on, tc.completion_language, tc.completion_score_pct, tc.completion_pass_mark_pct,
        tc.completion_attempts_used, tc.completion_duration_minutes, tc.completion_tsession_id, tc.completion_tattendee_id,
        tc.completion_evaluation_id, tc.completion_trainer_name, tc.completion_evaluator_name, tc.completion_external_issuer,
        tc.completion_external_ref, tc.completion_evidence_media_id, tc.completion_recorded_by_user_id, tc.completion_notes,
        tc.completion_cert_number, tc.completion_snap_contact_name, tc.completion_snap_client_id, tc.completion_snap_course_name,
        tc.completion_snap_course_code, tc.completion_snap_revision_number, tc.completion_supersedes_id, tc.completion_recorded_at_utc,
        c.contact_name AS cur_contact_name, c.contact_client_id AS cur_client_id,
        v.cvoid_id, v.cvoid_reason, v.cvoid_by_user_id, v.cvoid_at_utc';

    private const FROM = 'training_completions tc
        LEFT JOIN contacts c ON c.contact_id = tc.completion_contact_id
        LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = tc.completion_id';

    private RecordsSettings $settings;

    public function __construct(private readonly Ctx $c, ?RecordsSettings $settings = null)
    {
        $this->settings = $settings ?? RecordsSettings::fromDb($c->db);
    }

    /**
     * The records log. $f: q, course_id, client_id (current department; 0 = none), contact_id,
     * method, strength (A-E), voided (include|exclude|only), from, to (completed_on), page.
     *
     * @return array{rows:list<array>, total:int, page:int, page_size:int}
     */
    public function list(array $f, Scope $s): array
    {
        $page = max(1, (int) ($f['page'] ?? 1));
        if ($s->isNone()) {
            return ['rows' => [], 'total' => 0, 'page' => $page, 'page_size' => self::PAGE_SIZE];
        }
        [$scopeSql, $types, $params] = $s->sqlIn('c.contact_client_id');
        $where = '1=1' . $scopeSql;

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 100, 'UTF-8'), '%_\\') . '%';
            $where .= ' AND (tc.completion_snap_contact_name LIKE ? OR c.contact_name LIKE ? OR tc.completion_cert_number LIKE ?'
                . ' OR tc.completion_snap_course_name LIKE ? OR tc.completion_external_ref LIKE ?)';
            $types .= 'sssss';
            array_push($params, $like, $like, $like, $like, $like);
        }
        foreach (['course_id' => 'tc.completion_course_id', 'contact_id' => 'tc.completion_contact_id'] as $k => $col) {
            if (isset($f[$k]) && (int) $f[$k] > 0) {
                $where .= " AND $col = ?";
                $types .= 'i';
                $params[] = (int) $f[$k];
            }
        }
        if (isset($f['client_id']) && $f['client_id'] !== null && $f['client_id'] !== '') {
            $where .= ' AND COALESCE(c.contact_client_id, tc.completion_snap_client_id) = ?';
            $types .= 'i';
            $params[] = max(0, (int) $f['client_id']);
        }
        if (in_array($f['method'] ?? null, self::METHODS, true)) {
            $where .= ' AND tc.completion_method = ?';
            $types .= 's';
            $params[] = (string) $f['method'];
        }
        if (in_array($f['strength'] ?? null, array_keys(EvidenceStrength::LABELS), true)) {
            $where .= ' AND ' . EvidenceStrength::sqlCase('tc.completion_method', 'tc.completion_proof') . ' = ?';
            $types .= 's';
            $params[] = (string) $f['strength'];
        }
        $voided = self::voidedFilter($f['voided'] ?? null);
        if ($voided === 'exclude') {
            $where .= ' AND v.cvoid_id IS NULL';
        } elseif ($voided === 'only') {
            $where .= ' AND v.cvoid_id IS NOT NULL';
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = $f[$k] ?? null;
            if (is_string($d) && Clock::isYmd($d)) {
                $where .= " AND tc.completion_completed_on $op ?";
                $types .= 's';
                $params[] = $d;
            }
        }

        $total = (int) (Db::one($this->c->db, 'SELECT COUNT(*) AS n FROM ' . self::FROM . " WHERE $where", $types, $params)['n'] ?? 0);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = Db::all($this->c->db, 'SELECT ' . self::COLS . ' FROM ' . self::FROM . " WHERE $where
            ORDER BY tc.completion_completed_on DESC, tc.completion_id DESC LIMIT " . self::PAGE_SIZE . " OFFSET $offset", $types, $params);
        return ['rows' => $this->shapeMany($rows), 'total' => $total, 'page' => $page, 'page_size' => self::PAGE_SIZE];
    }

    /** CompletionDetail; 404 when missing or the person is out of scope. */
    public function detail(int $id, Scope $s): array
    {
        $row = $this->load($id, $s);
        $out = $this->shapeMany([$row])[0];
        $db = $this->c->db;

        // Row hashes: re-read through the text protocol (what the verifier sees) and re-hash.
        $out['hash_ok'] = self::rowHashOk($db, 'training_completions', 'completion_id', $id)
            && ($row['cvoid_id'] === null || self::rowHashOk($db, 'training_completion_voids', 'cvoid_id', (int) $row['cvoid_id']));

        $tok = Db::one($db, 'SELECT certtok_id FROM training_cert_tokens WHERE certtok_completion_id = ?', 'i', [$id]);
        $out['events'] = $this->events($id, $row['cvoid_id'] === null ? null : (int) $row['cvoid_id'],
            $tok === null ? null : (int) $tok['certtok_id'], $row['completion_assignment_id'] === null ? null : (int) $row['completion_assignment_id']);

        $out['components'] = [
            'session' => $this->sessionComponent($row),
            'evaluation' => $this->evaluationComponent($row),
        ];

        $out['verify_url'] = null;
        if ($tok !== null) {
            try {
                $token = CertIssuer::reprint($db, $id, CertSecret::fromGlobals());
                $out['verify_url'] = $token === null ? null : CertIssuer::verifyUrl($this->c->baseUrl, $token);
            } catch (\RuntimeException $e) {
                error_log('Training CompletionView: verify link unavailable: ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * The completion row (COLS) when it exists and its person is in scope, else 404.
     *
     * @return array<string, mixed>
     */
    public function load(int $id, Scope $s): array
    {
        $row = $id > 0 ? Db::one($this->c->db, 'SELECT ' . self::COLS . ' FROM ' . self::FROM . ' WHERE tc.completion_id = ?', 'i', [$id]) : null;
        if ($row === null || !self::inScope($row, $s)) {
            throw ApiException::notFound('That record was not found.');
        }
        return $row;
    }

    /** @param list<array> $rows COLS rows */
    public function shapeMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $db = $this->c->db;
        $today = Clock::todayLocal();
        $rr = CourseFacts::retrainRevisions($db, array_map(static fn($r) => (int) $r['completion_course_id'], $rows));
        $userIds = [];
        $mediaIds = [];
        foreach ($rows as $r) {
            $userIds[] = (int) ($r['completion_recorded_by_user_id'] ?? 0);
            $userIds[] = (int) ($r['cvoid_by_user_id'] ?? 0);
            if ($r['completion_evidence_media_id'] !== null) {
                $mediaIds[] = (int) $r['completion_evidence_media_id'];
            }
        }
        $users = PersonRefs::userNames($db, $userIds);
        $media = self::evidenceRefs($db, $mediaIds);

        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->shape($r, $rr[(int) $r['completion_course_id']] ?? null, $users, $media, $today);
        }
        return $out;
    }

    /** voided filter value: include (default) | exclude | only; '1'/'true' => only, '0'/'false' => exclude. */
    public static function voidedFilter(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'only' : 'exclude';
        }
        return match (is_string($v) || is_int($v) ? (string) $v : '') {
            'exclude', '0', 'false' => 'exclude',
            'only', '1', 'true' => 'only',
            default => 'include',
        };
    }

    /** Does the stored row re-hash to its stored hash? (text-protocol re-read, ids interpolated as ints) */
    public static function rowHashOk(\mysqli $db, string $table, string $idCol, int $id): bool
    {
        if (preg_match('/^[a-z_]+$/D', $table . $idCol) !== 1) {
            throw new \InvalidArgumentException('CompletionView::rowHashOk: bad identifier');
        }
        $meta = HashSpecs::meta($table);
        Db::ensureUtf8mb4($db);
        $res = $db->query('SELECT ' . implode(', ', HashSpecs::selectColumns($table)) . " FROM $table WHERE $idCol = " . (int) $id);
        $row = $res->fetch_assoc();
        $res->free();
        if (!$row) {
            return false;
        }
        try {
            return hash_equals((string) $row[$meta['hash']], RowHasher::hash($table, $row));
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------------------------------

    private function shape(array $r, ?array $rr, array $users, array $media, string $today): array
    {
        $id = (int) $r['completion_id'];
        $method = (string) $r['completion_method'];
        $proof = (string) $r['completion_proof'];
        $grade = EvidenceStrength::grade($method, $proof);
        $voidedAt = $r['cvoid_at_utc'] === null ? null : (string) $r['cvoid_at_utc'];
        $st = PairRules::certStatus([
            'completion_id' => $id,
            'completed_on' => (string) $r['completion_completed_on'],
            'expires_on' => $r['completion_expires_on'] === null ? null : (string) $r['completion_expires_on'],
            'revision_number' => $r['completion_snap_revision_number'] === null ? null : (int) $r['completion_snap_revision_number'],
            'supersedes_id' => $r['completion_supersedes_id'] === null ? null : (int) $r['completion_supersedes_id'],
            'voided_at_utc' => $voidedAt,
        ], $rr, $today, $this->settings->dueSoonDays);
        $int = static fn($v): ?int => $v === null ? null : (int) $v;
        $str = static fn($v): ?string => ($v === null || $v === '') ? null : (string) $v;
        $mediaId = $int($r['completion_evidence_media_id']);
        $recBy = (int) ($r['completion_recorded_by_user_id'] ?? 0);
        $voidBy = (int) ($r['cvoid_by_user_id'] ?? 0);

        return [
            'id' => $id,
            'person' => [
                'contact_id' => (int) $r['completion_contact_id'],
                'name' => (string) $r['completion_snap_contact_name'],
                'current_name' => $str($r['cur_contact_name'] ?? null),
            ],
            'course' => [
                'id' => (int) $r['completion_course_id'],
                'name' => (string) $r['completion_snap_course_name'],
                'code' => $str($r['completion_snap_course_code']),
                'kind' => (string) $r['completion_course_kind'],
            ],
            'revision' => $r['completion_revision_id'] === null ? null
                : ['id' => (int) $r['completion_revision_id'], 'number' => $int($r['completion_snap_revision_number'])],
            'method' => $method,
            'proof' => $proof,
            'strength' => $grade,
            'strength_label' => EvidenceStrength::label($grade, $method),
            'completed_on' => (string) $r['completion_completed_on'],
            'trained_on' => $str($r['completion_trained_on']),
            'evaluated_on' => $str($r['completion_evaluated_on']),
            'expires_on' => $str($r['completion_expires_on']),
            'language' => (string) $r['completion_language'],
            'score_pct' => $str($r['completion_score_pct']),
            'pass_mark_pct' => $int($r['completion_pass_mark_pct']),
            'attempts_used' => $int($r['completion_attempts_used']),
            'duration_minutes' => $int($r['completion_duration_minutes']),
            'trainer_name' => $str($r['completion_trainer_name']),
            'evaluator_name' => $str($r['completion_evaluator_name']),
            'external' => in_array($method, ['external', 'legacy_paper'], true)
                ? ['issuer' => $str($r['completion_external_issuer']), 'ref' => $str($r['completion_external_ref'])] : null,
            'evidence' => $mediaId === null ? null : ($media[$mediaId] ?? null),
            'cert_number' => $str($r['completion_cert_number']),
            'cert_status' => (string) $st['status'],
            'cert_status_label' => (string) $st['label'],
            'revoked_reason' => $st['reason'],
            'voided' => $voidedAt === null ? null : [
                'at' => Clock::toIso($voidedAt, true),
                'by_name' => $voidBy > 0 ? ($users[$voidBy] ?? null) : null,
                'reason' => (string) $r['cvoid_reason'],
            ],
            'recorded_at' => Clock::toIso((string) $r['completion_recorded_at_utc'], true),
            'recorded_by_name' => $recBy > 0 ? ($users[$recBy] ?? null) : null,
            'notes' => $str($r['completion_notes']),
            'assignment_id' => $int($r['completion_assignment_id']),
            'session_id' => $int($r['completion_tsession_id']),
            'evaluation_id' => $int($r['completion_evaluation_id']),
            'certificate_url' => '/agent/training_certificate.php?id=' . $id,
        ];
    }

    private static function inScope(array $row, Scope $s): bool
    {
        if ($s->isAll()) {
            return true;
        }
        return $row['cur_client_id'] !== null && $s->allows((int) $row['cur_client_id']);
    }

    /** @return array<int, array{media_id:int, url:string, mime:string, original_name:?string}> evidence rows only */
    private static function evidenceRefs(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all($db, "SELECT media_id, media_mime, media_original_name FROM training_media WHERE media_kind = 'evidence' AND media_id IN ($in)",
                str_repeat('i', count($chunk)), $chunk) as $m) {
                $out[(int) $m['media_id']] = [
                    'media_id' => (int) $m['media_id'],
                    'url' => EvidenceStore::url((int) $m['media_id']),
                    'mime' => (string) $m['media_mime'],
                    'original_name' => $m['media_original_name'] === null ? null : (string) $m['media_original_name'],
                ];
            }
        }
        return $out;
    }

    /** Ledger events of this record: the completion, its token, its void and the assignment it closed. */
    private function events(int $id, ?int $voidId, ?int $tokenId, ?int $assignmentId): array
    {
        $sql = "SELECT tevent_seq, tevent_type, tevent_at_utc, tevent_actor_type, tevent_actor_user_id, tevent_kiosk_id, tevent_payload_json
            FROM training_events WHERE (tevent_entity_type = 'completion' AND tevent_entity_id = ?)";
        $types = 'i';
        $params = [$id];
        if ($voidId !== null) {
            $sql .= " OR (tevent_entity_type = 'completion_void' AND tevent_entity_id = ?)";
            $types .= 'i';
            $params[] = $voidId;
        }
        if ($tokenId !== null) {
            $sql .= " OR (tevent_entity_type = 'cert_token' AND tevent_entity_id = ?)";
            $types .= 'i';
            $params[] = $tokenId;
        }
        if ($assignmentId !== null) {
            $sql .= " OR (tevent_entity_type = 'assignment' AND tevent_entity_id = ? AND tevent_type = 'assignment.completed')";
            $types .= 'i';
            $params[] = $assignmentId;
        }
        $rows = Db::all($this->c->db, $sql . ' ORDER BY tevent_seq', $types, $params);
        $users = PersonRefs::userNames($this->c->db, array_map(static fn($e) => (int) ($e['tevent_actor_user_id'] ?? 0), $rows));
        $out = [];
        foreach ($rows as $e) {
            if ($e['tevent_type'] === 'assignment.completed') {
                // Only the close caused by THIS record (an assignment closes once, but be exact).
                $p = json_decode((string) $e['tevent_payload_json'], true);
                if (!is_array($p) || (int) ($p['completion_id'] ?? 0) !== $id) {
                    continue;
                }
            }
            $uid = (int) ($e['tevent_actor_user_id'] ?? 0);
            $actor = match ((string) $e['tevent_actor_type']) {
                'user' => $users[$uid] ?? ('User #' . $uid),
                'kiosk' => 'Kiosk' . ($e['tevent_kiosk_id'] !== null ? ' #' . (int) $e['tevent_kiosk_id'] : ''),
                'contact' => 'Employee',
                default => 'System',
            };
            $out[] = [
                'seq' => (int) $e['tevent_seq'],
                'type' => (string) $e['tevent_type'],
                'at' => Clock::toIso((string) $e['tevent_at_utc'], true),
                'actor' => $actor,
            ];
        }
        return $out;
    }

    private function sessionComponent(array $row): ?array
    {
        $sid = $row['completion_tsession_id'] === null ? null : (int) $row['completion_tsession_id'];
        if ($sid === null) {
            return null;
        }
        $s = Db::one($this->c->db, 'SELECT tsession_id, tsession_held_on, tsession_topic, tsession_trainer_name, tsession_location, tsession_status,
                tsession_sha256 FROM training_sessions WHERE tsession_id = ?', 'i', [$sid]);
        if ($s === null) {
            return null;
        }
        $a = null;
        if ($row['completion_tattendee_id'] !== null) {
            $a = Db::one($this->c->db, 'SELECT tattendee_attendance, tattendee_proof, tattendee_practical FROM training_session_attendees WHERE tattendee_id = ?',
                'i', [(int) $row['completion_tattendee_id']]);
        }
        return [
            'id' => (int) $s['tsession_id'],
            'held_on' => (string) $s['tsession_held_on'],
            'topic' => $s['tsession_topic'],
            'location' => $s['tsession_location'],
            'trainer_name' => (string) $s['tsession_trainer_name'],
            'status' => (string) $s['tsession_status'],
            'sha12' => $s['tsession_sha256'] === null ? null : substr((string) $s['tsession_sha256'], 0, 12),
            'attendance' => $a['tattendee_attendance'] ?? null,
            'proof' => $a['tattendee_proof'] ?? null,
            'practical' => $a['tattendee_practical'] ?? null,
        ];
    }

    private function evaluationComponent(array $row): ?array
    {
        $eid = $row['completion_evaluation_id'] === null ? null : (int) $row['completion_evaluation_id'];
        if ($eid === null) {
            return null;
        }
        $e = Db::one($this->c->db, 'SELECT evaluation_id, evaluation_channel, evaluation_evaluator_name, evaluation_evaluated_on, evaluation_result,
                evaluation_equipment, evaluation_checklist_json, evaluation_proof, evaluation_evidence_media_id, evaluation_tsession_id
            FROM training_evaluations WHERE evaluation_id = ?', 'i', [$eid]);
        if ($e === null) {
            return null;
        }
        $checklist = [];
        $raw = $e['evaluation_checklist_json'] === null ? null : json_decode((string) $e['evaluation_checklist_json'], true);
        foreach (is_array($raw) ? $raw : [] as $it) {
            if (is_array($it) && is_string($it['item'] ?? null)) {
                $checklist[] = ['item' => $it['item'], 'critical' => (bool) ($it['critical'] ?? false), 'result' => (string) ($it['result'] ?? '')];
            }
        }
        return [
            'id' => (int) $e['evaluation_id'],
            'channel' => (string) $e['evaluation_channel'],
            'evaluator_name' => (string) $e['evaluation_evaluator_name'],
            'evaluated_on' => (string) $e['evaluation_evaluated_on'],
            'result' => (string) $e['evaluation_result'],
            'equipment' => $e['evaluation_equipment'],
            'proof' => (string) $e['evaluation_proof'],
            'session_id' => $e['evaluation_tsession_id'] === null ? null : (int) $e['evaluation_tsession_id'],
            'evidence' => EvidenceStore::ref($this->c->db, $e['evaluation_evidence_media_id'] === null ? null : (int) $e['evaluation_evidence_media_id']),
            'checklist' => $checklist,
        ];
    }
}
