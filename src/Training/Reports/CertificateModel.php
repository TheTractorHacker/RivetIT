<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Compliance\PairRules;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;

/**
 * Everything the HTML certificate (M10, mockup Certificate) prints for one completion.
 *
 * The record is read with an explicit column list (never modified); status comes from
 * Compliance\PairRules::certStatus (spec §1.4 #4); the verify link comes from Lane C's frozen
 * token API: CompletionService::reprintToken() + CertIssuer::verifyUrl() (spec §1.4 #3).
 * Document-kind records are acknowledgment records: no number, no token, no QR (A14).
 * The caller authorizes (Access + Scope::assertContact on `contact_id`) before printing.
 */
final class CertificateModel
{
    /** @return ?array<string, mixed> null when the completion does not exist */
    public static function build(Ctx $c, int $completionId, ?RecordsSettings $settings = null): ?array
    {
        if ($completionId <= 0) {
            return null;
        }
        $db = $c->db;
        $r = Db::one($db, "SELECT tc.completion_id, tc.completion_contact_id, tc.completion_course_id, tc.completion_course_kind,
                tc.completion_method, tc.completion_proof, tc.completion_completed_on, tc.completion_trained_on,
                tc.completion_evaluated_on, tc.completion_expires_on, tc.completion_language, tc.completion_score_pct,
                tc.completion_trainer_contact_id, tc.completion_trainer_name, tc.completion_evaluator_name,
                tc.completion_evaluation_id, tc.completion_external_issuer, tc.completion_external_ref,
                tc.completion_cert_number, tc.completion_snap_contact_name, tc.completion_snap_course_name,
                tc.completion_snap_course_code, tc.completion_snap_revision_number, tc.completion_snap_regulation_ref,
                tc.completion_supersedes_id, tc.completion_recorded_at_utc, tc.completion_row_sha256,
                v.cvoid_reason, v.cvoid_at_utc
            FROM training_completions tc
            LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = tc.completion_id
            WHERE tc.completion_id = ?", 'i', [$completionId]);
        if ($r === null) {
            return null;
        }
        $settings ??= RecordsSettings::fromDb($db);
        $today = Clock::todayLocal();
        $courseId = (int) $r['completion_course_id'];
        $rr = Lookup::retrainRevisions($db, [$courseId])[$courseId] ?? null;
        $status = PairRules::certStatus([
            'completion_id' => (int) $r['completion_id'],
            'completed_on' => (string) $r['completion_completed_on'],
            'expires_on' => $r['completion_expires_on'],
            'revision_number' => $r['completion_snap_revision_number'] !== null ? (int) $r['completion_snap_revision_number'] : null,
            'supersedes_id' => $r['completion_supersedes_id'] !== null ? (int) $r['completion_supersedes_id'] : null,
            'voided_at_utc' => $r['cvoid_at_utc'],
        ], $rr, $today, $settings->dueSoonDays);

        $kind = (string) $r['completion_course_kind'];
        $method = (string) $r['completion_method'];
        $proof = (string) $r['completion_proof'];
        $external = in_array($method, ['external', 'legacy_paper'], true);

        $signer = null;
        if (!$external && $kind === 'training' && $method !== 'online') {
            $name = $method === 'evaluation'
                ? ($r['completion_evaluator_name'] ?: $r['completion_trainer_name'])
                : ($r['completion_trainer_name'] ?: $r['completion_evaluator_name']);
            if ($name) {
                $signer = ['name' => (string) $name, 'title' => self::trainerTitle($db, $r)];
            }
        }

        $verifyUrl = null;
        $token = null;
        if ($kind === 'training' && $r['completion_cert_number'] !== null) {
            try {
                $certKey = \ITFlow\Training\Records\CertSecret::fromGlobals();
                $token = (new \ITFlow\Training\Records\CompletionService($c, $certKey))->reprintToken($completionId);
                if (is_string($token) && preg_match('/^[A-Za-z0-9_-]{24}$/', $token) === 1) {
                    $verifyUrl = \ITFlow\Training\Records\CertIssuer::verifyUrl($c->baseUrl, $token);
                }
            } catch (\Throwable $e) {
                error_log('Training certificate: no verify token for #' . $completionId . ': ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        $grade = Labels::grade($method, $proof);
        $score = $r['completion_score_pct'] !== null ? Labels::score((string) $r['completion_score_pct']) : null;
        return [
            'completion_id' => (int) $r['completion_id'],
            'contact_id' => (int) $r['completion_contact_id'],
            'course_id' => $courseId,
            'kind' => $kind,
            'method' => $method,
            'proof' => $proof,
            'grade' => $grade,
            'external' => $external,
            'external_label' => $method === 'external' ? 'External card recorded' : ($method === 'legacy_paper' ? 'Paper record on file' : null),
            'issuer' => $r['completion_external_issuer'] ?: null,
            'external_ref' => $r['completion_external_ref'] ?: null,
            'cert_number' => $r['completion_cert_number'] ?: null,
            'person_name' => (string) $r['completion_snap_contact_name'],
            'course_name' => (string) $r['completion_snap_course_name'],
            'course_code' => $r['completion_snap_course_code'] ?: null,
            'revision_number' => $r['completion_snap_revision_number'] !== null ? (int) $r['completion_snap_revision_number'] : null,
            'regulation_line' => Labels::regulation($r['completion_snap_regulation_ref']),
            'components_line' => self::components($method, $proof, $score !== null),
            'score_pct' => $score,
            'completed_on' => (string) $r['completion_completed_on'],
            'trained_on' => $r['completion_trained_on'] ?: null,
            'evaluated_on' => $r['completion_evaluated_on'] ?: null,
            'expires_on' => $r['completion_expires_on'] ?: null,
            'language' => (string) $r['completion_language'],
            'signer' => $signer,
            'status' => ['code' => (string) ($status['status'] ?? 'valid'), 'reason' => $status['reason'] ?? null, 'label' => (string) ($status['label'] ?? '')],
            'voided' => $r['cvoid_at_utc'] !== null
                ? ['on' => Clock::localDate((string) $r['cvoid_at_utc']), 'reason' => (string) $r['cvoid_reason']] : null,
            'verify_url' => $verifyUrl,
            'row_sha12' => substr((string) $r['completion_row_sha256'], 0, 12),
            'recorded_at' => Clock::toIso((string) $r['completion_recorded_at_utc'], true),
            'ledger' => Lookup::ledgerStamp($db),
        ];
    }

    /** The components line under the course name (§5.1). */
    public static function components(string $method, string $proof, bool $scored): ?string
    {
        return match ($method) {
            'online' => $proof === 'self_pin_signature' ? 'Online course + signed attestation' : 'Online course + PIN attestation',
            'session' => 'Instructor-led session',
            'blended' => $scored ? 'Written exam + practical evaluation' : 'Instructor-led session + practical evaluation',
            'evaluation' => 'Practical evaluation',
            default => null,
        };
    }

    private static function trainerTitle(\mysqli $db, array $r): ?string
    {
        $contactId = $r['completion_trainer_contact_id'] !== null ? (int) $r['completion_trainer_contact_id'] : null;
        try {
            if ($contactId === null && $r['completion_evaluation_id'] !== null) {
                $e = Db::one($db, 'SELECT evaluation_evaluator_contact_id FROM training_evaluations WHERE evaluation_id = ?', 'i', [(int) $r['completion_evaluation_id']]);
                $contactId = $e !== null && $e['evaluation_evaluator_contact_id'] !== null ? (int) $e['evaluation_evaluator_contact_id'] : null;
            }
            if ($contactId === null) {
                return null;
            }
            $t = Db::one($db, 'SELECT t.trainer_title, c.contact_title FROM contacts c
                LEFT JOIN training_trainers t ON t.trainer_contact_id = c.contact_id WHERE c.contact_id = ?', 'i', [$contactId]);
            $title = ($t['trainer_title'] ?? null) ?: ($t['contact_title'] ?? null);
            return $title !== null && $title !== '' ? (string) $title : null;
        } catch (\mysqli_sql_exception) {
            return null;
        }
    }
}
