<?php

namespace ITFlow\Training\Achievements;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Icons;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Kiosk\Core\Hashed;

/**
 * The achievement award engine (plan A15, P3 spec §3.5).
 *
 * RULES (training_achievements.achievement_rule_type + the normalised achievement_rule_json):
 *   V = the contact's valid completed course ids (RecordsFacts, i.e. K3's RecordsBridge).
 *   course_completed         rule course_id ∈ V
 *   category_completed       every non-archived course with a current revision in the category ∈ V (at least one)
 *   path_completed           every required non-archived course of the non-archived path ∈ V (at least one);
 *                            ALSO every non-archived path whose required courses are all in V awards its
 *                            tpath_achievement_id (whatever that achievement's own rule type is)
 *   courses_completed_count  |V ∩ category filter| >= count
 *   perfect_score            exam passed with score '100.00' (optional course_id)
 *   first_attempt_pass       exam passed on attempt 1 with no earlier failed exam result for that
 *                            contact and course in any run (optional course_id)
 *   on_time_streak           RecordsFacts::onTimeMonths(contact) >= months (nightly only)
 *   manual                   awardManual() only
 * Only achievements with achievement_active = 1 and not archived are awarded.
 *
 * WRITES. One Db::tx per call (per contact for the nightly jobs), opened at depth 0 so the
 * ledger head is always this transaction's last lock. Rule awards use scope key '' (each badge
 * once per person, enforced by uq_training_taward); a manual award uses a random scope key, so
 * the same badge can be given again. Every hit is a Kiosk\Core\Hashed::insert in achievement id
 * order (so two concurrent evaluations lock rows in the same order), and a 1062 AT THE INSERT
 * means "already has it" and is skipped. All inserts come first, then one achievement.awarded
 * ledger event per new row (ledger last). Name, icon (Core\Icons::ALLOWED) and color
 * (#RRGGBB) are snapshotted; the evidence doc is Canonical::doc(
 * {trigger, course_id?, completion_id?, attempt_id?, path_id?, category_id?, count?, months?}).
 *
 * P2 calls onCompletionRecorded() after commit for EVERY completion (online, session, blended,
 * evaluation, external), so awards do not depend on the kiosk channel; a lost call is repaired by
 * the nightly backfill(). The listener and onExamSubmitted() never throw: failures are logged by
 * class name only.
 *
 * ACTOR arrays are ledger actor fields: actor_type ('contact'|'kiosk'|'user'|'system'),
 * actor_user_id, actor_contact_id, kiosk_id, ksess_id, user_agent - KioskCtx::eventBase() fits
 * as is. An optional 'lang' picks translated badge names for the returned AwardPublic list
 * (otherwise the ksess language when ksess_id is given).
 */
final class AwardEngine
{
    public const TABLE = 'training_achievement_awards';
    public const DEFAULT_ICON = 'award';
    public const DEFAULT_COLOR = '#D97706';
    public const REASON_MIN = 5;
    public const REASON_MAX = 500;

    /** Rule types decided from the completed-course set V. */
    private const COMPLETION_RULES = ['course_completed', 'category_completed', 'path_completed', 'courses_completed_count'];
    private const EXAM_RULES = ['perfect_score', 'first_attempt_pass'];
    private const ACTOR_KEYS = ['actor_type', 'actor_user_id', 'actor_contact_id', 'kiosk_id', 'ksess_id', 'user_agent'];
    private const HEX = '/^#[0-9A-Fa-f]{6}$/D';

    /** @var array<string, bool> database name => awards table present */
    private static array $ready = [];

    // ------------------------------------------------------------------ public API (§3.5)

    /** P2 listener (exact signature, C-P2-12): reads the completion via RecordsBridge, then evaluateContact(); never throws. */
    public static function onCompletionRecorded(Ctx $c, int $completionId): void
    {
        try {
            $db = $c->db;
            if ($completionId < 1 || !self::ready($db)) {
                return;
            }
            $facts = AwardFacts::get($db, $c);
            $completion = $facts?->completion($completionId);
            if ($completion === null || (int) $completion['contact_id'] < 1) {
                return;
            }
            $actor = $c->userId > 0
                ? ['actor_type' => 'user', 'actor_user_id' => $c->userId, 'user_agent' => $c->userAgent]
                : ['actor_type' => 'system', 'user_agent' => $c->userAgent];
            self::evaluateWith($db, $facts, $actor, (int) $completion['contact_id'], 'completion', $completionId);
        } catch (\Throwable $e) {
            error_log('Training awards: onCompletionRecorded failed: ' . get_class($e));
        }
    }

