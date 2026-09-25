<?php

namespace ITFlow\Training\Kiosk\Bridge;

use ITFlow\Training\Core\Db;

/**
 * Trainer facts for the kiosk (P3 spec §3.7, lane K5; consumed by K2 sign-in and K3).
 *
 * The only place Phase 3+4 reads Phase 2's trainer tables (training_trainers,
 * training_trainer_courses, training_trainer_departments, created by 2.6.92). Nothing is cached
 * across calls: trainer() re-reads the row every time, so a trainer deactivated in the office
 * loses kiosk powers on their very next request (v0 §6.4 T1 "every action re-checks").
 *
 * Scope rules (v0 §6.4, §12):
 *   - courses:     trainer_all_courses = 1, or the course is listed in training_trainer_courses;
 *   - departments: trainer_all_departments = 1, or the department is listed in
 *                  training_trainer_departments. A trainer with neither covers NO department
 *                  (fail closed) - their own department is not implied.
 * When the Phase 2 tables are missing (a build before 2.6.92) every answer is "not a trainer".
 */
final class TrainerBridge
{
    private const COLS = 'trainer_contact_id, trainer_user_id, trainer_title, trainer_can_train, trainer_can_evaluate, trainer_can_setup_pins,
        trainer_can_unlock, trainer_can_view_team, trainer_all_courses, trainer_all_departments, trainer_active';

    /** @var array<string, bool> database name => trainer tables present */
    private static array $ready = [];

    public function __construct(private readonly \mysqli $db)
    {
    }

    /** True when the Phase 2 trainer tables exist in this database (memoised per database). */
    public static function tablesReady(\mysqli $db): bool
    {
        $res = $db->query('SELECT DATABASE() AS d');
        $name = (string) (($res->fetch_assoc()['d'] ?? '') ?: '');
        $res->free();
        if (isset(self::$ready[$name])) {
            return self::$ready[$name];
        }
        $row = Db::one($db, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('training_trainers', 'training_trainer_courses', 'training_trainer_departments')");
        return self::$ready[$name] = ((int) ($row['n'] ?? 0)) === 3;
    }

    /**
     * The trainer row of a contact, or null (no row, or no Phase 2 tables).
     *
     * @return array{contact_id:int, user_id:?int, title:?string, active:bool, can_train:bool, can_evaluate:bool,
     *   can_setup_pins:bool, can_unlock:bool, can_view_team:bool, all_courses:bool, all_departments:bool,
     *   course_ids:list<int>, client_ids:list<int>}|null
     */
    public function trainer(int $cid): ?array
    {
        if ($cid < 1 || !self::tablesReady($this->db)) {
            return null;
        }
        $r = Db::one($this->db, 'SELECT ' . self::COLS . ' FROM training_trainers WHERE trainer_contact_id = ?', 'i', [$cid]);
        if ($r === null) {
            return null;
        }
        $courses = array_map(static fn($x) => (int) $x['ttcourse_course_id'],
            Db::all($this->db, 'SELECT ttcourse_course_id FROM training_trainer_courses WHERE ttcourse_contact_id = ? ORDER BY ttcourse_course_id', 'i', [$cid]));
        $depts = array_map(static fn($x) => (int) $x['ttdept_client_id'],
            Db::all($this->db, 'SELECT ttdept_client_id FROM training_trainer_departments WHERE ttdept_contact_id = ? ORDER BY ttdept_client_id', 'i', [$cid]));
        $b = static fn(string $k): bool => (int) ($r[$k] ?? 0) === 1;
        return [
            'contact_id' => $cid,
            'user_id' => $r['trainer_user_id'] === null ? null : (int) $r['trainer_user_id'],
            'title' => $r['trainer_title'] === null ? null : (string) $r['trainer_title'],
            'active' => $b('trainer_active'),
            'can_train' => $b('trainer_can_train'),
            'can_evaluate' => $b('trainer_can_evaluate'),
            'can_setup_pins' => $b('trainer_can_setup_pins'),
            'can_unlock' => $b('trainer_can_unlock'),
            'can_view_team' => $b('trainer_can_view_team'),
            'all_courses' => $b('trainer_all_courses'),
            'all_departments' => $b('trainer_all_departments'),
            'course_ids' => array_values(array_unique($courses)),
            'client_ids' => array_values(array_unique(array_filter($depts, static fn(int $d) => $d > 0))),
        ];
    }

    /** Active AND (can_train OR can_evaluate). False when Phase 2 is absent. K2 uses this for trainer sign-in and "trainers are local-PIN only". */
    public function isActiveTrainer(int $cid): bool
    {
        $t = $this->trainer($cid);
        return $t !== null && $t['active'] && ($t['can_train'] || $t['can_evaluate']);
    }

    /** Is there at least one active trainer or evaluator (the sign-in page shows its "Trainer sign-in" link only then)? */
    public function anyActive(): bool
    {
        if (!self::tablesReady($this->db)) {
            return false;
        }
        return Db::one($this->db, 'SELECT 1 AS ok FROM training_trainers WHERE trainer_active = 1 AND (trainer_can_train = 1 OR trainer_can_evaluate = 1) LIMIT 1') !== null;
    }

    /** May this trainer train / evaluate this course? */
    public function canCourse(array $t, int $courseId): bool
    {
        if ($courseId < 1 || empty($t['active'])) {
            return false;
        }
        return !empty($t['all_courses']) || in_array($courseId, array_map('intval', (array) ($t['course_ids'] ?? [])), true);
    }

    /** Is this department (clients.client_id) inside the trainer's scope? Department 0 ("no department") only with all_departments. */
    public function inScope(array $t, int $clientId): bool
    {
        if (empty($t['active'])) {
            return false;
        }
        if (!empty($t['all_departments'])) {
            return true;
        }
        return $clientId > 0 && in_array($clientId, array_map('intval', (array) ($t['client_ids'] ?? [])), true);
    }

    /**
     * The departments a trainer covers for SQL filters: null = all departments; [] = none.
     *
     * @return list<int>|null
     */
    public function scopeClientIds(array $t): ?array
    {
        if (empty($t['active'])) {
            return [];
        }
        return !empty($t['all_departments']) ? null : array_values(array_map('intval', (array) ($t['client_ids'] ?? [])));
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$ready = [];
    }
}
