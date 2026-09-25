<?php

namespace ITFlow\Training\Kiosk\Verify;

use ITFlow\Training\Core\Canonical;

/**
 * Kiosk evidence checks the generic EVENT_ROWS path cannot do (P3 spec §7.10, P-8). Called by
 * Core\LedgerVerifier::verify() inside its consistent snapshot, after checkUnevented():
 *
 *   answers_digest   tresult_answers_sha256 re-derived from training_attempt_answers
 *   log_digest       tresult_log_sha256 re-derived from the log rows with talog_id <= tresult_log_max_id
 *   png_sha          tsig_png_sha256 == sha256(base64_decode(tsig_png_base64))
 *   attempt_gap      tattempt_number runs 1..n with no gaps per (run, lesson)
 *   dangling_ref     trun_passed_attempt_id, lcomp_attempt_id and lcomp_tsig_id point at existing rows
 *
 * Plain BUFFERED queries (text protocol - the strings the writer hashed), keyset-paged. Skipped
 * silently while the Phase 3 tables are absent (a 2.6.92 database).
 */
final class KioskLedgerChecks
{
    public const PAGE = 500;

    private const TABLES = ['training_attempt_results', 'training_attempt_answers', 'training_attempt_answer_log', 'training_signatures',
                            'training_attempts', 'training_runs', 'training_lesson_completions'];

    /** The answer-row columns, in this order, that make up one element of the answers digest. */
    public const ANSWER_COLUMNS = ['tanswer_attempt_id', 'tanswer_question_uid', 'tanswer_position', 'tanswer_type', 'tanswer_presented',
                                   'tanswer_selected', 'tanswer_is_correct', 'tanswer_points_awarded', 'tanswer_points_possible', 'tanswer_critical'];
    public const LOG_COLUMNS = ['talog_id', 'talog_question_uid', 'talog_selected', 'talog_saved_at_utc'];

