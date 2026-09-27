<?php

namespace ITFlow\Training\Upstream;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;

/**
 * Phase 3 learner data for Phase 5 (spec §3.1; Lane A). SQL on the P3 tables lives ONLY here.
 * Bound to the P3 schema on main (v1.14.0):
 *   training_attempts.tattempt_kind enum('standalone','exam','check')   - 'exam' and 'standalone' are
 *       graded quizzes (AttemptDTO kind 'exam'); 'check' is a lesson quick check (kind 'check').
 *       The raw value is also returned as raw_kind.
 *   training_attempt_results.tresult_duration_seconds (the spec draft said tresult_duration_s)
 *   training_attempt_answers.tanswer_presented / tanswer_selected: comma-joined option uids
 *       (selected sorted; '' = unanswered), decoded here into lists.
 *   training_achievement_awards: taward_snap_name, taward_source enum('rule','manual'),
 *       taward_awarded_at_utc. There is no revoke column (A-5 is LATER, hence L8).
 * Every method answers [] / null before the P3 migration (Schema::P3 / P3_AWARDS).
 */
final class LearnerGateway
{
    public const KIND_FILTERS = ['exam', 'check', 'all'];
    private const MAX_ATTEMPTS = 20000;

    public function __construct(private readonly \mysqli $db)
    {
    }

    /** Whether the attempt tables exist (item analysis shows available:false otherwise). */
    public function available(): bool
    {
        return Schema::has($this->db, Schema::P3);
    }

