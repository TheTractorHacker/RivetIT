<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;

/**
 * The attested online part of a course for one person (the body of Kiosk\RunComponentSource,
 * kept here so kiosk code and tests can use it on a tree where Phase 2's OnlineComponentSource
 * interface is not deployed). Plain reads on the caller's connection, except the run it picks:
 * that one is re-read with LOCK IN SHARE MODE (the latest committed row, or the caller's own
 * uncommitted attestation) and skipped unless it is still attested and waiting or completed. A
 * record writer whose snapshot predates an agent's Reset progress (RunReset) therefore never
 * credits the abandoned run, and a reset that locks the run first waits for the writer's commit
 * (then finds the assignment completed). The shared lock is the writer's only run lock; nothing
 * that holds a run row waits for the records mutex except the kiosk attest (its own run) and
 * "Reset (take again)".
 */
final class AttestedRuns
{
    private const STATUSES = ['awaiting_session', 'awaiting_evaluation', 'completed'];

    /**
     * Newest attested run of (contact, course) with status awaiting_session, awaiting_evaluation or
     * completed whose LOCAL attestation date is on or after $sinceOn (see RunComponentSource),
     * skipping $excludeRunIds (runs of records voided by "Reset (take again)").
     */
    public static function find(\mysqli $db, int $contactId, int $courseId, string $sinceOn, array $excludeRunIds = []): ?array
    {
        $skip = array_fill_keys(array_map('intval', $excludeRunIds), true);
        $rows = Db::all($db, "SELECT r.trun_id, r.trun_revision_id, r.trun_attested_at_utc, r.trun_language, r.trun_attest_proof,
                r.trun_attest_tsig_id, r.trun_attest_pin_source, r.trun_attest_odoo_employee_id, r.trun_passed_attempt_id,
                r.trun_started_kiosk_id, s.tsig_kiosk_id
            FROM training_runs r
            LEFT JOIN training_signatures s ON s.tsig_id = r.trun_attest_tsig_id
            WHERE r.trun_contact_id = ? AND r.trun_course_id = ? AND r.trun_attested_at_utc IS NOT NULL
              AND r.trun_status IN ('awaiting_session', 'awaiting_evaluation', 'completed')
            ORDER BY r.trun_attested_at_utc DESC, r.trun_id DESC LIMIT 20", 'ii', [$contactId, $courseId]);
        foreach ($rows as $r) {
            $on = Clock::localDate((string) $r['trun_attested_at_utc']);
            if ($on < $sinceOn) {
                continue;
            }
            $runId = (int) $r['trun_id'];
            if (isset($skip[$runId])) {
                continue;
            }
            $now = Db::one($db, 'SELECT trun_status, trun_attested_at_utc FROM training_runs WHERE trun_id = ? LOCK IN SHARE MODE', 'i', [$runId]);
            if ($now === null || $now['trun_attested_at_utc'] === null || !in_array($now['trun_status'], self::STATUSES, true)) {
                continue;   // reset (abandoned) or otherwise changed since the caller's snapshot
            }
            $score = null;
            $passMark = null;
            $attempts = null;
            if ($r['trun_passed_attempt_id'] !== null) {
                $res = Db::one($db, 'SELECT a.tattempt_lesson_uid, a.tattempt_number, x.tresult_score_pct, x.tresult_pass_mark_pct
                    FROM training_attempts a JOIN training_attempt_results x ON x.tresult_attempt_id = a.tattempt_id
                    WHERE a.tattempt_id = ?', 'i', [(int) $r['trun_passed_attempt_id']]);
                if ($res !== null) {
                    $score = (string) $res['tresult_score_pct'];
                    $passMark = (int) $res['tresult_pass_mark_pct'];
                    $attempts = (int) $res['tattempt_number'];
                }
            }
            $secs = Db::one($db, 'SELECT COALESCE(SUM(lcomp_server_seconds), 0) AS s FROM training_lesson_completions WHERE lcomp_run_id = ?', 'i', [$runId]);
            $kioskId = $r['tsig_kiosk_id'] !== null ? (int) $r['tsig_kiosk_id'] : ($r['trun_started_kiosk_id'] !== null ? (int) $r['trun_started_kiosk_id'] : null);
            $asset = null;
            if ($kioskId !== null) {
                $k = Db::one($db, 'SELECT kiosk_asset_id FROM training_kiosks WHERE kiosk_id = ?', 'i', [$kioskId]);
                $asset = ($k === null || $k['kiosk_asset_id'] === null) ? null : (int) $k['kiosk_asset_id'];   // NULL: an unlisted device (not in Assets)
            }
            return [
                'run_id' => $runId,
                'revision_id' => (int) $r['trun_revision_id'],
                'attested_on' => $on,
                'score_pct' => $score,
                'pass_mark_pct' => $passMark,
                'attempts_used' => $attempts,
                'language' => (string) $r['trun_language'],
                'proof' => (string) ($r['trun_attest_proof'] ?? 'self_pin'),
                'kiosk_id' => $kioskId,
                'asset_id' => $asset,
                'learner_tsig_id' => $r['trun_attest_tsig_id'] === null ? null : (int) $r['trun_attest_tsig_id'],
                'pin_source' => $r['trun_attest_pin_source'] === null ? null : (string) $r['trun_attest_pin_source'],
                'odoo_employee_id' => $r['trun_attest_odoo_employee_id'] === null ? null : (int) $r['trun_attest_odoo_employee_id'],
                'duration_minutes' => min(65535, max(1, (int) ceil(((int) $secs['s']) / 60))),
            ];
        }
        return null;
    }
}