    /**
     * After an exam submit has committed. $facts {attempt_id, course_id, kind, passed, score_pct, attempt_number};
     * the stored attempt and result rows win over $facts when attempt_id names them. kind must be 'exam'.
     * Never throws. @return list<array> the NEW AwardPublic rows
     */
    public static function onExamSubmitted(\mysqli $db, array $actor, int $contactId, array $facts): array
    {
        try {
            if ($contactId < 1 || !self::ready($db)) {
                return [];
            }
            $exam = self::examFacts($db, $contactId, $facts);
            if ($exam === null) {
                return [];
            }
            $rules = self::rules($db, self::EXAM_RULES);
            if ($rules === []) {
                return [];
            }
            $hits = self::examHits($rules, $exam, self::noEarlierFail($db, $contactId, $exam), 'exam');
            return self::commit($db, $actor, $contactId, $hits, self::langFor($db, $actor));
        } catch (\Throwable $e) {
            error_log('Training awards: onExamSubmitted failed: ' . get_class($e));
            return [];
        }
    }

    /**
     * The completion rules for one contact, from the current valid-completion set. Throws on a
     * database failure (callers that must not throw wrap it). @return list<array> NEW AwardPublic rows
     */
    public static function evaluateContact(\mysqli $db, array $actor, int $contactId, string $trigger, ?int $completionId = null): array
    {
        if ($contactId < 1 || !self::ready($db)) {
            return [];
        }
        return self::evaluateWith($db, AwardFacts::get($db), $actor, $contactId, $trigger, $completionId);
    }

