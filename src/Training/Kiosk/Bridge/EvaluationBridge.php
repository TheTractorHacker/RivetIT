<?php

namespace ITFlow\Training\Kiosk\Bridge;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashedInsert;
use ITFlow\Training\Core\RecordsMutex;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Records\CertSecret;
use ITFlow\Training\Records\CompletionService;

/**
 * Kiosk practical evaluations (P3 spec §3.7, C-P2-7; lane K5). The ONLY place trainer mode
 * writes Phase 2's training_evaluations.
 *
 * record() runs INSIDE the caller's Db::tx (EvaluateService::submit: role switch -> evaluator
 * signature -> this) in Phase 2's order: records mutex FIRST -> source key (FOR UPDATE) ->
 * HashedInsert evaluation (channel 'kiosk', proof 'evaluator_signed', both signature ids, the
 * run id, the kiosk id; no evidence scan - the two finger signatures and two PINs are the
 * evidence) -> on a pass, Records\CompletionService::tryIssueComponents(deferEvents), which
 * reads the online part through Phase 3's RunComponentSource on this connection. The events
 * (evaluation.recorded, completion events) are returned for the caller to append LAST;
 * afterCommit() runs Phase 2's completion afterCommit (listeners, awards, reconcile).
 *
 * The source key is 'kev:<handoff ksess id>': one evaluation per hand-off, so a double tap on
 * Submit returns the first result (duplicate:true) instead of recording twice.
 */
final class EvaluationBridge
{
    /** @var array<string, bool> */
    private static array $avail = [];

    public function __construct(private readonly Ctx $c, private readonly array $actor = [])
    {
    }