    /**
     * Submitted attempts of a course (a row in training_attempt_results), oldest first.
     * opts: revision_ids?: list<int>; kind: 'exam' (exam + standalone, the default) | 'check' | 'all';
     *       lang?: string (e.g. 'es'); since_utc?: 'Y-m-d H:i:s' (submitted at or after); limit?: int (<= 20000).
     *
     * @return list<array{attempt_id:int, contact_id:int, revision_id:int, lesson_uid:string, quiz_uid:string, kind:string,
     *   raw_kind:string, number:int, language:string, submitted_at_utc:string, score_pct:string, passed:bool, duration_s:int,
     *   answers:list<array{q:string, presented:list<string>, selected:list<string>, correct:bool, type:string, critical:bool}>}>
     */
    public function attemptsForCourse(int $courseId, array $opts): array
    {
        if ($courseId < 1 || !$this->available()) {
            return [];
        }
        $kind = (string) ($opts['kind'] ?? 'exam');
        if (!in_array($kind, self::KIND_FILTERS, true)) {
            throw new \InvalidArgumentException("LearnerGateway: bad kind '$kind'");
        }
        $sql = 'SELECT t.tattempt_id, t.tattempt_contact_id, t.tattempt_revision_id, t.tattempt_lesson_uid, t.tattempt_quiz_uid, t.tattempt_kind,
                t.tattempt_number, t.tattempt_language, r.tresult_submitted_at_utc, r.tresult_score_pct, r.tresult_passed, r.tresult_duration_seconds
            FROM training_attempts t
            JOIN training_attempt_results r ON r.tresult_attempt_id = t.tattempt_id
            WHERE t.tattempt_course_id = ?';
        $types = 'i';
        $params = [$courseId];
        if ($kind === 'exam') {
            $sql .= " AND t.tattempt_kind IN ('exam', 'standalone')";
        } elseif ($kind === 'check') {
            $sql .= " AND t.tattempt_kind = 'check'";
        }
        $revs = array_values(array_unique(array_filter(array_map('intval', (array) ($opts['revision_ids'] ?? [])), static fn($i) => $i > 0)));
        if (array_key_exists('revision_ids', $opts) && $opts['revision_ids'] !== null && $revs === []) {
            return [];
        }
        if ($revs !== []) {
            $sql .= ' AND t.tattempt_revision_id IN (' . implode(',', array_fill(0, count($revs), '?')) . ')';
            $types .= str_repeat('i', count($revs));
            array_push($params, ...$revs);
        }
        if (isset($opts['lang']) && $opts['lang'] !== null && $opts['lang'] !== '') {
            $sql .= ' AND t.tattempt_language = ?';
            $types .= 's';
            $params[] = (string) $opts['lang'];
        }
        if (isset($opts['since_utc']) && $opts['since_utc'] !== null && $opts['since_utc'] !== '') {
            $sql .= ' AND r.tresult_submitted_at_utc >= ?';
            $types .= 's';
            $params[] = (string) $opts['since_utc'];
        }
        $limit = max(1, min(self::MAX_ATTEMPTS, (int) ($opts['limit'] ?? self::MAX_ATTEMPTS)));
        $sql .= ' ORDER BY r.tresult_submitted_at_utc, t.tattempt_id LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $out = [];
        foreach (Db::all($this->db, $sql, $types, $params) as $r) {
            $raw = (string) $r['tattempt_kind'];
            $out[(int) $r['tattempt_id']] = [
                'attempt_id' => (int) $r['tattempt_id'],
                'contact_id' => (int) $r['tattempt_contact_id'],
                'revision_id' => (int) $r['tattempt_revision_id'],
                'lesson_uid' => (string) $r['tattempt_lesson_uid'],
                'quiz_uid' => (string) $r['tattempt_quiz_uid'],
                'kind' => $raw === 'check' ? 'check' : 'exam',
                'raw_kind' => $raw,
                'number' => (int) $r['tattempt_number'],
                'language' => (string) $r['tattempt_language'],
                'submitted_at_utc' => (string) $r['tresult_submitted_at_utc'],
                'score_pct' => sprintf('%.2f', (float) $r['tresult_score_pct']),
                'passed' => (int) $r['tresult_passed'] === 1,
                'duration_s' => (int) $r['tresult_duration_seconds'],
                'answers' => [],
            ];
        }
        if ($out === []) {
            return [];
        }
        foreach (array_chunk(array_keys($out), 500) as $chunk) {
            $rows = Db::all($this->db, 'SELECT tanswer_attempt_id, tanswer_question_uid, tanswer_presented, tanswer_selected, tanswer_is_correct,
                    tanswer_type, tanswer_critical
                FROM training_attempt_answers WHERE tanswer_attempt_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                ORDER BY tanswer_attempt_id, tanswer_position', str_repeat('i', count($chunk)), $chunk);
            foreach ($rows as $a) {
                $out[(int) $a['tanswer_attempt_id']]['answers'][] = [
                    'q' => (string) $a['tanswer_question_uid'],
                    'presented' => self::uidList((string) $a['tanswer_presented']),
                    'selected' => self::uidList((string) $a['tanswer_selected']),
                    'correct' => (int) $a['tanswer_is_correct'] === 1,
                    'type' => (string) $a['tanswer_type'],
                    'critical' => (int) $a['tanswer_critical'] === 1,
                ];
            }
        }
        return array_values($out);
    }

    /**
     * Awards of the achievements in $achievementIds, awarded at/after $sinceUtc, with no outbox create
     * row for ($targetKey, $mode). For the certification target ($mode 'skill') only achievements mapped
     * to an Odoo skill on THIS target. ORDER BY award id. [] before the P3 awards or P5 migrations.
     *
     * @param list<int> $achievementIds
     * @return list<array{award_id:int, contact_id:int, achievement_id:int}>
     */
    public function awardCandidates(string $sinceUtc, string $targetKey, array $achievementIds, int $limit, string $mode = 'resume'): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $achievementIds), static fn($i) => $i > 0)));
        if ($ids === [] || !Schema::has($this->db, Schema::P3_AWARDS) || !Schema::has($this->db, Schema::P5) || !in_array($mode, ['resume', 'skill', 'note'], true)) {
            return [];
        }
        $skill = $mode === 'skill'
            ? " AND EXISTS (SELECT 1 FROM training_odoo_map m WHERE m.tomap_entity = 'achievement' AND m.tomap_entity_id = w.taward_achievement_id
                            AND m.tomap_odoo_skill_id IS NOT NULL AND m.tomap_target_key = ?)"
            : '';
        $rows = Db::all($this->db, "SELECT w.taward_id, w.taward_contact_id, w.taward_achievement_id
            FROM training_achievement_awards w
            WHERE w.taward_achievement_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
              AND w.taward_awarded_at_utc >= ?$skill
              AND NOT EXISTS (SELECT 1 FROM training_odoo_outbox o WHERE o.todoo_target_key = ? AND o.todoo_source_type = 'award'
                              AND o.todoo_source_id = w.taward_id AND o.todoo_action = 'create' AND o.todoo_mode = ?)
            ORDER BY w.taward_id LIMIT ?", str_repeat('i', count($ids)) . ($mode === 'skill' ? 'ssssi' : 'sssi'),
            array_merge($ids, $mode === 'skill' ? [$sinceUtc, $targetKey, $targetKey, $mode] : [$sinceUtc, $targetKey, $mode], [max(1, min(5000, $limit))]));
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['award_id' => (int) $r['taward_id'], 'contact_id' => (int) $r['taward_contact_id'], 'achievement_id' => (int) $r['taward_achievement_id']];
        }
        return $out;
    }

    /**
     * Awards the certification target leaves out because their achievement (one of $achievementIds, the ones switched to
     * "Send to Odoo") has no Odoo skill mapped on this target: per achievement, the awards that would otherwise be sent.
     *
     * @param list<int> $achievementIds
     * @return list<array{achievement_id:int, name:string, n:int}>
     */
    public function unmappedSkillAchievements(string $sinceUtc, string $targetKey, array $achievementIds, int $limit = 20): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $achievementIds), static fn($i) => $i > 0)));
        if ($ids === [] || !Schema::has($this->db, Schema::P3_AWARDS) || !Schema::has($this->db, Schema::P5)) {
            return [];
        }
        $out = [];
        foreach (Db::all($this->db, "SELECT w.taward_achievement_id AS id, MAX(w.taward_snap_name) AS name, COUNT(*) AS n
            FROM training_achievement_awards w
            LEFT JOIN training_odoo_map m ON m.tomap_entity = 'achievement' AND m.tomap_entity_id = w.taward_achievement_id
            WHERE w.taward_achievement_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
              AND w.taward_awarded_at_utc >= ?
              AND (m.tomap_odoo_skill_id IS NULL OR m.tomap_target_key IS NULL OR m.tomap_target_key <> ?)
              AND NOT EXISTS (SELECT 1 FROM training_odoo_outbox o WHERE o.todoo_target_key = ? AND o.todoo_source_type = 'award'
                              AND o.todoo_source_id = w.taward_id AND o.todoo_action = 'create' AND o.todoo_mode = 'skill')
            GROUP BY w.taward_achievement_id ORDER BY n DESC, w.taward_achievement_id LIMIT ?", str_repeat('i', count($ids)) . 'sssi',
            array_merge($ids, [$sinceUtc, $targetKey, $targetKey, max(1, min(200, $limit))])) as $r) {
            $out[] = ['achievement_id' => (int) $r['id'], 'name' => (string) $r['name'], 'n' => (int) $r['n']];
        }
        return $out;
    }

    /** @return array{award_id:int, contact_id:int, achievement_id:int, achievement_name:string, awarded_on:string}|null */
    public function awardForPush(int $awardId): ?array
    {
        if ($awardId < 1 || !Schema::has($this->db, Schema::P3_AWARDS)) {
            return null;
        }
        $r = Db::one($this->db, 'SELECT taward_id, taward_contact_id, taward_achievement_id, taward_snap_name, taward_awarded_at_utc
            FROM training_achievement_awards WHERE taward_id = ?', 'i', [$awardId]);
        if ($r === null) {
            return null;
        }
        return [
            'award_id' => (int) $r['taward_id'],
            'contact_id' => (int) $r['taward_contact_id'],
            'achievement_id' => (int) $r['taward_achievement_id'],
            'achievement_name' => (string) $r['taward_snap_name'],
            'awarded_on' => Clock::localDate((string) $r['taward_awarded_at_utc']),
        ];
    }

    /** @return list<array{name:string, awarded_on:string, how:'automatic'|'manual', reason:?string}> newest first */
    public function awardsForContact(int $contactId): array
    {
        if ($contactId < 1 || !Schema::has($this->db, Schema::P3_AWARDS)) {
            return [];
        }
        $out = [];
        foreach (Db::all($this->db, 'SELECT taward_snap_name, taward_source, taward_reason, taward_awarded_at_utc
                FROM training_achievement_awards WHERE taward_contact_id = ? ORDER BY taward_awarded_at_utc DESC, taward_id DESC', 'i', [$contactId]) as $r) {
            $out[] = [
                'name' => (string) $r['taward_snap_name'],
                'awarded_on' => Clock::localDate((string) $r['taward_awarded_at_utc']),
                'how' => (string) $r['taward_source'] === 'manual' ? 'manual' : 'automatic',
                'reason' => $r['taward_reason'] === null || $r['taward_reason'] === '' ? null : (string) $r['taward_reason'],
            ];
        }
        return $out;
    }

    /** 'a,b,c' => ['a','b','c']; '' => []. */
    private static function uidList(string $s): array
    {
        if ($s === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $s)), static fn($x) => $x !== ''));
    }
}
