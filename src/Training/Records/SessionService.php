<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashedInsert;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsMutex;
use ITFlow\Training\Core\SessionDigest;
use ITFlow\Training\Core\Text;
use ITFlow\Training\People\Scope;

/**
 * Office sessions and paper backfill (S2; v0 §6.6; Phase 2 spec §3.5).
 *
 * A session is MUTABLE while open (every change is a session.opened/updated event) and
 * DIGEST-FROZEN at finalize: tsession_sha256 = SessionDigest over a text-protocol re-read of
 * the session and ALL its attendee rows (removed ones included), taken inside the finalize
 * transaction after the finalize fields are written. Once a session is finalized or cancelled
 * nothing here writes it again (409 session_finalized / session_cancelled); a correction
 * after finalize is a later-phase amendment row, never an edit.
 *
 * Attendees are replaced as a set on save; people dropped from the set are soft-removed
 * (removed_at_utc + reason), never deleted. An editor who cannot see some attendees (fail-closed
 * scope) never removes or reveals them: only in-scope attendees are replaced.
 *
 * FINALIZE, one transaction in the global lock order:
 *   session FOR UPDATE -> attendees FOR UPDATE -> records mutex -> finalize fields (digest_v 1)
 *   -> session practical marks as evaluation rows ('sev:<tattendee_id>', channel 'session')
 *   -> digest -> per present attendee: CompletionService::issue('att:<tattendee_id>', deferEvents)
 *      when the course needs no online part and (no practical, or a pass), else
 *      tryIssueComponents(deferEvents) -> events LAST: session.finalized, evaluation.recorded*,
 *      completion events*.
 * After commit: each completion's afterCommit with its reconcile suppressed, then ONE
 * reconcile over the present attendees (trigger 'session').
 */
final class SessionService
{
    public const ATTEST_TEXT = 'I witnessed this session, or transcribed it from the attached sheet.';
    public const AGENT_PROOFS = ['document', 'agent_recorded'];
    public const PAGE_SIZE = 50;

    private const SESSION_COLS = 'tsession_id, tsession_request_uid, tsession_course_id, tsession_revision_id, tsession_status, tsession_held_on,
        tsession_start_time, tsession_duration_minutes, tsession_client_id, tsession_location, tsession_topic, tsession_notes,
        tsession_trainer_contact_id, tsession_trainer_user_id, tsession_trainer_name, tsession_channel, tsession_is_backfill,
        tsession_evidence_media_id, tsession_created_by_user_id, tsession_finalized_at_utc, tsession_finalized_by_user_id,
        tsession_digest_v, tsession_sha256, tsession_cancel_reason, tsession_version, tsession_created_at';
    private const ATT_COLS = 'tattendee_id, tattendee_tsession_id, tattendee_contact_id, tattendee_proof, tattendee_attest_reason,
        tattendee_checked_in_at_utc, tattendee_attendance, tattendee_practical, tattendee_notes, tattendee_marked_by_user_id,
        tattendee_removed_at_utc, tattendee_removed_reason';

    public function __construct(private readonly Ctx $c, private readonly ?CompletionService $completions = null)
    {
    }

    // =========================================================================================
    // save
    // =========================================================================================