    /**
     * @param callable(?int, string, string): void $addBreak
     * @param callable(): bool $stopped
     */
    public static function run(\mysqli $db, callable $addBreak, callable $stopped): void
    {
        $in = "'" . implode("','", self::TABLES) . "'";
        $res = $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)");
        $n = (int) ($res->fetch_assoc()['n'] ?? 0);
        $res->free();
        if ($n !== count(self::TABLES)) {
            return;
        }
        self::digests($db, $addBreak, $stopped);
        if (!$stopped()) {
            self::signatures($db, $addBreak, $stopped);
        }
        if (!$stopped()) {
            self::attemptContinuity($db, $addBreak, $stopped);
        }
        if (!$stopped()) {
            self::references($db, $addBreak, $stopped);
        }
    }

    /** Digest of a list of rows: Canonical::sha256(Canonical::doc(list of string maps)) - the writer's formula (§2.5). */
    public static function digest(array $rows, array $columns): string
    {
        $list = [];
        foreach ($rows as $r) {
            $m = [];
            foreach ($columns as $c) {
                $m[$c] = $r[$c] === null ? null : (string) $r[$c];
            }
            $list[] = $m;
        }
        return Canonical::sha256(Canonical::doc($list));
    }

    private static function digests(\mysqli $db, callable $addBreak, callable $stopped): void
    {
        $after = 0;
        do {
            $res = $db->query('SELECT tresult_attempt_id, tresult_answers_sha256, tresult_log_sha256, tresult_log_max_id
                FROM training_attempt_results WHERE tresult_attempt_id > ' . $after . ' ORDER BY tresult_attempt_id LIMIT ' . self::PAGE);
            $page = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
            foreach ($page as $r) {
                $aid = (int) $r['tresult_attempt_id'];
                $after = $aid;
                $res = $db->query('SELECT ' . implode(', ', self::ANSWER_COLUMNS) . ' FROM training_attempt_answers
                    WHERE tanswer_attempt_id = ' . $aid . ' ORDER BY tanswer_position, tanswer_question_uid');
                $answers = $res->fetch_all(MYSQLI_ASSOC);
                $res->free();
                if (!hash_equals((string) $r['tresult_answers_sha256'], self::digest($answers, self::ANSWER_COLUMNS))) {
                    $addBreak(null, 'answers_digest', "training_attempt_results #$aid answers digest does not re-compute");
                }
                $max = (int) $r['tresult_log_max_id'];
                $res = $db->query('SELECT ' . implode(', ', self::LOG_COLUMNS) . ' FROM training_attempt_answer_log
                    WHERE talog_attempt_id = ' . $aid . ' AND talog_id <= ' . $max . ' ORDER BY talog_id');
                $log = $res->fetch_all(MYSQLI_ASSOC);
                $res->free();
                if (!hash_equals((string) $r['tresult_log_sha256'], self::digest($log, self::LOG_COLUMNS))) {
                    $addBreak(null, 'log_digest', "training_attempt_results #$aid answer-log digest does not re-compute");
                }
                if ($stopped()) {
                    return;
                }
            }
        } while (count($page) === self::PAGE && !$stopped());
    }

    private static function signatures(\mysqli $db, callable $addBreak, callable $stopped): void
    {
        $after = 0;
        do {
            // One page of ids, then each PNG on its own (they are up to ~270 KB of base64).
            $res = $db->query('SELECT tsig_id FROM training_signatures WHERE tsig_id > ' . $after . ' ORDER BY tsig_id LIMIT ' . self::PAGE);
            $ids = array_map('intval', array_column($res->fetch_all(MYSQLI_ASSOC), 'tsig_id'));
            $res->free();
            foreach ($ids as $id) {
                $after = $id;
                $res = $db->query('SELECT tsig_png_base64, tsig_png_sha256 FROM training_signatures WHERE tsig_id = ' . $id);
                $s = $res->fetch_assoc();
                $res->free();
                $bin = base64_decode((string) ($s['tsig_png_base64'] ?? ''), true);
                if ($bin === false || !hash_equals((string) $s['tsig_png_sha256'], hash('sha256', $bin))) {
                    $addBreak(null, 'png_sha', "training_signatures #$id PNG does not match tsig_png_sha256");
                }
                if ($stopped()) {
                    return;
                }
            }
        } while (count($ids) === self::PAGE && !$stopped());
    }

    private static function attemptContinuity(\mysqli $db, callable $addBreak, callable $stopped): void
    {
        $res = $db->query('SELECT tattempt_run_id, tattempt_lesson_uid, COUNT(*) AS n, MIN(tattempt_number) AS lo, MAX(tattempt_number) AS hi
            FROM training_attempts GROUP BY tattempt_run_id, tattempt_lesson_uid HAVING lo <> 1 OR hi <> n');
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $g) {
            $addBreak(null, 'attempt_gap', 'run #' . (int) $g['tattempt_run_id'] . ' lesson ' . preg_replace('/[^0-9a-z]/', '', (string) $g['tattempt_lesson_uid'])
                . ': attempt numbers ' . (int) $g['lo'] . '..' . (int) $g['hi'] . ' for ' . (int) $g['n'] . ' attempts');
            if ($stopped()) {
                break;
            }
        }
        $res->free();
    }

    private static function references(\mysqli $db, callable $addBreak, callable $stopped): void
    {
        $checks = [
            ['SELECT r.trun_id AS id FROM training_runs r LEFT JOIN training_attempts a ON a.tattempt_id = r.trun_passed_attempt_id
               WHERE r.trun_passed_attempt_id IS NOT NULL AND a.tattempt_id IS NULL LIMIT 50', 'training_runs #%d trun_passed_attempt_id points at no attempt'],
            ['SELECT l.lcomp_id AS id FROM training_lesson_completions l LEFT JOIN training_attempts a ON a.tattempt_id = l.lcomp_attempt_id
               WHERE l.lcomp_attempt_id IS NOT NULL AND a.tattempt_id IS NULL LIMIT 50', 'training_lesson_completions #%d lcomp_attempt_id points at no attempt'],
            ['SELECT l.lcomp_id AS id FROM training_lesson_completions l LEFT JOIN training_signatures s ON s.tsig_id = l.lcomp_tsig_id
               WHERE l.lcomp_tsig_id IS NOT NULL AND s.tsig_id IS NULL LIMIT 50', 'training_lesson_completions #%d lcomp_tsig_id points at no signature'],
        ];
        foreach ($checks as [$sql, $fmt]) {
            $res = $db->query($sql);
            foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
                $addBreak(null, 'dangling_ref', sprintf($fmt, (int) $r['id']));
            }
            $res->free();
            if ($stopped()) {
                return;
            }
        }
    }
}
