<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Achievements\AwardRepository;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * The learner's sign-off (P3 spec §3.4 "AttestService::attest", L-6): finger signature (when the
 * course requires one) + PIN re-entry (always) -> the Phase 2 record through RecordsBridge ->
 * the receipt. The signature is validated BEFORE the PIN is charged, and records availability
 * before either, so a learner never spends a PIN try on something that cannot finish.
 *
 * finishInTx() is the shared attestation step (also the final acknowledgment of a DOCUMENT
 * course, RunService::ackSign): run attestation facts, next status, the P2 record, and the
 * online.attested event - all inside the caller's transaction, P2's events returned for the
 * caller to append last.
 */
final class AttestService
{
    public const BUILTIN_TEXT = 'I, {name}, completed {course} (version {revision}) on {date}. I understood it and I will follow it at work.';
    public const BUILTIN_TEXT_ES = 'Yo, {name}, completé {course} (versión {revision}) el {date}. Lo entendí y lo voy a seguir en el trabajo.';
    /** Blended courses (a class or a hands-on evaluation still to come): the learner signs for the online part only. */
    public const BUILTIN_TEXT_PART = 'I, {name}, completed the online part of {course} (version {revision}) on {date}. I understood it and I will follow it at work.';
    public const BUILTIN_TEXT_PART_ES = 'Yo, {name}, completé la parte en línea de {course} (versión {revision}) el {date}. Lo entendí y lo voy a seguir en el trabajo.';

    public function __construct(private readonly KioskCtx $k)
    {
    }

    /** The attestation statement for a run, placeholders filled ({name} {course} {revision} {date}). */
    public function attestationText(array $run, array $doc): string
    {
        $lang = RunRepo::lang($run, $doc);
        $default = (string) ($doc['course']['default_language'] ?? 'en');
        $text = $doc['course']['text'][$lang]['attestation_text'] ?? $doc['course']['text'][$default]['attestation_text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            $text = $this->k->core->settings->attestationDefault ?? null;
        }
        if (!is_string($text) || trim($text) === '') {
            $parts = is_array($doc['course']['components'] ?? null) ? $doc['course']['components'] : [];
            $text = !empty($parts['session']) || !empty($parts['practical'])
                ? ($lang === 'es' ? self::BUILTIN_TEXT_PART_ES : self::BUILTIN_TEXT_PART)
                : ($lang === 'es' ? self::BUILTIN_TEXT_ES : self::BUILTIN_TEXT);
        }
        $rev = (int) \ITFlow\Training\Kiosk\Core\RevisionCache::get($this->k->db(), (int) $run['trun_revision_id'])['number'];
        return strtr(trim($text), [
            '{name}' => (string) ($this->k->ksess['contact_name'] ?? ''),
            '{course}' => self::courseName($doc, $lang),
            '{revision}' => (string) $rev,
            '{date}' => self::longDate(Clock::todayLocal(), $lang),
        ]);
    }