    /**
     * Creates ($id null) or updates an open session. $data is typed by the Action:
     *   course_id, held_on, start_time?, duration_minutes?, client_id, location?, topic?, notes?,
     *   trainer_contact_id? | trainer_name?, evidence_token?, request_uid? (create), remove_reason?,
     *   attendees: list<{contact_id, attendance, practical, proof, attest_reason?, notes?}>
     * $scope limits which existing attendees this editor may replace (null = all).
     */
    public function save(?int $id, ?int $version, array $data, ?Scope $scope = null): array
    {
        $db = $this->c->db;
        $today = Clock::todayLocal();
        $courseId = (int) ($data['course_id'] ?? 0);
        $course = CourseFacts::load($db, $courseId);
        if ($course === null) {
            throw ApiException::notFound('That course was not found.');
        }
        if (!$course['published']) {
            throw new ApiException(422, 'course_unpublished', 'Publish the course before recording a session for it.', ['course_id' => 'Not published.']);
        }
        $heldOn = (string) ($data['held_on'] ?? '');
        RecordRules::dates(['held_on' => $heldOn], $today);
        $startTime = self::time($data['start_time'] ?? null);
        $clientId = max(0, (int) ($data['client_id'] ?? 0));
        if ($clientId > 0 && Db::one($db, 'SELECT client_id FROM clients WHERE client_id = ?', 'i', [$clientId]) === null) {
            throw ApiException::validation(['client_id' => 'That department no longer exists.']);
        }
        if ($scope !== null && $clientId > 0 && !$scope->allows($clientId)) {
            throw ApiException::notFound('That department was not found.');
        }

        [$trainerContactId, $trainerUserId, $trainerName] = $this->trainer($data);
        $mediaId = EvidenceStore::resolveToken($this->c, isset($data['evidence_token']) ? (string) $data['evidence_token'] : null);

        $given = [];
        foreach (is_array($data['attendees'] ?? null) ? $data['attendees'] : [] as $i => $a) {
            $cid = (int) ($a['contact_id'] ?? 0);
            if ($cid < 1 || isset($given[$cid])) {
                throw ApiException::validation(['attendees' => 'Each person can be on the roster once.']);
            }
            $proof = (string) ($a['proof'] ?? 'document');
            if (!in_array($proof, self::AGENT_PROOFS, true)) {
                throw ApiException::validation(['attendees' => 'Choose Signed sheet or Recorded by office.']);
            }
            $reason = self::text($a['attest_reason'] ?? null, 255, 'attest_reason');
            if ($proof === 'agent_recorded' && ($reason === null || mb_strlen($reason, 'UTF-8') < 5)) {
                throw ApiException::validation(['attendees' => 'Say why there is no signature for everyone marked "Recorded by office".']);
            }
            $attendance = (string) ($a['attendance'] ?? 'present');
            $practical = (string) ($a['practical'] ?? 'not_evaluated');
            if (!in_array($attendance, ['present', 'partial', 'absent'], true) || !in_array($practical, ['not_evaluated', 'pass', 'fail'], true)) {
                throw ApiException::validation(['attendees' => 'Not a valid choice.']);
            }
            if (!$course['needs']['practical']) {
                $practical = 'not_evaluated';
            }
            $notes = self::text($a['notes'] ?? null, 500, 'notes');
            RecordRules::notOwn($this->c, $db, $cid, $notes ?? $reason);
            $given[$cid] = ['proof' => $proof, 'attest_reason' => $reason, 'attendance' => $attendance, 'practical' => $practical, 'notes' => $notes];
        }
        if (count($given) > 500) {
            throw ApiException::validation(['attendees' => 'Too many people on one session.']);
        }
        $removeReason = self::text($data['remove_reason'] ?? null, 255, 'remove_reason') ?? 'Removed from the roster before finalizing';

        $fields = [
            'tsession_course_id' => $courseId,
            'tsession_revision_id' => $course['revision']['id'] ?? null,
            'tsession_held_on' => $heldOn,
            'tsession_start_time' => $startTime,
            'tsession_duration_minutes' => isset($data['duration_minutes']) && $data['duration_minutes'] !== null ? max(0, min(65535, (int) $data['duration_minutes'])) : null,
            'tsession_client_id' => $clientId,
            'tsession_location' => self::text($data['location'] ?? null, 200, 'location'),
            'tsession_topic' => self::text($data['topic'] ?? null, 200, 'topic'),
            'tsession_notes' => self::text($data['notes'] ?? null, 5000, 'notes'),
            'tsession_trainer_contact_id' => $trainerContactId,
            'tsession_trainer_user_id' => $trainerUserId,
            'tsession_trainer_name' => $trainerName ?? '',
            'tsession_is_backfill' => $heldOn < Clock::addDays($today, -1) ? 1 : 0,
        ];
        if ($mediaId !== null) {
            $fields['tsession_evidence_media_id'] = $mediaId;
        }
        $requestUid = isset($data['request_uid']) && $data['request_uid'] !== null ? (string) $data['request_uid'] : null;
        if ($requestUid !== null && preg_match('/^[0-9a-f]{32}$/D', $requestUid) !== 1) {
            throw ApiException::validation(['request_uid' => 'Reload the form and try again.']);
        }

        $sessionId = Db::tx($db, function () use ($db, $id, $version, $fields, $given, $scope, $removeReason, $requestUid, $courseId, $heldOn): int {
            $now = Clock::nowUtc();
            if ($id === null) {
                if ($requestUid !== null) {
                    $dup = Db::one($db, 'SELECT tsession_id FROM training_sessions WHERE tsession_request_uid = ? FOR UPDATE', 's', [$requestUid]);
                    if ($dup !== null) {
                        return (int) $dup['tsession_id'];
                    }
                }
                $cols = array_keys($fields);
                $sessionId = Db::insert($db, 'INSERT INTO training_sessions (tsession_request_uid, tsession_status, tsession_channel, tsession_created_by_user_id, '
                    . implode(', ', $cols) . ') VALUES (?, \'open\', \'agent\', ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                    'si' . str_repeat('s', count($cols)),
                    array_merge([$requestUid, $this->c->userId > 0 ? $this->c->userId : null], self::strs(array_values($fields))));
                $type = 'session.opened';
                $existing = [];
            } else {
                $s = $this->lockOpen($db, $id, $version);
                $sessionId = (int) $s['tsession_id'];
                $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($fields)));
                Db::exec($db, "UPDATE training_sessions SET $sets, tsession_version = tsession_version + 1 WHERE tsession_id = ?",
                    str_repeat('s', count($fields)) . 'i', array_merge(self::strs(array_values($fields)), [$sessionId]));
                $type = 'session.updated';
                $existing = [];
                foreach (Db::all($db, 'SELECT ' . self::ATT_COLS . ' FROM training_session_attendees WHERE tattendee_tsession_id = ? FOR UPDATE', 'i', [$sessionId]) as $a) {
                    $existing[(int) $a['tattendee_contact_id']] = $a;
                }
            }

            $marker = $this->c->userId > 0 ? $this->c->userId : null;
            foreach ($given as $cid => $a) {
                $old = $existing[$cid] ?? null;
                if ($old === null) {
                    Db::exec($db, 'INSERT INTO training_session_attendees (tattendee_tsession_id, tattendee_contact_id, tattendee_proof, tattendee_attest_reason,
                            tattendee_checked_in_at_utc, tattendee_attendance, tattendee_practical, tattendee_notes, tattendee_marked_by_user_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', 'iissssssi',
                        [$sessionId, $cid, $a['proof'], $a['attest_reason'], $now, $a['attendance'], $a['practical'], $a['notes'], $marker]);
                    continue;
                }
                $same = $old['tattendee_proof'] === $a['proof'] && $old['tattendee_attest_reason'] === $a['attest_reason']
                    && $old['tattendee_attendance'] === $a['attendance'] && $old['tattendee_practical'] === $a['practical']
                    && $old['tattendee_notes'] === $a['notes'] && $old['tattendee_removed_at_utc'] === null;
                if (!$same) {
                    Db::exec($db, 'UPDATE training_session_attendees SET tattendee_proof = ?, tattendee_attest_reason = ?, tattendee_attendance = ?,
                            tattendee_practical = ?, tattendee_notes = ?, tattendee_marked_by_user_id = ?, tattendee_removed_at_utc = NULL,
                            tattendee_removed_reason = NULL
                        WHERE tattendee_id = ?', 'sssssii',
                        [$a['proof'], $a['attest_reason'], $a['attendance'], $a['practical'], $a['notes'], $marker, (int) $old['tattendee_id']]);
                }
            }
            $hidden = $this->hiddenContacts($db, array_keys($existing), $scope);
            foreach ($existing as $cid => $old) {
                if (isset($given[$cid]) || $old['tattendee_removed_at_utc'] !== null || isset($hidden[$cid])) {
                    continue;
                }
                Db::exec($db, 'UPDATE training_session_attendees SET tattendee_removed_at_utc = ?, tattendee_removed_reason = ?, tattendee_marked_by_user_id = ?
                    WHERE tattendee_id = ?', 'ssii', [$now, $removeReason, $marker, (int) $old['tattendee_id']]);
            }

            $active = array_map('intval', array_column(Db::all($db, 'SELECT tattendee_contact_id FROM training_session_attendees
                WHERE tattendee_tsession_id = ? AND tattendee_removed_at_utc IS NULL ORDER BY tattendee_contact_id', 'i', [$sessionId]), 'tattendee_contact_id'));
            Ledger::append($db, $this->actor() + [
                'type' => $type,
                'course_id' => $courseId,
                'entity_type' => 'session',
                'entity_id' => $sessionId,
                'payload' => ['course_id' => $courseId, 'held_on' => $heldOn, 'attendee_ids' => $active],
            ]);
            return $sessionId;
        });

        AfterCommit::log($id === null ? 'Create' : 'Edit', ($id === null ? 'Created' : 'Updated') . " training session #$sessionId", $sessionId);
        return $this->get($sessionId, $scope ?? Scope::all());
    }

    // =========================================================================================
    // finalize / cancel
    // =========================================================================================

    /**
     * @return array{session:array, issued:list<int>, pending:list<array{contact_id:int, reason:string}>, reconcile:?array}
     */
    public function finalize(int $id, int $version, bool $attest, ?Scope $scope = null): array
    {
        if (!$attest) {
            throw ApiException::validation(['attest' => 'Confirm that you witnessed the session or transcribed the attached sheet.']);
        }
        $cs = $this->completions ?? throw new \LogicException('SessionService::finalize needs a CompletionService');
        $db = $this->c->db;
        $today = Clock::todayLocal();

        $r = Db::tx($db, function () use ($db, $id, $version, $today, $cs): array {
            $s = $this->lockOpen($db, $id, $version);
            $atts = Db::all($db, 'SELECT ' . self::ATT_COLS . ' FROM training_session_attendees WHERE tattendee_tsession_id = ? ORDER BY tattendee_contact_id FOR UPDATE', 'i', [$id]);
            RecordsMutex::acquire($db);

            $present = array_values(array_filter($atts, static fn($a) => $a['tattendee_removed_at_utc'] === null && $a['tattendee_attendance'] === 'present'));
            if ($present === []) {
                throw ApiException::validation(['attendees' => 'Mark at least one person present.']);
            }
            if (trim((string) $s['tsession_trainer_name']) === '') {
                throw ApiException::validation(['trainer_name' => 'Name the trainer.']);
            }
            $heldOn = (string) $s['tsession_held_on'];
            if ($heldOn > $today) {
                throw new ApiException(422, 'date_out_of_range', 'A session can be finalized on or after the day it is held.', ['held_on' => 'In the future.']);
            }
            $backfill = $heldOn < Clock::addDays($today, -1);
            $mediaId = $s['tsession_evidence_media_id'] === null ? null : (int) $s['tsession_evidence_media_id'];
            if ($backfill && $mediaId === null) {
                throw new ApiException(422, 'evidence_required', 'Attach the scanned sign-in sheet: this session is being recorded after the fact.',
                    ['evidence_token' => 'Required for a past session.']);
            }
            foreach ($present as $a) {
                RecordRules::notOwn($this->c, $db, (int) $a['tattendee_contact_id'], $a['tattendee_notes'] ?? $a['tattendee_attest_reason']);
            }
            $courseId = (int) $s['tsession_course_id'];
            $revisionId = $s['tsession_revision_id'] === null ? null : (int) $s['tsession_revision_id'];
            $course = CourseFacts::load($db, $courseId, $revisionId);
            if ($course === null || $course['revision'] === null) {
                throw new ApiException(422, 'course_unpublished', 'This course has not been published.');
            }
            $needs = $course['needs'];
            $trainerContactId = $s['tsession_trainer_contact_id'] === null ? null : (int) $s['tsession_trainer_contact_id'];
            $trainerUserId = $s['tsession_trainer_user_id'] === null ? null : (int) $s['tsession_trainer_user_id'];
            $trainerName = (string) $s['tsession_trainer_name'];

            Db::exec($db, "UPDATE training_sessions SET tsession_status = 'finalized', tsession_is_backfill = ?, tsession_finalized_at_utc = ?,
                    tsession_finalized_by_user_id = ?, tsession_finalize_attest = ?, tsession_digest_v = 1, tsession_version = tsession_version + 1
                WHERE tsession_id = ?", 'isisi',
                [$backfill ? 1 : 0, Clock::nowUtc(), $this->c->userId > 0 ? $this->c->userId : null, self::ATTEST_TEXT, $id]);

            $actor = $cs->actor([]);
            $evalEvents = [];
            $evalIds = [];
            if ($needs['practical']) {
                foreach ($present as $a) {
                    $mark = (string) $a['tattendee_practical'];
                    if ($mark !== 'pass' && $mark !== 'fail') {
                        continue;
                    }
                    $cid = (int) $a['tattendee_contact_id'];
                    if ($trainerContactId !== null && $trainerContactId === $cid) {
                        throw new ApiException(422, 'evaluator_is_learner', 'The trainer cannot mark their own practical. Clear that mark or change the trainer.');
                    }
                    $ins = HashedInsert::insert($db, 'training_evaluations', [
                        'evaluation_source_key' => 'sev:' . (int) $a['tattendee_id'],
                        'evaluation_contact_id' => (string) $cid,
                        'evaluation_course_id' => (string) $courseId,
                        'evaluation_revision_id' => (string) $course['revision']['id'],
                        'evaluation_run_id' => null,
                        'evaluation_tsession_id' => (string) $id,
                        'evaluation_channel' => 'session',
                        'evaluation_evaluator_contact_id' => $trainerContactId === null ? null : (string) $trainerContactId,
                        'evaluation_evaluator_user_id' => $trainerUserId === null ? null : (string) $trainerUserId,
                        'evaluation_evaluator_name' => (string) Text::clip($trainerName, 200),
                        'evaluation_evaluated_on' => $heldOn,
                        'evaluation_result' => $mark,
                        'evaluation_equipment' => null,
                        'evaluation_checklist_json' => null,
                        'evaluation_notes' => null,
                        'evaluation_proof' => $a['tattendee_proof'] === 'agent_recorded' ? 'agent_recorded' : 'document',
                        'evaluation_evaluator_tsig_id' => null,
                        'evaluation_evaluatee_tsig_id' => null,
                        'evaluation_evidence_media_id' => $mediaId === null ? null : (string) $mediaId,
                        'evaluation_kiosk_id' => null,
                        'evaluation_recorded_by_user_id' => $this->c->userId > 0 ? (string) $this->c->userId : null,
                        'evaluation_recorded_at_utc' => Clock::nowUtc(),
                        'evaluation_hash_v' => '1',
                    ]);
                    $evalIds[(int) $a['tattendee_id']] = $ins['id'];
                    $evalEvents[] = $actor + [
                        'type' => 'evaluation.recorded',
                        'subject_contact_id' => $cid,
                        'course_id' => $courseId,
                        'entity_type' => 'evaluation',
                        'entity_id' => $ins['id'],
                        'entity_sha256' => $ins['sha'],
                        'payload' => ['course_id' => $courseId, 'result' => $mark, 'evaluated_on' => $heldOn, 'channel' => 'session', 'tsession_id' => $id],
                    ];
                }
            }

            $digest = SessionDigest::computeFromDb($db, $id, 1);
            Db::exec($db, 'UPDATE training_sessions SET tsession_sha256 = ? WHERE tsession_id = ?', 'si', [$digest, $id]);

            $issued = [];
            $pending = [];
            $completionEvents = [];
            foreach ($present as $a) {
                $cid = (int) $a['tattendee_contact_id'];
                $attId = (int) $a['tattendee_id'];
                $mark = (string) $a['tattendee_practical'];
                $res = null;
                if (!$needs['online'] && (!$needs['practical'] || $mark === 'pass')) {
                    $res = $cs->issue([
                        'contact_id' => $cid,
                        'course_id' => $courseId,
                        'method' => $needs['practical'] ? 'blended' : 'session',
                        'proof' => (string) $a['tattendee_proof'],
                        'source_key' => 'att:' . $attId,
                        'completed_on' => $heldOn,
                        'trained_on' => $heldOn,
                        'evaluated_on' => $needs['practical'] ? $heldOn : null,
                        'revision_id' => (int) $course['revision']['id'],
                        'duration_minutes' => $s['tsession_duration_minutes'] === null ? null : (int) $s['tsession_duration_minutes'],
                        'tsession_id' => $id,
                        'tattendee_id' => $attId,
                        'evaluation_id' => $evalIds[$attId] ?? null,
                        'trainer_contact_id' => $trainerContactId,
                        'trainer_user_id' => $trainerUserId,
                        'trainer_name' => $trainerName,
                        'evaluator_name' => $needs['practical'] ? $trainerName : null,
                        'evidence_media_id' => $mediaId,
                    ], true);
                } elseif ($needs['practical'] && $mark === 'fail') {
                    $pending[] = ['contact_id' => $cid, 'reason' => 'practical_failed'];
                } else {
                    $res = $cs->tryIssueComponents($cid, $courseId, true);
                    if ($res === null) {
                        $pending[] = ['contact_id' => $cid, 'reason' => $cs->lastPendingReason() ?? 'pending'];
                    }
                }
                if ($res !== null) {
                    foreach ($res['events'] ?? [] as $e) {
                        $completionEvents[] = $e;
                    }
                    unset($res['events']);
                    $issued[] = $res;
                }
            }

            Ledger::append($db, $actor + [
                'type' => 'session.finalized',
                'course_id' => $courseId,
                'entity_type' => 'session',
                'entity_id' => $id,
                'entity_sha256' => $digest,
                'payload' => ['course_id' => $courseId, 'held_on' => $heldOn, 'present' => count($present),
                              'total' => count(array_filter($atts, static fn($a) => $a['tattendee_removed_at_utc'] === null)), 'digest_v' => 1],
            ]);
            foreach (array_merge($evalEvents, $completionEvents) as $e) {
                Ledger::append($db, $e);
            }
            return ['issued' => $issued, 'pending' => $pending, 'present_ids' => array_map(static fn($a) => (int) $a['tattendee_contact_id'], $present)];
        });

        AfterCommit::log('Edit', "Finalized training session #$id (" . count($r['issued']) . ' records issued)', $id);
        $ids = [];
        foreach ($r['issued'] as $res) {
            if (empty($res['duplicate'])) {
                $cs->afterCommit($res + ['suppress_reconcile' => true]);
            }
            $ids[] = (int) $res['completion_id'];
        }
        $reconcile = CompletionService::reconcile($this->c, $r['present_ids'], 'session');
        return ['session' => $this->get($id, $scope ?? Scope::all()), 'issued' => $ids, 'pending' => $r['pending'], 'reconcile' => $reconcile];
    }

    public function cancel(int $id, string $reason, ?Scope $scope = null): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 255) {
            throw ApiException::validation(['reason' => 'Give a reason (5 to 255 characters).']);
        }
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $id, $reason): void {
            $s = $this->lockOpen($db, $id, null);
            Db::exec($db, "UPDATE training_sessions SET tsession_status = 'cancelled', tsession_cancel_reason = ?, tsession_version = tsession_version + 1
                WHERE tsession_id = ?", 'si', [$reason, $id]);
            $active = array_map('intval', array_column(Db::all($db, 'SELECT tattendee_contact_id FROM training_session_attendees
                WHERE tattendee_tsession_id = ? AND tattendee_removed_at_utc IS NULL ORDER BY tattendee_contact_id', 'i', [$id]), 'tattendee_contact_id'));
            Ledger::append($db, $this->actor() + [
                'type' => 'session.cancelled',
                'course_id' => (int) $s['tsession_course_id'],
                'entity_type' => 'session',
                'entity_id' => $id,
                'payload' => ['course_id' => (int) $s['tsession_course_id'], 'held_on' => (string) $s['tsession_held_on'], 'attendee_ids' => $active, 'reason' => $reason],
            ]);
        });
        AfterCommit::log('Edit', "Cancelled training session #$id", $id);
        return $this->get($id, $scope ?? Scope::all());
    }

    // =========================================================================================
    // read
    // =========================================================================================

    /** Session (spec §4.1). 404 unless its department or any current attendee is in scope. */
    public function get(int $id, Scope $s): array
    {
        $db = $this->c->db;
        $row = Db::one($db, 'SELECT ' . self::SESSION_COLS . ' FROM training_sessions WHERE tsession_id = ?', 'i', [$id]);
        if ($row === null || !$this->visible($row, $s)) {
            throw ApiException::notFound('That session was not found.');
        }
        $atts = Db::all($db, 'SELECT ' . self::ATT_COLS . ' FROM training_session_attendees WHERE tattendee_tsession_id = ? ORDER BY tattendee_id', 'i', [$id]);
        $contactIds = array_map(static fn($a) => (int) $a['tattendee_contact_id'], $atts);
        $refs = PersonRefs::load($db, $contactIds);
        $hidden = $this->hiddenContacts($db, $contactIds, $s);
        $completions = [];
        if ($atts !== []) {
            $attIds = array_map(static fn($a) => (int) $a['tattendee_id'], $atts);
            $in = implode(',', array_fill(0, count($attIds), '?'));
            foreach (Db::all($db, "SELECT completion_id, completion_tattendee_id FROM training_completions WHERE completion_tattendee_id IN ($in) ORDER BY completion_id",
                str_repeat('i', count($attIds)), $attIds) as $c) {
                $completions[(int) $c['completion_tattendee_id']] ??= (int) $c['completion_id'];
            }
        }
        $course = CourseFacts::load($db, (int) $row['tsession_course_id']);
        $finalized = $row['tsession_status'] === 'finalized';
        $outAtts = [];
        $hiddenCount = 0;
        foreach ($atts as $a) {
            $cid = (int) $a['tattendee_contact_id'];
            if (isset($hidden[$cid])) {
                $hiddenCount++;
                continue;
            }
            $attId = (int) $a['tattendee_id'];
            $pending = null;
            if ($finalized && $a['tattendee_attendance'] === 'present' && $a['tattendee_removed_at_utc'] === null && !isset($completions[$attId]) && $course !== null) {
                $pending = self::pendingLabel($course['needs'], (string) $a['tattendee_practical']);
            }
            $outAtts[] = [
                'id' => $attId,
                'person' => $refs[$cid] ?? ['contact_id' => $cid, 'name' => null, 'department' => null, 'title' => null, 'job' => null,
                                            'location' => null, 'hire_date' => null, 'employee_no' => null, 'archived' => true],
                'proof' => (string) $a['tattendee_proof'],
                'attest_reason' => $a['tattendee_attest_reason'],
                'attendance' => (string) $a['tattendee_attendance'],
                'practical' => (string) $a['tattendee_practical'],
                'notes' => $a['tattendee_notes'],
                'removed_at' => Clock::toIso($a['tattendee_removed_at_utc'], true),
                'removed_reason' => $a['tattendee_removed_reason'],
                'completion_id' => $completions[$attId] ?? null,
                'pending' => $pending,
            ];
        }
        $clientId = (int) $row['tsession_client_id'];
        $dept = $clientId > 0 ? Db::one($db, 'SELECT client_name FROM clients WHERE client_id = ?', 'i', [$clientId]) : null;
        $sha = $row['tsession_sha256'] === null ? null : (string) $row['tsession_sha256'];
        return [
            'id' => (int) $row['tsession_id'],
            'course' => $course === null ? null : CourseFacts::card($course),
            'status' => (string) $row['tsession_status'],
            'held_on' => (string) $row['tsession_held_on'],
            'start_time' => $row['tsession_start_time'] === null ? null : substr((string) $row['tsession_start_time'], 0, 5),
            'duration_minutes' => $row['tsession_duration_minutes'] === null ? null : (int) $row['tsession_duration_minutes'],
            'department' => $clientId > 0 ? ['id' => $clientId, 'name' => (string) ($dept['client_name'] ?? '')] : null,
            'location' => $row['tsession_location'],
            'topic' => $row['tsession_topic'],
            'notes' => $row['tsession_notes'],
            'trainer' => [
                'contact_id' => $row['tsession_trainer_contact_id'] === null ? null : (int) $row['tsession_trainer_contact_id'],
                'user_id' => $row['tsession_trainer_user_id'] === null ? null : (int) $row['tsession_trainer_user_id'],
                'name' => (string) $row['tsession_trainer_name'],
            ],
            'is_backfill' => (int) $row['tsession_is_backfill'] === 1,
            'evidence' => EvidenceStore::ref($db, $row['tsession_evidence_media_id'] === null ? null : (int) $row['tsession_evidence_media_id']),
            'version' => (int) $row['tsession_version'],
            'finalized_at' => Clock::toIso($row['tsession_finalized_at_utc'], true),
            'sha12' => $sha === null ? null : substr($sha, 0, 12),
            'cancel_reason' => $row['tsession_cancel_reason'],
            'attendees' => $outAtts,
            'hidden_attendees' => $hiddenCount,
        ];
    }

    /**
     * Session-lite rows: {id, course:{id,name,code}, status, held_on, topic, trainer_name,
     * department:{id,name}|null, present, total, is_backfill, finalized_at}.
     * $f: status, course_id, from, to, page (50 per page).
     */
    public function list(array $f, Scope $s): array
    {
        if ($s->isNone()) {
            return ['rows' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $types = '';
        $params = [];
        if (in_array($f['status'] ?? null, ['open', 'finalized', 'cancelled'], true)) {
            $where[] = 's.tsession_status = ?';
            $types .= 's';
            $params[] = $f['status'];
        }
        if (!empty($f['course_id'])) {
            $where[] = 's.tsession_course_id = ?';
            $types .= 'i';
            $params[] = (int) $f['course_id'];
        }
        if (!empty($f['from']) && Clock::isYmd((string) $f['from'])) {
            $where[] = 's.tsession_held_on >= ?';
            $types .= 's';
            $params[] = (string) $f['from'];
        }
        if (!empty($f['to']) && Clock::isYmd((string) $f['to'])) {
            $where[] = 's.tsession_held_on <= ?';
            $types .= 's';
            $params[] = (string) $f['to'];
        }
        if (!$s->isAll()) {
            [$sqlA, $tA, $pA] = $s->sqlIn('s.tsession_client_id');
            [$sqlB, $tB, $pB] = $s->sqlIn('c.contact_client_id');
            $where[] = '((1=1' . $sqlA . ') OR EXISTS (SELECT 1 FROM training_session_attendees ta JOIN contacts c ON c.contact_id = ta.tattendee_contact_id
                WHERE ta.tattendee_tsession_id = s.tsession_id AND ta.tattendee_removed_at_utc IS NULL' . $sqlB . '))';
            $types .= $tA . $tB;
            $params = array_merge($params, $pA, $pB);
        }
        $w = implode(' AND ', $where);
        $total = (int) (Db::one($this->c->db, "SELECT COUNT(*) AS n FROM training_sessions s WHERE $w", $types, $params)['n'] ?? 0);
        $page = max(1, (int) ($f['page'] ?? 1));
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = Db::all($this->c->db, "SELECT s.tsession_id, s.tsession_course_id, s.tsession_status, s.tsession_held_on, s.tsession_topic, s.tsession_trainer_name,
                s.tsession_client_id, cl.client_name, s.tsession_is_backfill, s.tsession_finalized_at_utc, co.course_name, co.course_code,
                (SELECT COUNT(*) FROM training_session_attendees a WHERE a.tattendee_tsession_id = s.tsession_id AND a.tattendee_removed_at_utc IS NULL AND a.tattendee_attendance = 'present') AS present,
                (SELECT COUNT(*) FROM training_session_attendees a WHERE a.tattendee_tsession_id = s.tsession_id AND a.tattendee_removed_at_utc IS NULL) AS total
            FROM training_sessions s
            LEFT JOIN clients cl ON cl.client_id = s.tsession_client_id
            LEFT JOIN training_courses co ON co.course_id = s.tsession_course_id
            WHERE $w ORDER BY s.tsession_held_on DESC, s.tsession_id DESC LIMIT " . self::PAGE_SIZE . " OFFSET $offset", $types, $params);
        $out = [];
        foreach ($rows as $r) {
            $cl = (int) $r['tsession_client_id'];
            $out[] = [
                'id' => (int) $r['tsession_id'],
                'course' => ['id' => (int) $r['tsession_course_id'], 'name' => (string) ($r['course_name'] ?? ''), 'code' => $r['course_code']],
                'status' => (string) $r['tsession_status'],
                'held_on' => (string) $r['tsession_held_on'],
                'topic' => $r['tsession_topic'],
                'trainer_name' => (string) $r['tsession_trainer_name'],
                'department' => $cl > 0 ? ['id' => $cl, 'name' => (string) ($r['client_name'] ?? '')] : null,
                'present' => (int) $r['present'],
                'total' => (int) $r['total'],
                'is_backfill' => (int) $r['tsession_is_backfill'] === 1,
                'finalized_at' => Clock::toIso($r['tsession_finalized_at_utc'], true),
            ];
        }
        return ['rows' => $out, 'total' => $total];
    }

    /** "Needs the online part" etc. for a present attendee of a finalized session who has no record yet. */
    public static function pendingLabel(array $needs, string $practical): string
    {
        if ($needs['practical'] && $practical === 'fail') {
            return 'Did not pass the practical';
        }
        if ($needs['online']) {
            return 'Needs the online part';
        }
        if ($needs['practical'] && $practical !== 'pass') {
            return 'Needs a practical evaluation';
        }
        return 'Waiting for the other parts of the course';
    }

    // =========================================================================================

    /** SELECT ... FOR UPDATE an open session; 404 / 409 session_finalized|session_cancelled / 409 conflict. */
    private function lockOpen(\mysqli $db, int $id, ?int $version): array
    {
        $s = Db::one($db, 'SELECT ' . self::SESSION_COLS . ' FROM training_sessions WHERE tsession_id = ? FOR UPDATE', 'i', [$id]);
        if ($s === null) {
            throw ApiException::notFound('That session was not found.');
        }
        if ($s['tsession_status'] === 'finalized') {
            throw new ApiException(409, 'session_finalized', 'This session is finalized and can no longer change.');
        }
        if ($s['tsession_status'] === 'cancelled') {
            throw new ApiException(409, 'session_cancelled', 'This session was cancelled.');
        }
        if ($version !== null && (int) $s['tsession_version'] !== $version) {
            throw ApiException::conflict(['id' => $id, 'version' => (int) $s['tsession_version']], 'Someone else changed this session. Reload to see their changes.');
        }
        return $s;
    }

    /** True when the session's department or any current attendee is in scope. */
    private function visible(array $row, Scope $s): bool
    {
        if ($s->isAll()) {
            return true;
        }
        if ($s->isNone()) {
            return false;
        }
        if ($s->allows((int) $row['tsession_client_id'])) {
            return true;
        }
        [$sql, $t, $p] = $s->sqlIn('c.contact_client_id');
        return Db::one($this->c->db, "SELECT 1 AS ok FROM training_session_attendees ta JOIN contacts c ON c.contact_id = ta.tattendee_contact_id
            WHERE ta.tattendee_tsession_id = ? AND ta.tattendee_removed_at_utc IS NULL$sql LIMIT 1", 'i' . $t, array_merge([(int) $row['tsession_id']], $p)) !== null;
    }

    /** @return array<int, true> contacts among $ids that $s does not see (none for all-scope or a null scope) */
    private function hiddenContacts(\mysqli $db, array $ids, ?Scope $s): array
    {
        if ($s === null || $s->isAll() || $ids === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $hidden = [];
        foreach (Db::all($db, "SELECT contact_id, contact_client_id FROM contacts WHERE contact_id IN ($in)", str_repeat('i', count($ids)), $ids) as $r) {
            if (!$s->allows((int) $r['contact_client_id'])) {
                $hidden[(int) $r['contact_id']] = true;
            }
        }
        foreach ($ids as $id) {
            if (!isset($hidden[$id]) && Db::one($db, 'SELECT 1 AS ok FROM contacts WHERE contact_id = ?', 'i', [$id]) === null) {
                $hidden[$id] = true;
            }
        }
        return $hidden;
    }

    /** @return array{0:?int, 1:?int, 2:?string} trainer contact id, user id, name */
    private function trainer(array $data): array
    {
        $tc = isset($data['trainer_contact_id']) && $data['trainer_contact_id'] !== null ? (int) $data['trainer_contact_id'] : null;
        if ($tc !== null) {
            $t = TrainerService::trainerFor($this->c->db, $tc);
            if ($t === null) {
                throw ApiException::validation(['trainer_contact_id' => 'That person is not an active trainer (People › Trainers). Type an outside instructor\'s name instead.']);
            }
            return [$t['contact_id'], $t['user_id'], (string) Text::clip($t['name'], 200)];
        }
        return [null, null, self::text($data['trainer_name'] ?? null, 200, 'trainer_name')];
    }

    private function actor(): array
    {
        return [
            'actor_type' => $this->c->userId > 0 ? 'user' : 'system',
            'actor_user_id' => $this->c->userId > 0 ? $this->c->userId : null,
            'user_agent' => $this->c->userAgent,
        ];
    }

    /** 'HH:MM' or 'HH:MM:SS' => 'HH:MM:SS' (the text-protocol form of a TIME column). */
    private static function time(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/D', trim($v), $m) !== 1) {
            throw ApiException::validation(['start_time' => 'Use a time like 07:30.']);
        }
        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }

    private static function text(mixed $v, int $max, string $field): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v) && !is_int($v)) {
            throw ApiException::validation([$field => 'Must be text.']);
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (!mb_check_encoding($v, 'UTF-8') || mb_strlen($v, 'UTF-8') > $max) {
            throw ApiException::validation([$field => "Too long (at most $max characters)."]);
        }
        return $v;
    }

    /** @return list<?string> */
    private static function strs(array $vals): array
    {
        return array_map(static fn($x) => $x === null ? null : (string) $x, $vals);
    }
}
