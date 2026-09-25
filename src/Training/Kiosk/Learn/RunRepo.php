<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\RevisionCache;

/**
 * Shared reads for the learner engine (runs, their revision, lesson lookups, progress). Every
 * query uses an explicit column list; FOR UPDATE variants are used only inside Db::tx and follow
 * the kiosk lock order (§0.8): run row -> attempt row -> P2 rows -> ledger head.
 */
final class RunRepo
{
    public const COLUMNS = 'trun_id, trun_contact_id, trun_course_id, trun_revision_id, trun_revision_sha256, trun_assignment_id, trun_language,
        trun_status, trun_open_guard, trun_channel, trun_started_at_utc, trun_started_kiosk_id, trun_current_lesson_uid, trun_lesson_opened_at_utc,
        trun_lesson_last_tick_at_utc, trun_lesson_last_active, trun_lesson_credit_s, trun_lesson_max_position, trun_lesson_pages_hex,
        trun_lesson_rejected_ticks, trun_progress_pct, trun_extra_attempts, trun_locked_at_utc, trun_locked_lesson_uid, trun_blocked_reason,
        trun_blocked_lesson_uid, trun_passed_attempt_id, trun_attested_at_utc, trun_attest_tsig_id, trun_attest_proof, trun_attest_pin_source,
        trun_attest_odoo_employee_id, trun_last_activity_at_utc, trun_ended_at_utc, trun_completion_id, trun_superseded_by_run_id';

    public const OPEN_STATUSES = ['in_progress', 'awaiting_signature', 'awaiting_session', 'awaiting_evaluation'];
    public const AWAITING = ['awaiting_signature', 'awaiting_session', 'awaiting_evaluation'];