    /** "September 25, 2026" / "25 de septiembre de 2026" from a Y-m-d (the signed statement reads like the mockup, not ISO). */
    public static function longDate(string $ymd, string $lang): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) !== 1) {
            return $ymd;
        }
        $mon = (int) $m[2];
        if ($mon < 1 || $mon > 12) {
            return $ymd;
        }
        $day = (int) $m[3];
        if ($lang === 'es') {
            $es = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            return $day . ' de ' . $es[$mon - 1] . ' de ' . $m[1];
        }
        $en = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        return $en[$mon - 1] . ' ' . $day . ', ' . $m[1];
    }

    /**
     * attest {run_id, signature_png?, pin} => Receipt. The caller (LearnerActions) pads the
     * response to >= 800 ms in a finally and never logs the PIN.
     */
    public function attest(int $runId, ?string $sigDataUrl, mixed $pin): array
    {
        $db = $this->k->db();
        $cid = $this->contact();
        $run = RunRepo::own($db, $runId, $cid);
        $rev = RunRepo::revision($db, $run);
        $doc = $rev['doc'];
        if ($run['trun_attested_at_utc'] !== null) {
            unset($pin);
            $r = $this->receipt($runId);
            if ($r !== null) {
                return $r;
            }
        }
        if ($run['trun_status'] !== 'awaiting_signature') {
            unset($pin);
            throw new ApiException(409, 'run_locked', 'This course is not ready to sign yet.');
        }
        if (!RunRepo::allRequiredDone($doc, RunRepo::done($db, $runId, $doc))) {
            unset($pin);   // a must-pass quick check not passed yet (a run from before kiosk quick checks): before any PIN charge
            throw new ApiException(409, 'check_pending', 'Pass the lesson\'s quick check first, then sign.');
        }
        if (!RecordsBridge::available($db)) {
            unset($pin);
            throw new ApiException(503, 'records_unavailable', 'Training records are not available yet. Your work is saved - try again later.');
        }
        $prep = null;
        if (!empty($doc['course']['requires_signature'])) {
            try {
                $prep = SignatureService::prepare($sigDataUrl ?? '');
            } catch (SignatureException $e) {
                unset($pin);
                throw $e->toApi();
            }
        }
        $pinInfo = PinGate::stepUp($this->k, $pin, 'attest');
        unset($pin);
        $startUtc = KTime::now();
        $statement = $this->attestationText($run, $doc);
        $bridge = new RecordsBridge($this->k->core);
        $actor = $this->k->eventBase();
        $k = $this->k;
        $out = Db::tx($db, static function () use ($db, $cid, $runId, $rev, $doc, $prep, $pinInfo, $statement, $bridge, $actor, $k): array {
            $run = RunRepo::own($db, $runId, $cid, true);
            if ($run['trun_status'] !== 'awaiting_signature' || $run['trun_attested_at_utc'] !== null) {
                throw new ApiException(409, 'run_locked', 'This course changed. Open it again.');
            }
            $events = [];
            $sig = null;
            if ($prep !== null) {
                $sig = SignatureService::insert($db, $prep, [
                    'purpose' => 'learner_attest', 'contact_id' => $cid, 'signer_name' => (string) ($k->ksess['contact_name'] ?? ''),
                    'statement_sha256' => hash('sha256', $statement), 'kiosk_id' => $k->kioskId() ?: null, 'ksess_id' => $k->ksessId(),
                    'run_id' => $runId, 'tsession_id' => null,
                ]);
                $events[] = array_merge($actor, SignatureService::event($sig, 'learner_attest', $runId, null, $cid, (int) $run['trun_course_id']));
            }
            $att = self::finishInTx($k, $bridge, $run, $rev, $sig, $sig !== null ? 'self_pin_signature' : 'self_pin', $pinInfo, $statement, KTime::now());
            foreach (array_merge($events, $att['events']) as $e) {
                Ledger::append($db, $e);
            }
            return ['run' => $att['run'], 'r' => $att['r']];
        });
        $bridge->afterCommit($out['r']);
        return self::receiptFor($this->k, $out['run'], $doc, $out['r'], $startUtc);
    }

    /**
     * INSIDE the caller's Db::tx with the run row locked: the attestation facts, the next status,
     * the P2 record (RecordsBridge::issueOnline) and online.attested. Returns P2's events followed
     * by online.attested, for the caller to append after its own events.
     *
     * @param array|null $sig  SignatureService::insert() result, or null (no signature ran)
     * @param array      $pinInfo PinGate::stepUp() result
     * @return array{run:array, r:?array, events:list<array>}
     */
    public static function finishInTx(KioskCtx $k, RecordsBridge $bridge, array $run, array $rev, ?array $sig, string $proof, ?array $pinInfo,
        string $statement, string $now): array
    {
        $db = $k->db();
        $doc = $rev['doc'];
        $runId = (int) $run['trun_id'];
        $cid = (int) $run['trun_contact_id'];
        $courseId = (int) $run['trun_course_id'];
        $comp = $doc['course']['components'] ?? [];
        $blended = !empty($comp['session']) || !empty($comp['practical']);
        $next = 'completed';
        if ($blended) {
            $next = (!empty($comp['session']) && !$bridge->attendedSession($cid, $courseId)) ? 'awaiting_session' : 'awaiting_evaluation';
            if (empty($comp['practical']) && $next === 'awaiting_evaluation') {
                $next = 'awaiting_session';   // session-only course whose session is recorded: tryIssueComponents closes it now
            }
        }
        $pinSource = $pinInfo['source'] ?? null;
        $emp = $pinInfo['odoo_employee_id'] ?? null;
        Db::exec($db, 'UPDATE training_runs SET trun_attested_at_utc = ?, trun_attest_tsig_id = ?, trun_attest_proof = ?, trun_attest_pin_source = ?,
                trun_attest_odoo_employee_id = ?, trun_status = ?, trun_last_activity_at_utc = ?, trun_current_lesson_uid = NULL, trun_lesson_resume_at = NULL WHERE trun_id = ?',
            'sississi', [$now, $sig['id'] ?? null, $proof, $pinSource, $emp, $blended ? $next : 'awaiting_signature', $now, $runId]);
        $run = RunRepo::load($db, $runId) ?? $run;

        $score = null;
        $passMark = null;
        $attempts = null;
        if ($run['trun_passed_attempt_id'] !== null) {
            $res = Db::one($db, 'SELECT a.tattempt_number, x.tresult_score_pct, x.tresult_pass_mark_pct FROM training_attempts a
                JOIN training_attempt_results x ON x.tresult_attempt_id = a.tattempt_id WHERE a.tattempt_id = ?', 'i', [(int) $run['trun_passed_attempt_id']]);
            if ($res !== null) {
                $score = (string) $res['tresult_score_pct'];
                $passMark = (int) $res['tresult_pass_mark_pct'];
                $attempts = (int) $res['tattempt_number'];
            }
        }
        $secs = (int) (Db::one($db, 'SELECT COALESCE(SUM(lcomp_server_seconds), 0) AS s FROM training_lesson_completions WHERE lcomp_run_id = ?', 'i', [$runId])['s'] ?? 0);
        $r = $bridge->issueOnline([
            'contact_id' => $cid,
            'course_id' => $courseId,
            'revision_id' => (int) $run['trun_revision_id'],
            'run_id' => $runId,
            'attempt_id' => $run['trun_passed_attempt_id'] === null ? null : (int) $run['trun_passed_attempt_id'],
            'score_pct' => $score,
            'pass_mark_pct' => $passMark,
            'attempts_used' => $attempts,
            'duration_minutes' => min(65535, max(1, (int) ceil($secs / 60))),
            'language' => RunRepo::lang($run, $doc),
            'learner_tsig_id' => $sig['id'] ?? null,
            'proof' => $proof,
            'pin_source' => $pinSource,
            'odoo_employee_id' => $emp,
            'kiosk_id' => $k->kioskId() > 0 ? $k->kioskId() : null,
            'asset_id' => isset($k->device['kiosk_asset_id']) ? (int) $k->device['kiosk_asset_id'] : null,   // NULL on an unlisted device
            'ksess_id' => $k->ksessId(),
            'attestation_text' => mb_substr(trim(strip_tags($statement)), 0, 5000, 'UTF-8'),
            'completed_on' => Clock::todayLocal(),
            'components' => $comp,
        ]);
        if ($r !== null) {
            Db::exec($db, "UPDATE training_runs SET trun_status = 'completed', trun_completion_id = ?, trun_ended_at_utc = ?, trun_open_guard = NULL
                WHERE trun_id = ?", 'isi', [(int) $r['completion_id'], $now, $runId]);
        } elseif (!$blended) {
            throw new \RuntimeException('Kiosk attest: records returned no completion for an online course');
        }
        $run = RunRepo::load($db, $runId) ?? $run;
        $events = $r === null ? [] : $r['events'];
        $events[] = RunRepo::event($k->eventBase(), 'online.attested', $run, 'run', $runId, null, [
            'tsig_id' => $sig['id'] ?? null,
            'completion_id' => $r === null ? null : (int) $r['completion_id'],
            'next_status' => (string) $run['trun_status'],
            'proof' => $proof,
            'pin_source' => $pinSource,
            'odoo_employee_id' => $emp,
        ]);
        return ['run' => $run, 'r' => $r, 'events' => $events];
    }

    /**
     * Receipt {status, kind, record_id, cert_number, score_pct, completed_on, expires_on, course_name, achievements}.
     * $sinceUtc bounds the awards shown ("unlocked just now").
     */
    public static function receiptFor(KioskCtx $k, array $run, array $doc, ?array $r, string $sinceUtc): array
    {
        $db = $k->db();
        $lang = RunRepo::lang($run, $doc);
        $status = match ((string) $run['trun_status']) {
            'completed' => 'recorded',
            'awaiting_session' => 'pending_session',
            'awaiting_evaluation' => 'pending_evaluation',
            default => 'recorded',
        };
        $completionId = $r['completion_id'] ?? ($run['trun_completion_id'] === null ? null : (int) $run['trun_completion_id']);
        $rec = $completionId === null ? null : (new RecordsBridge($k->core))->receipt((int) $completionId);
        $score = null;
        if ($run['trun_passed_attempt_id'] !== null) {
            $x = Db::one($db, 'SELECT tresult_score_pct FROM training_attempt_results WHERE tresult_attempt_id = ?', 'i', [(int) $run['trun_passed_attempt_id']]);
            $score = $x === null ? null : (string) $x['tresult_score_pct'];
        }
        return [
            'status' => $completionId !== null ? 'recorded' : $status,
            'kind' => (string) ($doc['course']['kind'] ?? 'training'),
            'record_id' => $completionId === null ? null : (int) $completionId,
            'cert_number' => $r['cert_number'] ?? ($rec['cert_number'] ?? null),
            'score_pct' => $score,
            'completed_on' => $r['completed_on'] ?? ($rec['completed_on'] ?? null),
            'expires_on' => $r['expires_on'] ?? ($rec['expires_on'] ?? null),
            'course_name' => self::courseName($doc, $lang),
            'achievements' => self::awardsSince($db, (int) $run['trun_contact_id'], $sinceUtc, $lang),
        ];
    }

    /**
     * The receipt of one of the caller's runs from the database (sign.php?receipt=1): a run that
     * was attested within $withinS seconds and is completed or awaiting another part. Null otherwise.
     */
    public function receipt(int $runId, int $withinS = 600): ?array
    {
        $db = $this->k->db();
        $run = RunRepo::own($db, $runId, $this->contact());
        if ($run['trun_attested_at_utc'] === null) {
            return null;
        }
        $age = KTime::epoch(KTime::now()) - (float) KTime::epoch((string) $run['trun_attested_at_utc']);
        if ($age > $withinS || !in_array($run['trun_status'], ['completed', 'awaiting_session', 'awaiting_evaluation'], true)) {
            return null;
        }
        $doc = RunRepo::revision($db, $run)['doc'];
        return self::receiptFor($this->k, $run, $doc, null, KTime::fromEpoch((float) KTime::epoch((string) $run['trun_attested_at_utc']) - 120));
    }

    public static function courseName(array $doc, string $lang): string
    {
        $default = (string) ($doc['course']['default_language'] ?? 'en');
        return (string) ($doc['course']['text'][$lang]['name'] ?? $doc['course']['text'][$default]['name'] ?? '');
    }

    /** @return list<array> AwardPublic rows since $sinceUtc (K6); [] on a read failure (logged) */
    public static function awardsSince(\mysqli $db, int $cid, string $sinceUtc, ?string $lang = null): array
    {
        try {
            return array_values(AwardRepository::since($db, $cid, $sinceUtc, $lang));
        } catch (\Throwable $e) {
            error_log('Kiosk AttestService awards: ' . get_class($e));
            return [];
        }
    }

    private function contact(): int
    {
        $cid = $this->k->contactId();
        if ($cid < 1 || ($this->k->role() ?? '') !== 'learner') {
            throw new ApiException(403, 'wrong_role', 'That is not available in this mode.');
        }
        return $cid;
    }
}
