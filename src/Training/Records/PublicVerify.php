<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Compliance\PairRules;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;

/**
 * The public certificate check behind {baseUrl}/verify/?t=<token> (FROZEN for Phase 5, spec
 * §1.4 #3, §1.5, §3.5). Phase 5 builds verify/index.php on lookup(); the URL and this method's
 * contract never change.
 *
 * lookup() returns ONLY these keys, or null:
 *   {cert_number, name (snapshot), course (snapshot), issued_on, expires_on, status, status_label,
 *    external:bool, issuer:?string}
 * status/status_label come from Compliance\PairRules::certStatus (valid | expiring | expired |
 * revoked), exactly as on the certificate and the transcript.
 *
 * The token is pre-checked against ^[A-Za-z0-9_-]{24}$ before any query, and looked up only by
 * its sha256 (the token itself is never stored). A token derived under a different settings key
 * hashes to nothing on file and returns null. Document acknowledgments have no token, so they
 * never verify here.
 */
final class PublicVerify
{
    /** The only keys lookup() may return (asserted). */
    public const KEYS = ['cert_number', 'name', 'course', 'issued_on', 'expires_on', 'status', 'status_label', 'external', 'issuer'];

    public static function lookup(\mysqli $db, string $token, string $today): ?array
    {
        if (preg_match(CertIssuer::TOKEN_RE, $token) !== 1 || !Clock::isYmd($today)) {
            return null;
        }
        $row = Db::one($db, 'SELECT tc.completion_id, tc.completion_course_id, tc.completion_course_kind, tc.completion_method,
                tc.completion_cert_number, tc.completion_snap_contact_name, tc.completion_snap_course_name, tc.completion_completed_on,
                tc.completion_expires_on, tc.completion_snap_revision_number, tc.completion_supersedes_id, tc.completion_external_issuer,
                v.cvoid_at_utc
            FROM training_cert_tokens t
            JOIN training_completions tc ON tc.completion_id = t.certtok_completion_id
            LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = tc.completion_id
            WHERE t.certtok_token_sha256 = ?', 's', [hash('sha256', $token)]);
        if ($row === null || $row['completion_course_kind'] !== 'training') {
            return null;
        }
        $rr = CourseFacts::retrainRevisions($db, [(int) $row['completion_course_id']])[(int) $row['completion_course_id']] ?? null;
        $st = PairRules::certStatus([
            'completion_id' => (int) $row['completion_id'],
            'completed_on' => (string) $row['completion_completed_on'],
            'expires_on' => $row['completion_expires_on'] === null ? null : (string) $row['completion_expires_on'],
            'revision_number' => $row['completion_snap_revision_number'] === null ? null : (int) $row['completion_snap_revision_number'],
            'supersedes_id' => $row['completion_supersedes_id'] === null ? null : (int) $row['completion_supersedes_id'],
            'voided_at_utc' => $row['cvoid_at_utc'] === null ? null : (string) $row['cvoid_at_utc'],
        ], $rr, $today, RecordsSettings::fromDb($db)->dueSoonDays);

        $external = in_array((string) $row['completion_method'], ['external', 'legacy_paper'], true);
        $out = [
            'cert_number' => $row['completion_cert_number'] === null ? null : (string) $row['completion_cert_number'],
            'name' => (string) $row['completion_snap_contact_name'],
            'course' => (string) $row['completion_snap_course_name'],
            'issued_on' => (string) $row['completion_completed_on'],
            'expires_on' => $row['completion_expires_on'] === null ? null : (string) $row['completion_expires_on'],
            'status' => (string) $st['status'],
            'status_label' => (string) $st['label'],
            'external' => $external,
            'issuer' => $external && $row['completion_external_issuer'] !== null ? (string) $row['completion_external_issuer'] : null,
        ];
        if (array_keys($out) !== self::KEYS) {
            throw new \LogicException('PublicVerify: response keys drifted from the frozen contract');
        }
        return $out;
    }
}
