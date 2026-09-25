<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Compliance\PairRules;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsSettings;

/**
 * The data a printed certificate shows (Phase 2 spec §3.5, §5.1 "training_certificate.php").
 * Pure read; the page authorizes first (level 1 plus Scope on the record's person) and renders
 * every string as text.
 *
 * build() => {
 *   completion_id, layout:'training'|'external'|'document', kind, method, cert_number,
 *   name, course, course_code, revision_number, regulation_ref, regulation_line, score_pct,
 *   components_line, issued_on, expires_on, trained_on, evaluated_on,
 *   issuer, ref, badge ("External card recorded" | "Paper record on file" | null),
 *   signer:{name, title}|null, status:{status, reason, label}, void:{at, reason}|null,
 *   verify_url, ledger:{seq, hash16}
 * } or null when the completion does not exist.
 *
 * Status comes from PairRules::certStatus (the single status function). The verify URL is
 * re-derived from the stored nonce (CertIssuer::reprint); null for documents or after a key
 * rotation, in which case the page prints the number without a QR code.
 */
final class CertificateView
{
    public const BADGE_EXTERNAL = 'External card recorded';
    public const BADGE_PAPER = 'Paper record on file';

    public static function build(\mysqli $db, int $completionId, string $baseUrl, string $certKey, RecordsSettings $s): ?array
    {
        $r = $completionId > 0 ? Db::one($db, 'SELECT tc.completion_id, tc.completion_course_id, tc.completion_course_kind, tc.completion_method,
                tc.completion_proof, tc.completion_cert_number, tc.completion_snap_contact_name, tc.completion_snap_course_name,
                tc.completion_snap_course_code, tc.completion_snap_revision_number, tc.completion_snap_regulation_ref, tc.completion_score_pct,
                tc.completion_completed_on, tc.completion_trained_on, tc.completion_evaluated_on, tc.completion_expires_on,
                tc.completion_external_issuer, tc.completion_external_ref, tc.completion_trainer_name, tc.completion_trainer_contact_id,
                tc.completion_evaluator_name, tc.completion_evaluation_id, tc.completion_run_id, tc.completion_supersedes_id,
                v.cvoid_at_utc, v.cvoid_reason
            FROM training_completions tc LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = tc.completion_id
            WHERE tc.completion_id = ?', 'i', [$completionId]) : null;
        if ($r === null) {
            return null;
        }
        $id = (int) $r['completion_id'];
        $kind = (string) $r['completion_course_kind'];
        $method = (string) $r['completion_method'];
        $str = static fn($v): ?string => ($v === null || $v === '') ? null : (string) $v;
        $today = Clock::todayLocal();
        $rr = CourseFacts::retrainRevisions($db, [(int) $r['completion_course_id']])[(int) $r['completion_course_id']] ?? null;
        $status = PairRules::certStatus([
            'completion_id' => $id,
            'completed_on' => (string) $r['completion_completed_on'],
            'expires_on' => $str($r['completion_expires_on']),
            'revision_number' => $r['completion_snap_revision_number'] === null ? null : (int) $r['completion_snap_revision_number'],
            'supersedes_id' => $r['completion_supersedes_id'] === null ? null : (int) $r['completion_supersedes_id'],
            'voided_at_utc' => $str($r['cvoid_at_utc']),
        ], $rr, $today, $s->dueSoonDays);

        $layout = $kind === 'document' ? 'document' : (in_array($method, ['external', 'legacy_paper'], true) ? 'external' : 'training');
        $ref = $str($r['completion_snap_regulation_ref']);

        $verifyUrl = null;
        if ($kind === 'training' && $certKey !== '') {
            $token = CertIssuer::reprint($db, $id, $certKey);
            $verifyUrl = $token === null ? null : CertIssuer::verifyUrl($baseUrl, $token);
        }

        $signer = null;
        if ($layout === 'training') {
            if ($method === 'evaluation' && $str($r['completion_evaluator_name']) !== null) {
                $signer = ['name' => (string) $r['completion_evaluator_name'], 'title' => null];
            } elseif ($str($r['completion_trainer_name']) !== null) {
                $tid = $r['completion_trainer_contact_id'] === null ? null : (int) $r['completion_trainer_contact_id'];
                $signer = ['name' => (string) $r['completion_trainer_name'], 'title' => TrainerService::titleOf($db, $tid)];
            } elseif ($str($r['completion_evaluator_name']) !== null) {
                $signer = ['name' => (string) $r['completion_evaluator_name'], 'title' => null];
            }
        }

        $ledger = null;
        $ev = Db::one($db, "SELECT tevent_seq, tevent_hash FROM training_events
            WHERE tevent_entity_type = 'completion' AND tevent_entity_id = ? AND tevent_type = 'completion.recorded' ORDER BY tevent_seq LIMIT 1", 'i', [$id]);
        if ($ev !== null) {
            $ledger = ['seq' => (int) $ev['tevent_seq'], 'hash16' => substr((string) $ev['tevent_hash'], 0, 16)];
        } else {
            try {
                $h = Ledger::head($db);
                $ledger = ['seq' => $h['seq'], 'hash16' => substr($h['hash'], 0, 16)];
            } catch (\RuntimeException) {
                $ledger = null;
            }
        }

        return [
            'completion_id' => $id,
            'layout' => $layout,
            'kind' => $kind,
            'method' => $method,
            'cert_number' => $str($r['completion_cert_number']),
            'name' => (string) $r['completion_snap_contact_name'],
            'course' => (string) $r['completion_snap_course_name'],
            'course_code' => $str($r['completion_snap_course_code']),
            'revision_number' => $r['completion_snap_revision_number'] === null ? null : (int) $r['completion_snap_revision_number'],
            'regulation_ref' => $ref,
            'regulation_line' => $ref === null ? null
                : (preg_match('/^19\d\d\./', $ref) === 1 ? 'Meets the training requirements of OSHA 29 CFR ' . $ref : 'Reference: ' . $ref),
            'score_pct' => $str($r['completion_score_pct']),
            'components_line' => self::componentsLine($method, $r['completion_evaluation_id'] !== null, $r['completion_run_id'] !== null),
            'issued_on' => (string) $r['completion_completed_on'],
            'expires_on' => $str($r['completion_expires_on']),
            'trained_on' => $str($r['completion_trained_on']),
            'evaluated_on' => $str($r['completion_evaluated_on']),
            'issuer' => $str($r['completion_external_issuer']),
            'ref' => $str($r['completion_external_ref']),
            'badge' => $layout !== 'external' ? null : ($method === 'external' ? self::BADGE_EXTERNAL : self::BADGE_PAPER),
            'signer' => $signer,
            'status' => $status,
            'void' => $r['cvoid_at_utc'] === null ? null : ['at' => Clock::toIso((string) $r['cvoid_at_utc'], true), 'reason' => (string) $r['cvoid_reason']],
            'verify_url' => $verifyUrl,
            'ledger' => $ledger,
        ];
    }

    /** The "how it was done" line under the score (spec §5.1). */
    public static function componentsLine(string $method, bool $hasEvaluation, bool $hasOnline = false): ?string
    {
        return match ($method) {
            'online' => 'Online course + signed attestation',
            'session' => 'Instructor-led session',
            'blended' => $hasEvaluation
                ? ($hasOnline ? 'Written exam + practical evaluation' : 'Instructor-led session + practical evaluation')
                : ($hasOnline ? 'Online course + instructor-led session' : 'Instructor-led session'),
            'evaluation' => 'Practical evaluation',
            default => null,
        };
    }
}