    public static function load(\mysqli $db, int $runId, bool $forUpdate = false): ?array
    {
        if ($runId < 1) {
            return null;
        }
        return Db::one($db, 'SELECT ' . self::COLUMNS . ' FROM training_runs WHERE trun_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$runId]);
    }

    /** The run, 404 unless it belongs to $contactId (a learner never learns another person's run exists). */
    public static function own(\mysqli $db, int $runId, int $contactId, bool $forUpdate = false): array
    {
        $run = self::load($db, $runId, $forUpdate);
        if ($run === null || (int) $run['trun_contact_id'] !== $contactId || $contactId < 1) {
            throw ApiException::notFound('That course run was not found.');
        }
        return $run;
    }

    /** The open run of the pair (trun_open_guard = 1), or null. */
    public static function open(\mysqli $db, int $contactId, int $courseId, bool $forUpdate = false): ?array
    {
        return Db::one($db, 'SELECT ' . self::COLUMNS . ' FROM training_runs WHERE trun_contact_id = ? AND trun_course_id = ? AND trun_open_guard = 1'
            . ($forUpdate ? ' FOR UPDATE' : ''), 'ii', [$contactId, $courseId]);
    }

    /** @return list<array> every open run of the person */
    public static function openRuns(\mysqli $db, int $contactId): array
    {
        return Db::all($db, 'SELECT ' . self::COLUMNS . ' FROM training_runs WHERE trun_contact_id = ? AND trun_open_guard = 1 ORDER BY trun_id', 'i', [$contactId]);
    }

    /** The run's revision, re-verified against its sha (RevisionCache) and against the run's stored sha. */
    public static function revision(\mysqli $db, array $run): array
    {
        $rev = RevisionCache::get($db, (int) $run['trun_revision_id']);
        if (!hash_equals((string) $run['trun_revision_sha256'], $rev['sha256'])) {
            throw new \RuntimeException('Kiosk run #' . (int) $run['trun_id'] . ' revision sha does not match');
        }
        return $rev;
    }

    /** @return array<string, array> lesson uid => revision lesson */
    public static function lessons(array $doc): array
    {
        $out = [];
        foreach ($doc['lessons'] ?? [] as $l) {
            $out[(string) $l['uid']] = $l;
        }
        return $out;
    }

    public static function lesson(array $doc, string $uid): ?array
    {
        foreach ($doc['lessons'] ?? [] as $l) {
            if ((string) $l['uid'] === $uid) {
                return $l;
            }
        }
        return null;
    }

    /** The lesson's variant in $lang, else the course default, else any. */
    public static function variant(array $lesson, string $lang, string $default): array
    {
        $v = $lesson['variants'] ?? [];
        if (!is_array($v) || $v === []) {
            return [];
        }
        return $v[$lang] ?? $v[$default] ?? (reset($v) ?: []);
    }

    /** @return list<string> lesson uids in course order */
    public static function order(array $doc): array
    {
        return array_values(array_map('strval', $doc['lesson_order'] ?? []));
    }

    /** @return list<string> required lesson uids in course order */
    public static function requiredUids(array $doc): array
    {
        $by = self::lessons($doc);
        $out = [];
        foreach (self::order($doc) as $uid) {
            if (!empty($by[$uid]['required'])) {
                $out[] = $uid;
            }
        }
        return $out;
    }

    /**
     * The first required lesson that keeps the final exam $examUid locked (409 exam_locked), or null.
     * §3.4 start step 2: the exam needs every other required lesson done. In a SEQUENTIAL course a
     * required lesson placed after the exam (an acknowledgment, say) is itself gated behind the exam,
     * so it is not counted - otherwise the exam and that lesson would lock each other and the learner
     * could never finish. In a free-order course every other required lesson counts (each can be done
     * first).
     */
    public static function examBlocker(array $doc, array $done, string $examUid): ?string
    {
        $sequential = !empty($doc['course']['sequential']);
        foreach (self::requiredUids($doc) as $u) {
            if ($u === $examUid) {
                if ($sequential) {
                    return null;
                }
                continue;
            }
            if (!isset($done[$u])) {
                return $u;
            }
        }
        return null;
    }

    /** @return array<string, true> lesson uids with a lesson completion in the run */
    public static function done(\mysqli $db, int $runId): array
    {
        $out = [];
        foreach (Db::all($db, 'SELECT lcomp_lesson_uid FROM training_lesson_completions WHERE lcomp_run_id = ?', 'i', [$runId]) as $r) {
            $out[(string) $r['lcomp_lesson_uid']] = true;
        }
        return $out;
    }

    /** floor(100 × done required / total required); 100 for a course with no required lesson. */
    public static function progress(array $doc, array $done): int
    {
        $req = self::requiredUids($doc);
        if ($req === []) {
            return 100;
        }
        $n = 0;
        foreach ($req as $u) {
            if (isset($done[$u])) {
                $n++;
            }
        }
        return intdiv(100 * $n, count($req));
    }

    public static function allRequiredDone(array $doc, array $done): bool
    {
        foreach (self::requiredUids($doc) as $u) {
            if (!isset($done[$u])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Sequential gating: with course.sequential, every REQUIRED lesson before $uid (course order)
     * must be done. Throws 409 lesson_locked.
     */
    public static function assertUnlocked(array $doc, array $done, string $uid): void
    {
        if (empty($doc['course']['sequential'])) {
            return;
        }
        $by = self::lessons($doc);
        foreach (self::order($doc) as $u) {
            if ($u === $uid) {
                return;
            }
            if (!empty($by[$u]['required']) && !isset($done[$u])) {
                throw new ApiException(409, 'lesson_locked', 'Finish the lessons before this one first.', [], ['lesson_uid' => $u]);
            }
        }
    }

    /** The next lesson after $uid in course order that is not done (null at the end). */
    public static function nextUid(array $doc, array $done, ?string $uid): ?string
    {
        $order = self::order($doc);
        $start = $uid === null ? 0 : ((int) array_search($uid, $order, true)) + 1;
        for ($i = $start; $i < count($order); $i++) {
            if (!isset($done[$order[$i]])) {
                return $order[$i];
            }
        }
        foreach ($order as $u) {
            if (!isset($done[$u])) {
                return $u;
            }
        }
        return null;
    }

    /** The run's language if the revision has it, else the course default. */
    public static function lang(array $run, array $doc): string
    {
        $l = (string) $run['trun_language'];
        return in_array($l, $doc['course']['languages'] ?? [], true) ? $l : (string) $doc['course']['default_language'];
    }

    /** Ledger actor for a run event, merged with the request's actor fields. */
    public static function event(array $actor, string $type, array $run, string $entityType, int $entityId, ?string $sha, array $payload): array
    {
        return array_merge($actor, [
            'type' => $type,
            'subject_contact_id' => (int) $run['trun_contact_id'],
            'course_id' => (int) $run['trun_course_id'],
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_sha256' => $sha,
            'payload' => $payload,
        ]);
    }
}
