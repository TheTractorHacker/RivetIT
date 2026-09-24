<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;

/**
 * Office entry of an outside card, a paper record, or a paper acknowledgment (M8; v0 §6.6
 * "Add qualification"; Phase 2 spec §3.5).
 *
 *   method external     an outside training card or certificate (issuer required).
 *                       Shown as "External card recorded". Training kind only.
 *   method legacy_paper a paper record from before the LMS ("Paper record on file"), or for a
 *                       document course a paper acknowledgment (optionally naming the version).
 *
 * Proof is 'document' when a scan is attached (by attach token) and 'agent_recorded' otherwise,
 * which requires a reason of at least 10 characters. The record is issued through
 * CompletionService::issue with source key 'ext:<request_uid>', so a retried submit returns
 * the first record. Training-kind records get an LMS number and a verify token like any other
 * (decision §1.4 #2, R9); document acknowledgments get neither.
 *
 * The caller (RecordActions) has already checked level 2 and Scope::assertContact.
 */
final class ExternalRecordService
{
    public const NO_EVIDENCE_REASON_MIN = 10;

    public function __construct(private readonly Ctx $c, private readonly CompletionService $completions)
    {
    }

    /**
     * @param array{contact_id:int, course_id:int, method:string, issuer:?string, ref:?string, trained_on:?string,
     *   evaluated_on:?string, completed_on:string, expires_on:?string, revision_id:?int, evidence_token:?string,
     *   no_evidence_reason:?string, notes:?string, request_uid:string} $in (typed by the Action)
     * @return array issue()'s result
     */
    public function record(array $in): array
    {
        $db = $this->c->db;
        $contactId = (int) $in['contact_id'];
        $courseId = (int) $in['course_id'];
        $method = (string) $in['method'];
        if (!in_array($method, ['external', 'legacy_paper'], true)) {
            throw ApiException::validation(['method' => 'Choose External card or Paper record.']);
        }
        $uid = (string) ($in['request_uid'] ?? '');
        if (preg_match('/^[0-9a-f]{32}$/D', $uid) !== 1) {
            throw ApiException::validation(['request_uid' => 'Reload the form and try again.']);
        }
        $course = CourseFacts::load($db, $courseId, null);
        if ($course === null) {
            throw ApiException::notFound('That course was not found.');
        }
        $issuer = self::text($in['issuer'] ?? null);
        if ($course['kind'] === 'document') {
            if ($method !== 'legacy_paper') {
                throw ApiException::validation(['method' => 'A document acknowledgment can only be recorded as a paper record.']);
            }
        } elseif ($method === 'external' && $issuer === null) {
            throw ApiException::validation(['issuer' => 'Who issued the card? (for example, the training company)']);
        }
        $revisionId = isset($in['revision_id']) && $in['revision_id'] !== null ? (int) $in['revision_id'] : null;
        if ($revisionId !== null) {
            CourseFacts::load($db, $courseId, $revisionId);   // 422 when it is not this course's revision
        }

        $notes = self::text($in['notes'] ?? null);
        RecordRules::notOwn($this->c, $db, $contactId, $notes);
        RecordRules::dates([
            'completed_on' => $in['completed_on'] ?? null,
            'trained_on' => $in['trained_on'] ?? null,
            'evaluated_on' => $in['evaluated_on'] ?? null,
            'expires_on' => $in['expires_on'] ?? null,
        ], Clock::todayLocal());

        $mediaId = EvidenceStore::resolveToken($this->c, isset($in['evidence_token']) ? (string) $in['evidence_token'] : null);
        $noScan = self::text($in['no_evidence_reason'] ?? null);
        if ($mediaId === null) {
            if ($noScan === null || mb_strlen($noScan, 'UTF-8') < self::NO_EVIDENCE_REASON_MIN) {
                throw new ApiException(422, 'evidence_required', 'Attach a scan of the card or record, or say why there is none (at least '
                    . self::NO_EVIDENCE_REASON_MIN . ' characters).', ['no_evidence_reason' => 'At least ' . self::NO_EVIDENCE_REASON_MIN . ' characters.']);
            }
            $notes = trim(($notes === null ? '' : $notes . "\n") . 'No scan on file: ' . $noScan);
        }

        return $this->completions->issue([
            'contact_id' => $contactId,
            'course_id' => $courseId,
            'method' => $method,
            'proof' => $mediaId === null ? 'agent_recorded' : 'document',
            'source_key' => 'ext:' . $uid,
            'completed_on' => $in['completed_on'] ?? null,
            'trained_on' => $in['trained_on'] ?? null,
            'evaluated_on' => $in['evaluated_on'] ?? null,
            'expires_on_override' => $in['expires_on'] ?? null,
            'revision_id' => $revisionId,
            'external_issuer' => $issuer,
            'external_ref' => self::text($in['ref'] ?? null),
            'evidence_media_id' => $mediaId,
            'notes' => $notes,
        ]);
    }

    private static function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