    /**
     * [S] Nightly, idempotent: re-applies the completion and exam rules for the given contacts, or
     * for every eligible contact plus everyone with a passed exam. Each contact is its own
     * transaction; one contact's failure is logged (class only) and the rest continue.
     *
     * @return int awards created
     */
    public static function backfill(\mysqli $db, ?array $contactIds = null): int
    {
        if (!self::ready($db)) {
            return 0;
        }
        $completionRules = self::rules($db, self::COMPLETION_RULES);
        $catalog = self::catalog($db);
        $hasPathBadges = $catalog['path_badges'] !== [];
        $examRules = self::rules($db, self::EXAM_RULES);
        $facts = AwardFacts::get($db);
        $useCompletions = $facts !== null && ($completionRules !== [] || $hasPathBadges);
        if (!$useCompletions && $examRules === []) {
            return 0;
        }

        $exams = $examRules === [] ? [] : self::passedExams($db, $contactIds);
        if ($contactIds === null) {
            $ids = $useCompletions ? AwardScope::eligibleContactIds($db) : [];
            $ids = array_merge($ids, array_keys($exams));
        } else {
            $ids = $contactIds;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        sort($ids);
        $firstFail = $examRules === [] ? [] : self::firstFailedExams($db, $contactIds);
        $actor = ['actor_type' => 'system', 'user_agent' => 'training_awards_backfill'];

        $created = 0;
        foreach ($ids as $cid) {
            try {
                $hits = [];
                if ($useCompletions) {
                    $hits = self::completionHits($completionRules, $catalog, $facts->validCourseIds($cid), 'backfill', null);
                }
                foreach ($exams[$cid] ?? [] as $exam) {
                    $failAt = $firstFail[$cid][$exam['course_id']] ?? null;
                    $hits = array_merge($hits, self::examHits($examRules, $exam, $failAt === null || $failAt > $exam['attempt_id'], 'backfill'));
                }
                if ($hits !== []) {
                    $created += count(self::commit($db, $actor, $cid, $hits, null));
                }
            } catch (\Throwable $e) {
                error_log('Training awards: backfill failed for one contact: ' . get_class($e));
            }
        }
        return $created;
    }

    /** [S] Nightly on_time_streak: RecordsFacts::onTimeMonths(contact) >= the rule's months. @return int awards created */
    public static function nightlyStreaks(\mysqli $db): int
    {
        if (!self::ready($db)) {
            return 0;
        }
        $rules = self::rules($db, ['on_time_streak']);
        $facts = AwardFacts::get($db);
        if ($rules === [] || $facts === null) {
            return 0;
        }
        $minMonths = min(array_map(static fn($r) => (int) ($r['params']['months'] ?? PHP_INT_MAX), $rules));
        $actor = ['actor_type' => 'system', 'user_agent' => 'training_awards_streaks'];
        $created = 0;
        foreach (AwardScope::eligibleContactIds($db) as $cid) {
            try {
                $months = $facts->onTimeMonths($cid);
                if ($months < 1 || $months < $minMonths) {
                    continue;
                }
                $hits = [];
                foreach ($rules as $r) {
                    $need = (int) ($r['params']['months'] ?? 0);
                    if ($need > 0 && $months >= $need) {
                        $hits[] = self::hit($r, 'on_time_streak', ['trigger' => 'streak', 'months' => $months], null);
                    }
                }
                if ($hits !== []) {
                    $created += count(self::commit($db, $actor, $cid, $hits, null));
                }
            } catch (\Throwable $e) {
                error_log('Training awards: streak failed for one contact: ' . get_class($e));
            }
        }
        return $created;
    }

    /**
     * [S] A manual award by an agent or a trainer. $by is the actor: ['actor_type'=>'user',
     * 'actor_user_id'=>N, 'user_agent'=>…] for an agent, or KioskCtx::eventBase() of a trainer
     * session (actor_type 'contact'). Only active, non-archived achievements of rule type
     * 'manual'. Scope key 'm' + 16 hex, so repeats are allowed. Department scope is the
     * caller's job (AwardScope::assertContact for agents). Own transaction (depth 0).
     *
     * @return array the award as AwardRepository::listRow() (award_id, AwardPublic fields, contact, source, reason, …)
     */
    public static function awardManual(\mysqli $db, int $achievementId, int $contactId, string $reason, array $by): array
    {
        if (!self::ready($db)) {
            throw new ApiException(503, 'unavailable', 'Awards are not set up yet. Run the database update.');
        }
        $reason = trim($reason);
        $len = mb_strlen($reason, 'UTF-8');
        if (!mb_check_encoding($reason, 'UTF-8') || $len < self::REASON_MIN || $len > self::REASON_MAX) {
            throw ApiException::validation(['reason' => 'Write a reason of ' . self::REASON_MIN . ' to ' . self::REASON_MAX . ' characters.']);
        }
        $a = $achievementId > 0 ? self::achievementRow($db, $achievementId) : null;
        if ($a === null || $a['archived']) {
            throw ApiException::validation(['achievement_id' => 'That achievement no longer exists.']);
        }
        if ($a['rule_type'] !== 'manual') {
            throw ApiException::validation(['achievement_id' => 'This achievement is awarded automatically.']);
        }
        if (!$a['active']) {
            throw ApiException::validation(['achievement_id' => 'This achievement is turned off.']);
        }
        $contact = $contactId > 0 ? Db::one($db, 'SELECT contact_id FROM contacts WHERE contact_id = ? AND contact_archived_at IS NULL', 'i', [$contactId]) : null;
        if ($contact === null) {
            throw ApiException::notFound('That person was not found.');
        }
        $actor = self::actor($by);
        $hit = self::hit($a, 'manual', ['trigger' => 'manual'], null);
        $hit['source'] = 'manual';
        $hit['scope'] = 'm' . bin2hex(random_bytes(8));
        $hit['reason'] = $reason;
        $hit['by_user'] = $actor['actor_type'] === 'user' ? ($actor['actor_user_id'] ?? null) : null;
        $hit['by_contact'] = $actor['actor_type'] === 'contact' ? ($actor['actor_contact_id'] ?? null) : null;
        if ($hit['by_user'] === null && $hit['by_contact'] === null) {
            throw new \InvalidArgumentException('AwardEngine::awardManual needs a user or contact actor');
        }
        $ids = self::insertAll($db, $actor, $contactId, [$hit]);
        if ($ids === []) {
            throw new \LogicException('AwardEngine::awardManual: nothing was inserted');
        }
        $rows = AwardRepository::listByIds($db, $ids);
        return $rows[0];
    }

    /** {uid, name, description, icon, color, awarded_at} from an award row (AwardRepository's SELECT). Icon and color re-validated. */
    public static function publicShape(array $row): array
    {
        $icon = (string) ($row['taward_snap_icon'] ?? '');
        $color = (string) ($row['taward_snap_color'] ?? '');
        $name = $row['tr_name'] ?? null;
        $desc = array_key_exists('tr_description', $row) && $row['tr_description'] !== null ? $row['tr_description'] : ($row['achievement_description'] ?? null);
        return [
            'uid' => (string) ($row['taward_achievement_uid'] ?? ''),
            'name' => (string) (($name !== null && $name !== '') ? $name : ($row['taward_snap_name'] ?? '')),
            'description' => ($desc === null || $desc === '') ? null : (string) $desc,
            'icon' => Icons::valid($icon) ? $icon : self::DEFAULT_ICON,
            'color' => preg_match(self::HEX, $color) === 1 ? $color : self::DEFAULT_COLOR,
            'awarded_at' => Clock::toIso(isset($row['taward_awarded_at_utc']) ? (string) $row['taward_awarded_at_utc'] : null, true),
        ];
    }

    // ------------------------------------------------------------------ rules

    private static function evaluateWith(\mysqli $db, ?RecordsFacts $facts, array $actor, int $contactId, string $trigger, ?int $completionId): array
    {
        if ($facts === null) {
            return [];
        }
        $rules = self::rules($db, self::COMPLETION_RULES);
        $catalog = self::catalog($db);
        if ($rules === [] && $catalog['path_badges'] === []) {
            return [];
        }
        $hits = self::completionHits($rules, $catalog, $facts->validCourseIds($contactId), $trigger, $completionId);
        return $hits === [] ? [] : self::commit($db, $actor, $contactId, $hits, self::langFor($db, $actor));
    }

    /**
     * @param list<array> $rules active achievements of the completion rule types
     * @param list<int>   $valid V
     * @return list<array> hits
     */
    private static function completionHits(array $rules, array $catalog, array $valid, string $trigger, ?int $completionId): array
    {
        $v = array_fill_keys(array_map('intval', $valid), true);
        if ($v === []) {
            return [];
        }
        $base = ['trigger' => $trigger] + ($completionId !== null ? ['completion_id' => $completionId] : []);
        $allIn = static function (array $ids) use ($v): bool {
            if ($ids === []) {
                return false;
            }
            foreach ($ids as $id) {
                if (!isset($v[$id])) {
                    return false;
                }
            }
            return true;
        };

        $hits = [];
        foreach ($rules as $r) {
            $p = $r['params'];
            switch ($r['rule_type']) {
                case 'course_completed':
                    $course = (int) ($p['course_id'] ?? 0);
                    if ($course > 0 && isset($v[$course])) {
                        $hits[] = self::hit($r, 'course_completed', $base + ['course_id' => $course], $course);
                    }
                    break;
                case 'category_completed':
                    $cat = (int) ($p['category_id'] ?? 0);
                    if ($cat > 0 && $allIn($catalog['categories'][$cat] ?? [])) {
                        $hits[] = self::hit($r, 'category_completed', $base + ['category_id' => $cat], null);
                    }
                    break;
                case 'path_completed':
                    $path = (int) ($p['path_id'] ?? 0);
                    if ($path > 0 && isset($catalog['paths'][$path]) && $allIn($catalog['paths'][$path])) {
                        $hits[] = self::hit($r, 'path_completed', $base + ['path_id' => $path], null);
                    }
                    break;
                case 'courses_completed_count':
                    $need = (int) ($p['count'] ?? 0);
                    $cat = isset($p['category_id']) ? (int) $p['category_id'] : null;
                    $have = 0;
                    foreach (array_keys($v) as $cid) {
                        if ($cat === null || ($catalog['course_category'][$cid] ?? null) === $cat) {
                            $have++;
                        }
                    }
                    if ($need > 0 && $have >= $need) {
                        $hits[] = self::hit($r, 'courses_completed_count', $base + ['count' => $have] + ($cat !== null ? ['category_id' => $cat] : []), null);
                    }
                    break;
            }
        }
        // A completed path awards its own badge (tpath_achievement_id), whatever that badge's rule type is.
        foreach ($catalog['path_badges'] as $pathId => $badge) {
            if ($allIn($catalog['paths'][$pathId] ?? [])) {
                $hits[] = self::hit($badge, 'path_completed', $base + ['path_id' => $pathId], null);
            }
        }
        return $hits;
    }

    /** @param array{attempt_id:int, course_id:int, attempt_number:int, score_pct:string, passed:bool} $exam */
    private static function examHits(array $rules, array $exam, bool $noEarlierFail, string $trigger): array
    {
        if (!$exam['passed']) {
            return [];
        }
        $hits = [];
        foreach ($rules as $r) {
            $only = isset($r['params']['course_id']) ? (int) $r['params']['course_id'] : null;
            if ($only !== null && $only !== $exam['course_id']) {
                continue;
            }
            $ev = ['trigger' => $trigger, 'course_id' => $exam['course_id'], 'attempt_id' => $exam['attempt_id']];
            if ($r['rule_type'] === 'perfect_score' && $exam['score_pct'] === '100.00') {
                $hits[] = self::hit($r, 'perfect_score', $ev, $exam['course_id']);
            } elseif ($r['rule_type'] === 'first_attempt_pass' && $exam['attempt_number'] === 1 && $noEarlierFail) {
                $hits[] = self::hit($r, 'first_attempt_pass', $ev, $exam['course_id']);
            }
        }
        return $hits;
    }

    private static function hit(array $achievement, string $ruleType, array $evidence, ?int $courseId): array
    {
        return [
            'a' => $achievement, 'rule_type' => $ruleType, 'evidence' => $evidence, 'course_id' => $courseId,
            'scope' => '', 'source' => 'rule', 'reason' => null, 'by_user' => null, 'by_contact' => null,
        ];
    }

    /**
     * The exam that was just submitted, from the stored attempt + result (text protocol) when
     * attempt_id names one of this contact's attempts; null when it is not a resulted exam.
     *
     * @return array{attempt_id:int, course_id:int, attempt_number:int, score_pct:string, passed:bool}|null
     */
    private static function examFacts(\mysqli $db, int $contactId, array $facts): ?array
    {
        $attemptId = (int) ($facts['attempt_id'] ?? 0);
        if ($attemptId > 0) {
            $row = Db::one($db, 'SELECT a.tattempt_contact_id, a.tattempt_course_id, a.tattempt_kind, a.tattempt_number,
                    CAST(r.tresult_score_pct AS CHAR) AS score_pct, r.tresult_passed
                FROM training_attempts a JOIN training_attempt_results r ON r.tresult_attempt_id = a.tattempt_id
                WHERE a.tattempt_id = ?', 'i', [$attemptId]);
            if ($row === null || (int) $row['tattempt_contact_id'] !== $contactId || $row['tattempt_kind'] !== 'exam') {
                return null;
            }
            return [
                'attempt_id' => $attemptId,
                'course_id' => (int) $row['tattempt_course_id'],
                'attempt_number' => (int) $row['tattempt_number'],
                'score_pct' => self::pct($row['score_pct']),
                'passed' => (int) $row['tresult_passed'] === 1,
            ];
        }
        return null;
    }

    /** No failed exam result for (contact, course) before this attempt, in any run. */
    private static function noEarlierFail(\mysqli $db, int $contactId, array $exam): bool
    {
        return Db::one($db, "SELECT a.tattempt_id FROM training_attempts a
                JOIN training_attempt_results r ON r.tresult_attempt_id = a.tattempt_id
            WHERE a.tattempt_contact_id = ? AND a.tattempt_course_id = ? AND a.tattempt_kind = 'exam'
              AND r.tresult_passed = 0 AND a.tattempt_id < ? LIMIT 1", 'iii', [$contactId, $exam['course_id'], $exam['attempt_id']]) === null;
    }

    /** Passed exam attempts by contact (backfill). @return array<int, list<array>> */
    private static function passedExams(\mysqli $db, ?array $contactIds): array
    {
        [$in, $types, $params] = self::inList('a.tattempt_contact_id', $contactIds);
        $out = [];
        foreach (Db::all($db, "SELECT a.tattempt_id, a.tattempt_contact_id, a.tattempt_course_id, a.tattempt_number,
                CAST(r.tresult_score_pct AS CHAR) AS score_pct
            FROM training_attempts a JOIN training_attempt_results r ON r.tresult_attempt_id = a.tattempt_id
            WHERE a.tattempt_kind = 'exam' AND r.tresult_passed = 1 $in
            ORDER BY a.tattempt_id", $types, $params) as $r) {
            $out[(int) $r['tattempt_contact_id']][] = [
                'attempt_id' => (int) $r['tattempt_id'],
                'course_id' => (int) $r['tattempt_course_id'],
                'attempt_number' => (int) $r['tattempt_number'],
                'score_pct' => self::pct($r['score_pct']),
                'passed' => true,
            ];
        }
        return $out;
    }

    /** First failed exam attempt id per contact and course (backfill). @return array<int, array<int, int>> */
    private static function firstFailedExams(\mysqli $db, ?array $contactIds): array
    {
        [$in, $types, $params] = self::inList('a.tattempt_contact_id', $contactIds);
        $out = [];
        foreach (Db::all($db, "SELECT a.tattempt_contact_id, a.tattempt_course_id, MIN(a.tattempt_id) AS first_fail
            FROM training_attempts a JOIN training_attempt_results r ON r.tresult_attempt_id = a.tattempt_id
            WHERE a.tattempt_kind = 'exam' AND r.tresult_passed = 0 $in
            GROUP BY a.tattempt_contact_id, a.tattempt_course_id", $types, $params) as $r) {
            $out[(int) $r['tattempt_contact_id']][(int) $r['tattempt_course_id']] = (int) $r['first_fail'];
        }
        return $out;
    }

    /** @return array{0:string, 1:string, 2:array} */
    private static function inList(string $col, ?array $ids): array
    {
        if ($ids === null) {
            return ['', '', []];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if ($ids === []) {
            return [' AND 0 = 1', '', []];
        }
        return [" AND $col IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids];
    }

    /** decimal(5,2) as the text protocol returns it ('100.00'). */
    private static function pct(mixed $v): string
    {
        return is_string($v) && preg_match('/^[0-9]{1,3}\.[0-9]{2}$/D', $v) === 1 ? $v : sprintf('%.2f', (float) $v);
    }

    // ------------------------------------------------------------------ definitions

    /**
     * Active, non-archived achievements of the given rule types, by id.
     *
     * @return list<array{id:int, uid:string, name:string, icon:string, color:string, rule_type:string, params:array, active:bool, archived:bool}>
     */
    private static function rules(\mysqli $db, array $types): array
    {
        if ($types === []) {
            return [];
        }
        $rows = Db::all($db, 'SELECT achievement_id, achievement_uid, achievement_name, achievement_icon, achievement_color,
                achievement_rule_type, achievement_rule_json, achievement_active, achievement_archived_at
            FROM training_achievements
            WHERE achievement_active = 1 AND achievement_archived_at IS NULL
              AND achievement_rule_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')
            ORDER BY achievement_id', str_repeat('s', count($types)), array_values($types));
        return array_map([self::class, 'shapeDefinition'], $rows);
    }

    private static function achievementRow(\mysqli $db, int $id): ?array
    {
        $row = Db::one($db, 'SELECT achievement_id, achievement_uid, achievement_name, achievement_icon, achievement_color,
                achievement_rule_type, achievement_rule_json, achievement_active, achievement_archived_at
            FROM training_achievements WHERE achievement_id = ?', 'i', [$id]);
        return $row === null ? null : self::shapeDefinition($row);
    }

    private static function shapeDefinition(array $r): array
    {
        $params = [];
        if ($r['achievement_rule_json'] !== null && $r['achievement_rule_json'] !== '') {
            $decoded = json_decode((string) $r['achievement_rule_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $val) {
                    if (is_int($val) || (is_string($val) && preg_match('/^[0-9]{1,10}$/D', $val) === 1)) {
                        $params[(string) $k] = (int) $val;
                    }
                }
            }
        }
        return [
            'id' => (int) $r['achievement_id'],
            'uid' => (string) $r['achievement_uid'],
            'name' => (string) $r['achievement_name'],
            'icon' => (string) $r['achievement_icon'],
            'color' => (string) $r['achievement_color'],
            'rule_type' => (string) $r['achievement_rule_type'],
            'params' => $params,
            'active' => (int) $r['achievement_active'] === 1,
            'archived' => $r['achievement_archived_at'] !== null,
        ];
    }

    /**
     * Course sets the completion rules compare V against, read once per call:
     *   categories:      category id => course ids (non-archived, with a current revision)
     *   course_category: course id   => category id (every course; for the count filter)
     *   paths:           non-archived path id => required non-archived course ids
     *   path_badges:     non-archived path id => its active, non-archived tpath_achievement_id definition
     */
    public static function catalog(\mysqli $db): array
    {
        $categories = [];
        $courseCategory = [];
        foreach (Db::all($db, 'SELECT course_id, course_category_id, course_current_revision_id, course_archived_at FROM training_courses') as $r) {
            $cid = (int) $r['course_id'];
            $cat = $r['course_category_id'] === null ? null : (int) $r['course_category_id'];
            $courseCategory[$cid] = $cat;
            if ($cat !== null && $r['course_archived_at'] === null && $r['course_current_revision_id'] !== null) {
                $categories[$cat][] = $cid;
            }
        }
        $paths = [];
        $badgeIds = [];
        foreach (Db::all($db, 'SELECT tpath_id, tpath_achievement_id FROM training_paths WHERE tpath_archived_at IS NULL ORDER BY tpath_id') as $r) {
            $paths[(int) $r['tpath_id']] = [];
            if ($r['tpath_achievement_id'] !== null) {
                $badgeIds[(int) $r['tpath_id']] = (int) $r['tpath_achievement_id'];
            }
        }
        foreach (Db::all($db, 'SELECT pc.tpcourse_path_id, pc.tpcourse_course_id
                FROM training_path_courses pc JOIN training_courses c ON c.course_id = pc.tpcourse_course_id
                WHERE pc.tpcourse_required = 1 AND c.course_archived_at IS NULL
                ORDER BY pc.tpcourse_path_id, pc.tpcourse_sort, pc.tpcourse_course_id') as $r) {
            $pid = (int) $r['tpcourse_path_id'];
            if (isset($paths[$pid])) {
                $paths[$pid][] = (int) $r['tpcourse_course_id'];
            }
        }
        $badges = [];
        if ($badgeIds !== []) {
            $ids = array_values(array_unique($badgeIds));
            $defs = [];
            foreach (Db::all($db, 'SELECT achievement_id, achievement_uid, achievement_name, achievement_icon, achievement_color,
                    achievement_rule_type, achievement_rule_json, achievement_active, achievement_archived_at
                FROM training_achievements
                WHERE achievement_active = 1 AND achievement_archived_at IS NULL
                  AND achievement_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids) as $row) {
                $defs[(int) $row['achievement_id']] = self::shapeDefinition($row);
            }
            foreach ($badgeIds as $pid => $aid) {
                if (isset($defs[$aid])) {
                    $badges[$pid] = $defs[$aid];
                }
            }
        }
        return ['categories' => $categories, 'course_category' => $courseCategory, 'paths' => $paths, 'path_badges' => $badges];
    }

    // ------------------------------------------------------------------ writes

    /**
     * Inserts the hits the contact does not have yet, then their ledger events.
     *
     * @return list<array> AwardPublic of the new rows
     */
    private static function commit(\mysqli $db, array $actor, int $contactId, array $hits, ?string $lang): array
    {
        $ids = self::insertAll($db, self::actor($actor), $contactId, $hits);
        return $ids === [] ? [] : AwardRepository::publicByIds($db, $ids, $lang);
    }

    /** @return list<int> new taward ids */
    private static function insertAll(\mysqli $db, array $actor, int $contactId, array $hits): array
    {
        if (Db::depth() !== 0) {
            throw new \LogicException('AwardEngine writes run in their own transaction (call after commit, at depth 0)');
        }
        // One hit per rule award (a path badge may also match its own rule); achievement id order.
        $unique = [];
        foreach ($hits as $h) {
            $key = $h['source'] === 'manual' ? $h['scope'] : 'r' . $h['a']['id'];
            $unique[$key] ??= $h;
        }
        usort($unique, static fn($x, $y) => $x['a']['id'] <=> $y['a']['id']);
        $held = [];
        foreach (Db::all($db, "SELECT taward_achievement_id FROM " . self::TABLE . " WHERE taward_contact_id = ? AND taward_scope_key = ''", 'i', [$contactId]) as $r) {
            $held[(int) $r['taward_achievement_id']] = true;
        }
        $todo = array_values(array_filter($unique, static fn($h) => $h['source'] === 'manual' || !isset($held[$h['a']['id']])));
        if ($todo === []) {
            return [];
        }

        $kioskId = isset($actor['kiosk_id']) ? (int) $actor['kiosk_id'] : null;
        return Db::tx($db, static function () use ($db, $actor, $contactId, $todo, $kioskId): array {
            $now = Clock::nowUtc();
            $new = [];
            foreach ($todo as $h) {
                $a = $h['a'];
                $row = [
                    'taward_achievement_id' => (string) $a['id'],
                    'taward_achievement_uid' => $a['uid'],
                    'taward_contact_id' => (string) $contactId,
                    'taward_rule_type' => $h['rule_type'],
                    'taward_scope_key' => $h['scope'],
                    'taward_source' => $h['source'],
                    'taward_evidence_json' => Canonical::doc($h['evidence']),
                    'taward_reason' => $h['reason'],
                    'taward_awarded_by_user_id' => $h['by_user'] === null ? null : (string) (int) $h['by_user'],
                    'taward_awarded_by_contact_id' => $h['by_contact'] === null ? null : (string) (int) $h['by_contact'],
                    'taward_snap_name' => (string) Text::clip($a['name'], 100),
                    'taward_snap_icon' => Icons::valid($a['icon']) ? $a['icon'] : self::DEFAULT_ICON,
                    'taward_snap_color' => preg_match(self::HEX, $a['color']) === 1 ? $a['color'] : self::DEFAULT_COLOR,
                    'taward_awarded_at_utc' => $now,
                    'taward_kiosk_id' => ($kioskId !== null && $kioskId > 0) ? (string) $kioskId : null,
                ];
                try {
                    $ins = Hashed::insert($db, self::TABLE, $row);
                } catch (\mysqli_sql_exception $e) {
                    if ((int) $e->getCode() === 1062) {
                        continue;   // already has it (a concurrent evaluation won)
                    }
                    throw $e;
                }
                $new[] = ['id' => $ins['id'], 'sha' => $ins['sha'], 'hit' => $h];
            }
            foreach ($new as $n) {
                $h = $n['hit'];
                Ledger::append($db, $actor + [
                    'type' => 'achievement.awarded',
                    'subject_contact_id' => $contactId,
                    'course_id' => $h['course_id'],
                    'entity_type' => 'achievement_award',
                    'entity_id' => $n['id'],
                    'entity_sha256' => $n['sha'],
                    'payload' => [
                        'achievement_uid' => $h['a']['uid'],
                        'rule_type' => $h['rule_type'],
                        'source' => $h['source'],
                        'evidence' => $h['evidence'],
                    ],
                ]);
            }
            return array_map(static fn($n) => (int) $n['id'], $new);
        });
    }

    /** Ledger actor fields only (§0.9); anything else in the caller's array is ignored. */
    private static function actor(array $in): array
    {
        $out = [];
        foreach (self::ACTOR_KEYS as $k) {
            if (isset($in[$k]) && $in[$k] !== '') {
                $out[$k] = $in[$k];
            }
        }
        $out['actor_type'] = in_array($out['actor_type'] ?? null, ['user', 'contact', 'kiosk', 'system'], true) ? $out['actor_type'] : 'system';
        foreach (['actor_user_id', 'actor_contact_id', 'kiosk_id', 'ksess_id'] as $k) {
            if (isset($out[$k])) {
                $out[$k] = (int) $out[$k];
                if ($out[$k] < 1) {
                    unset($out[$k]);
                }
            }
        }
        if (isset($out['user_agent'])) {
            $out['user_agent'] = Text::clip((string) $out['user_agent'], 255);
        }
        return $out;
    }

    /** The display language for returned badges: $actor['lang'], else the ksess language, else null (base). */
    private static function langFor(\mysqli $db, array $actor): ?string
    {
        $lang = $actor['lang'] ?? null;
        if (is_string($lang) && preg_match('/^[a-z]{2}(-[a-z]{2})?$/D', $lang) === 1) {
            return $lang;
        }
        $ksess = isset($actor['ksess_id']) ? (int) $actor['ksess_id'] : 0;
        if ($ksess > 0) {
            $row = Db::one($db, 'SELECT ksess_language FROM training_kiosk_sessions WHERE ksess_id = ?', 'i', [$ksess]);
            if ($row !== null && preg_match('/^[a-z]{2}(-[a-z]{2})?$/D', (string) $row['ksess_language']) === 1) {
                return (string) $row['ksess_language'];
            }
        }
        return null;
    }

    /** training_achievement_awards exists (2.6.93 applied); memoised per database. */
    public static function ready(\mysqli $db): bool
    {
        $res = $db->query('SELECT DATABASE() AS d');
        $name = (string) ($res->fetch_assoc()['d'] ?? '');
        $res->free();
        if (!isset(self::$ready[$name])) {
            $row = Db::one($db, 'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', 's', [self::TABLE]);
            self::$ready[$name] = (int) ($row['n'] ?? 0) > 0;
        }
        return self::$ready[$name];
    }
}