    public static function available(\mysqli $db): bool
    {
        $res = $db->query('SELECT DATABASE() AS d');
        $name = (string) ($res->fetch_assoc()['d'] ?? '');
        $res->free();
        if (isset(self::$avail[$name])) {
            return self::$avail[$name];
        }
        $ok = false;
        try {
            $row = Db::one($db, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
                ('training_evaluations', 'training_completions', 'training_cert_counters')");
            $ok = (int) ($row['n'] ?? 0) === 3;
        } catch (\Throwable $e) {
            error_log('Kiosk EvaluationBridge::available: ' . get_class($e));
            $ok = false;
        }
        return self::$avail[$name] = $ok;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$avail = [];
    }

    /**
     * INSIDE the caller's Db::tx. $e: source_key, contact_id, course_id, revision_id, run_id?,
     * evaluator_contact_id, evaluator_name, evaluated_on (local Y-m-d), result pass|fail, equipment?,
     * checklist (list of {item, critical, result}), notes?, evaluator_tsig_id, evaluatee_tsig_id, kiosk_id.
     *
     * @return array{evaluation_id:int, result:string, duplicate:bool, completion:?array{completion_id:int, cert_number:?string},
     *   pending:?string, events:list<array>, opaque:?array}
     */
    public function record(array $e): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('EvaluationBridge::record must run inside Db::tx');
        }
        $db = $this->c->db;
        $sourceKey = (string) $e['source_key'];
        if (preg_match('/^kev:[0-9]{1,11}$/D', $sourceKey) !== 1) {
            throw new \InvalidArgumentException('EvaluationBridge: bad source key');
        }
        $contactId = (int) $e['contact_id'];
        $courseId = (int) $e['course_id'];
        $result = (string) $e['result'];
        if (!in_array($result, ['pass', 'fail'], true)) {
            throw new \InvalidArgumentException('EvaluationBridge: bad result');
        }
        RecordsMutex::acquire($db);
        $hit = Db::one($db, 'SELECT evaluation_id, evaluation_contact_id, evaluation_course_id, evaluation_result FROM training_evaluations
            WHERE evaluation_source_key = ? FOR UPDATE', 's', [$sourceKey]);
        if ($hit !== null) {
            if ((int) $hit['evaluation_contact_id'] !== $contactId || (int) $hit['evaluation_course_id'] !== $courseId) {
                throw new ApiException(409, 'busy', 'That evaluation was already used. Start again.');
            }
            $done = Db::one($db, 'SELECT completion_id, completion_cert_number FROM training_completions WHERE completion_evaluation_id = ?
                ORDER BY completion_id LIMIT 1', 'i', [(int) $hit['evaluation_id']]);
            return ['evaluation_id' => (int) $hit['evaluation_id'], 'result' => (string) $hit['evaluation_result'], 'duplicate' => true,
                    'completion' => $done === null ? null : ['completion_id' => (int) $done['completion_id'],
                        'cert_number' => $done['completion_cert_number'] === null ? null : (string) $done['completion_cert_number']],
                    'pending' => null, 'events' => [], 'opaque' => null];
        }
        $checklist = [];
        foreach ($e['checklist'] as $row) {
            $checklist[] = ['item' => (string) $row['item'], 'critical' => (bool) $row['critical'], 'result' => (string) $row['result']];
        }
        $ins = HashedInsert::insert($db, 'training_evaluations', [
            'evaluation_source_key' => $sourceKey,
            'evaluation_contact_id' => (string) $contactId,
            'evaluation_course_id' => (string) $courseId,
            'evaluation_revision_id' => (string) (int) $e['revision_id'],
            'evaluation_run_id' => isset($e['run_id']) && $e['run_id'] !== null ? (string) (int) $e['run_id'] : null,
            'evaluation_tsession_id' => null,
            'evaluation_channel' => 'kiosk',
            'evaluation_evaluator_contact_id' => (string) (int) $e['evaluator_contact_id'],
            'evaluation_evaluator_user_id' => null,
            'evaluation_evaluator_name' => (string) Text::clip((string) $e['evaluator_name'], 200),
            'evaluation_evaluated_on' => (string) $e['evaluated_on'],
            'evaluation_result' => $result,
            'evaluation_equipment' => Text::clip($e['equipment'] ?? null, 200),
            'evaluation_checklist_json' => Canonical::doc($checklist),
            'evaluation_notes' => Text::clip($e['notes'] ?? null, 2000),
            'evaluation_proof' => 'evaluator_signed',
            'evaluation_evaluator_tsig_id' => (string) (int) $e['evaluator_tsig_id'],
            'evaluation_evaluatee_tsig_id' => (string) (int) $e['evaluatee_tsig_id'],
            'evaluation_evidence_media_id' => null,
            'evaluation_kiosk_id' => (int) ($e['kiosk_id'] ?? 0) > 0 ? (string) (int) $e['kiosk_id'] : null,
            'evaluation_recorded_by_user_id' => null,
            'evaluation_recorded_at_utc' => KTime::now(),
            'evaluation_hash_v' => '1',
        ]);
        $events = [$this->actor + [
            'type' => 'evaluation.recorded',
            'subject_contact_id' => $contactId,
            'course_id' => $courseId,
            'entity_type' => 'evaluation',
            'entity_id' => $ins['id'],
            'entity_sha256' => $ins['sha'],
            'payload' => ['course_id' => $courseId, 'result' => $result, 'evaluated_on' => (string) $e['evaluated_on'], 'channel' => 'kiosk', 'tsession_id' => null],
        ]];
        $completion = null;
        $pending = null;
        $opaque = null;
        if ($result === 'pass') {
            $cs = new CompletionService($this->c, CertSecret::fromGlobals());
            $issued = $cs->tryIssueComponents($contactId, $courseId, true);
            if ($issued === null) {
                $pending = $cs->lastPendingReason() ?? 'pending';
            } else {
                foreach ($issued['events'] ?? [] as $ev) {
                    $events[] = $ev;
                }
                unset($issued['events']);
                $opaque = $issued;
                $completion = ['completion_id' => (int) $issued['completion_id'],
                               'cert_number' => isset($issued['cert_number']) && $issued['cert_number'] !== null ? (string) $issued['cert_number'] : null];
            }
        }
        return ['evaluation_id' => (int) $ins['id'], 'result' => $result, 'duplicate' => false, 'completion' => $completion,
                'pending' => $pending, 'events' => $events, 'opaque' => $opaque];
    }

    /** After COMMIT: Phase 2's afterCommit for an issued completion (listeners, awards, audit, reconcile). Best-effort. */
    public function afterCommit(array $r): void
    {
        if (empty($r['opaque'])) {
            return;
        }
        try {
            (new CompletionService($this->c, CertSecret::fromGlobals()))->afterCommit($r['opaque']);
        } catch (\Throwable $e) {
            error_log('Kiosk EvaluationBridge::afterCommit: ' . get_class($e));
        }
    }

    /**
     * The newest open run of (contact, course) waiting for this evaluation, or null (Phase 3
     * table; lets the evaluation name the run it completes, evaluation_run_id).
     */
    public function awaitingRunId(int $contactId, int $courseId): ?int
    {
        try {
            $r = Db::one($this->c->db, "SELECT trun_id FROM training_runs WHERE trun_contact_id = ? AND trun_course_id = ?
                AND trun_status = 'awaiting_evaluation' ORDER BY trun_id DESC LIMIT 1", 'ii', [$contactId, $courseId]);
        } catch (\mysqli_sql_exception) {
            return null;
        }
        return $r === null ? null : (int) $r['trun_id'];
    }
}
