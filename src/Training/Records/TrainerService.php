<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\People\Scope;

/**
 * Trainers and evaluators (S4; People › Trainers). A trainer is a contact with a
 * training_trainers row: what they may do (train, evaluate, and the Phase 3 kiosk flags), which
 * courses (all, or the listed ones) and which departments (all, or the listed ones).
 *
 * Mutable, so every change is a trainer.changed ledger event naming the changed fields.
 * Saves use the row's version for optimistic concurrency (409 conflict). Level 3 at the edge.
 */
final class TrainerService
{
    public const FLAGS = ['can_train', 'can_evaluate', 'can_setup_pins', 'can_unlock', 'can_view_team'];

    private const COLS = 'trainer_contact_id, trainer_user_id, trainer_title, trainer_can_train, trainer_can_evaluate, trainer_can_setup_pins,
        trainer_can_unlock, trainer_can_view_team, trainer_all_courses, trainer_all_departments, trainer_qualifications, trainer_active,
        trainer_version';

    /**
     * The trainer PIN's status only (2.6.104) - never the hash. Read-only here: save() never
     * writes these, and never includes them in its "what changed" ledger diff (Kiosk\Pin\
     * TrainerCredentialRepo::setPin() is the only writer, with its own pin.set ledger event).
     */
    private const PIN_COLS = 'trainer_pin_hash, trainer_pin_locked_until_utc, trainer_pin_hard_locked, trainer_pin_set_at_utc, trainer_pin_set_method';

    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * Trainers the viewer may see: all for an all-scope viewer; otherwise trainers whose own
     * department is in scope or who train in-scope departments (listed, or all departments).
     *
     * @return list<array> Trainer (spec §4.1)
     */
    public function list(?Scope $s = null): array
    {
        $s ??= Scope::forCtx($this->c);
        if ($s->isNone()) {
            return [];
        }
        $rows = Db::all($this->c->db, 'SELECT ' . self::COLS . ', ' . self::PIN_COLS . ', c.contact_client_id FROM training_trainers t
            JOIN contacts c ON c.contact_id = t.trainer_contact_id ORDER BY c.contact_name, t.trainer_contact_id');
        $ids = array_map(static fn($r) => (int) $r['trainer_contact_id'], $rows);
        [$courses, $depts] = $this->links($ids);
        $out = [];
        $refs = PersonRefs::load($this->c->db, $ids);
        foreach ($rows as $r) {
            $id = (int) $r['trainer_contact_id'];
            if (!$s->isAll()) {
                $visible = $s->allows((int) $r['contact_client_id']) || (int) $r['trainer_all_departments'] === 1;
                foreach ($depts[$id] ?? [] as $cl) {
                    $visible = $visible || $s->allows($cl);
                }
                if (!$visible) {
                    continue;
                }
            }
            $out[] = self::shape($r, $refs[$id] ?? null, $courses[$id] ?? [], $depts[$id] ?? []);
        }
        return $out;
    }

    public function get(int $contactId): ?array
    {
        $r = Db::one($this->c->db, 'SELECT ' . self::COLS . ', ' . self::PIN_COLS . ' FROM training_trainers WHERE trainer_contact_id = ?', 'i', [$contactId]);
        if ($r === null) {
            return null;
        }
        [$courses, $depts] = $this->links([$contactId]);
        return self::shape($r, PersonRefs::load($this->c->db, [$contactId])[$contactId] ?? null, $courses[$contactId] ?? [], $depts[$contactId] ?? []);
    }

    /**
     * Creates or updates a trainer. $f: title, flags{...}, all_courses, all_departments, course_ids,
     * client_ids, qualifications, active (all typed by the Action). Create when no row exists
     * ($version null or 0); otherwise $version must match (409 conflict).
     */
    public function save(int $contactId, ?int $version, array $f): array
    {
        $db = $this->c->db;
        $contact = Db::one($db, 'SELECT contact_id, contact_user_id, contact_archived_at FROM contacts WHERE contact_id = ?', 'i', [$contactId]);
        if ($contact === null) {
            throw ApiException::notFound('That person was not found.');
        }
        $courseIds = self::ids($f['course_ids'] ?? []);
        $clientIds = self::ids($f['client_ids'] ?? []);
        if ($courseIds !== []) {
            $in = implode(',', array_fill(0, count($courseIds), '?'));
            $n = (int) (Db::one($db, "SELECT COUNT(*) AS n FROM training_courses WHERE course_id IN ($in)", str_repeat('i', count($courseIds)), $courseIds)['n'] ?? 0);
            if ($n !== count($courseIds)) {
                throw ApiException::validation(['course_ids' => 'One of these courses no longer exists.']);
            }
        }
        if ($clientIds !== []) {
            $in = implode(',', array_fill(0, count($clientIds), '?'));
            $n = (int) (Db::one($db, "SELECT COUNT(*) AS n FROM clients WHERE client_id IN ($in)", str_repeat('i', count($clientIds)), $clientIds)['n'] ?? 0);
            if ($n !== count($clientIds)) {
                throw ApiException::validation(['client_ids' => 'One of these departments no longer exists.']);
            }
        }
        $flags = is_array($f['flags'] ?? null) ? $f['flags'] : [];
        $new = [
            'trainer_user_id' => (int) $contact['contact_user_id'] > 0 ? (int) $contact['contact_user_id'] : null,
            'trainer_title' => self::text($f['title'] ?? null, 100, 'title'),
            'trainer_can_train' => !empty($flags['can_train']) ? 1 : 0,
            'trainer_can_evaluate' => !empty($flags['can_evaluate']) ? 1 : 0,
            'trainer_can_setup_pins' => !empty($flags['can_setup_pins']) ? 1 : 0,
            'trainer_can_unlock' => !empty($flags['can_unlock']) ? 1 : 0,
            'trainer_can_view_team' => !empty($flags['can_view_team']) ? 1 : 0,
            'trainer_all_courses' => !empty($f['all_courses']) ? 1 : 0,
            'trainer_all_departments' => !empty($f['all_departments']) ? 1 : 0,
            'trainer_qualifications' => self::text($f['qualifications'] ?? null, 2000, 'qualifications'),
            'trainer_active' => !array_key_exists('active', $f) || !empty($f['active']) ? 1 : 0,
        ];
        if ($new['trainer_active'] === 1 && $contact['contact_archived_at'] !== null) {
            throw ApiException::validation(['active' => 'This person is archived. Restore the contact first.']);
        }

        Db::tx($db, function () use ($db, $contactId, $version, $new, $courseIds, $clientIds): void {
            $old = Db::one($db, 'SELECT ' . self::COLS . ' FROM training_trainers WHERE trainer_contact_id = ? FOR UPDATE', 'i', [$contactId]);
            $changed = [];
            if ($old === null) {
                if ($version !== null && $version !== 0) {
                    throw ApiException::conflict([], 'This trainer was removed in another tab. Reload.');
                }
                $cols = array_keys($new);
                Db::exec($db, 'INSERT INTO training_trainers (trainer_contact_id, ' . implode(', ', $cols) . ', trainer_version, trainer_added_by)
                    VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', 0, ?)',
                    'i' . str_repeat('s', count($cols)) . 'i', array_merge([$contactId], array_map(static fn($x) => $x === null ? null : (string) $x, array_values($new)), [max(0, $this->c->userId)]));
                $changed = ['created'];
            } else {
                if ($version === null || (int) $old['trainer_version'] !== $version) {
                    throw ApiException::conflict($this->get($contactId) ?? [], 'Someone else changed this trainer. Reload to see their changes.');
                }
                foreach ($new as $k => $val) {
                    $was = $old[$k] === null ? null : (string) $old[$k];
                    if ($was !== ($val === null ? null : (string) $val)) {
                        $changed[] = substr($k, strlen('trainer_'));
                    }
                }
                $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($new)));
                Db::exec($db, "UPDATE training_trainers SET $sets, trainer_version = trainer_version + 1 WHERE trainer_contact_id = ?",
                    str_repeat('s', count($new)) . 'i', array_merge(array_map(static fn($x) => $x === null ? null : (string) $x, array_values($new)), [$contactId]));
            }
            [$oldCourses, $oldDepts] = $this->links([$contactId]);
            if (($oldCourses[$contactId] ?? []) != $courseIds) {
                $changed[] = 'course_ids';
            }
            if (($oldDepts[$contactId] ?? []) != $clientIds) {
                $changed[] = 'client_ids';
            }
            Db::exec($db, 'DELETE FROM training_trainer_courses WHERE ttcourse_contact_id = ?', 'i', [$contactId]);
            foreach ($courseIds as $cid) {
                Db::exec($db, 'INSERT INTO training_trainer_courses (ttcourse_contact_id, ttcourse_course_id) VALUES (?, ?)', 'ii', [$contactId, $cid]);
            }
            Db::exec($db, 'DELETE FROM training_trainer_departments WHERE ttdept_contact_id = ?', 'i', [$contactId]);
            foreach ($clientIds as $cl) {
                Db::exec($db, 'INSERT INTO training_trainer_departments (ttdept_contact_id, ttdept_client_id) VALUES (?, ?)', 'ii', [$contactId, $cl]);
            }
            if ($changed === []) {
                return;
            }
            Ledger::append($db, $this->actor() + [
                'type' => 'trainer.changed',
                'subject_contact_id' => $contactId,
                'entity_type' => 'trainer',
                'entity_id' => $contactId,
                'payload' => ['changed' => array_values(array_unique($changed)), 'active' => $new['trainer_active'] === 1],
            ]);
        });
        AfterCommit::log('Edit', "Saved training trainer for contact #$contactId", $contactId);
        return $this->get($contactId) ?? throw new \RuntimeException('trainer row vanished');
    }

    public function deactivate(int $contactId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $contactId): void {
            $old = Db::one($db, 'SELECT trainer_active FROM training_trainers WHERE trainer_contact_id = ? FOR UPDATE', 'i', [$contactId]);
            if ($old === null) {
                throw ApiException::notFound('That trainer was not found.');
            }
            if ((int) $old['trainer_active'] === 0) {
                return;
            }
            Db::exec($db, 'UPDATE training_trainers SET trainer_active = 0, trainer_version = trainer_version + 1 WHERE trainer_contact_id = ?', 'i', [$contactId]);
            Ledger::append($db, $this->actor() + [
                'type' => 'trainer.changed',
                'subject_contact_id' => $contactId,
                'entity_type' => 'trainer',
                'entity_id' => $contactId,
                'payload' => ['changed' => ['active'], 'active' => false],
            ]);
        });
    }

    /**
     * An active trainer who may run sessions: {contact_id, name, user_id} or null.
     */
    public static function trainerFor(\mysqli $db, int $contactId): ?array
    {
        $r = Db::one($db, 'SELECT t.trainer_contact_id, t.trainer_user_id, c.contact_name, c.contact_user_id FROM training_trainers t
            JOIN contacts c ON c.contact_id = t.trainer_contact_id
            WHERE t.trainer_contact_id = ? AND t.trainer_active = 1 AND t.trainer_can_train = 1 AND c.contact_archived_at IS NULL', 'i', [$contactId]);
        return $r === null ? null : self::who($r);
    }

    /**
     * An active trainer who may evaluate $courseId (can_evaluate, and all courses or listed for it):
     * {contact_id, name, user_id} or null.
     */
    public static function evaluatorFor(\mysqli $db, int $contactId, int $courseId): ?array
    {
        $r = Db::one($db, 'SELECT t.trainer_contact_id, t.trainer_user_id, c.contact_name, c.contact_user_id FROM training_trainers t
            JOIN contacts c ON c.contact_id = t.trainer_contact_id
            WHERE t.trainer_contact_id = ? AND t.trainer_active = 1 AND t.trainer_can_evaluate = 1 AND c.contact_archived_at IS NULL
              AND (t.trainer_all_courses = 1 OR EXISTS (SELECT 1 FROM training_trainer_courses tc WHERE tc.ttcourse_contact_id = t.trainer_contact_id AND tc.ttcourse_course_id = ?))',
            'ii', [$contactId, $courseId]);
        return $r === null ? null : self::who($r);
    }

    /** trainer_title of a trainer contact, for the certificate signature line. */
    public static function titleOf(\mysqli $db, ?int $contactId): ?string
    {
        if ($contactId === null || $contactId < 1) {
            return null;
        }
        $r = Db::one($db, 'SELECT trainer_title FROM training_trainers WHERE trainer_contact_id = ?', 'i', [$contactId]);
        $t = $r['trainer_title'] ?? null;
        return $t === null || $t === '' ? null : (string) $t;
    }

    private static function who(array $r): array
    {
        $uid = $r['trainer_user_id'] !== null ? (int) $r['trainer_user_id'] : ((int) $r['contact_user_id'] > 0 ? (int) $r['contact_user_id'] : null);
        return ['contact_id' => (int) $r['trainer_contact_id'], 'name' => (string) $r['contact_name'], 'user_id' => $uid];
    }

    /** @return array{0:array<int, list<int>>, 1:array<int, list<int>>} contact => course ids, contact => client ids (sorted) */
    private function links(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        $courses = [];
        $depts = [];
        if ($ids === []) {
            return [$courses, $depts];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::all($this->c->db, "SELECT ttcourse_contact_id, ttcourse_course_id FROM training_trainer_courses WHERE ttcourse_contact_id IN ($in)
            ORDER BY ttcourse_course_id", str_repeat('i', count($ids)), $ids) as $r) {
            $courses[(int) $r['ttcourse_contact_id']][] = (int) $r['ttcourse_course_id'];
        }
        foreach (Db::all($this->c->db, "SELECT ttdept_contact_id, ttdept_client_id FROM training_trainer_departments WHERE ttdept_contact_id IN ($in)
            ORDER BY ttdept_client_id", str_repeat('i', count($ids)), $ids) as $r) {
            $depts[(int) $r['ttdept_contact_id']][] = (int) $r['ttdept_client_id'];
        }
        return [$courses, $depts];
    }

    private static function shape(array $r, ?array $person, array $courseIds, array $clientIds): array
    {
        $flags = [];
        foreach (self::FLAGS as $f) {
            $flags[$f] = (int) $r['trainer_' . $f] === 1;
        }
        return [
            'contact_id' => (int) $r['trainer_contact_id'],
            'person' => $person,
            'title' => $r['trainer_title'] === null ? null : (string) $r['trainer_title'],
            'flags' => $flags,
            'all_courses' => (int) $r['trainer_all_courses'] === 1,
            'all_departments' => (int) $r['trainer_all_departments'] === 1,
            'course_ids' => array_values($courseIds),
            'client_ids' => array_values($clientIds),
            'qualifications' => $r['trainer_qualifications'] === null ? null : (string) $r['trainer_qualifications'],
            'active' => (int) $r['trainer_active'] === 1,
            'version' => (int) $r['trainer_version'],
            // 2.6.104: this trainer's OWN kiosk sign-in PIN, completely separate from their
            // learner/Odoo PIN - status only, never the hash.
            'pin_set' => array_key_exists('trainer_pin_hash', $r) ? ($r['trainer_pin_hash'] !== null && $r['trainer_pin_hash'] !== '') : null,
            'pin_locked' => array_key_exists('trainer_pin_locked_until_utc', $r) && $r['trainer_pin_locked_until_utc'] !== null
                && $r['trainer_pin_locked_until_utc'] > gmdate('Y-m-d H:i:s'),
            'pin_hard_locked' => array_key_exists('trainer_pin_hard_locked', $r) && (int) $r['trainer_pin_hard_locked'] === 1,
            'pin_set_at' => array_key_exists('trainer_pin_set_at_utc', $r) && $r['trainer_pin_set_at_utc'] !== null ? Clock::toIso((string) $r['trainer_pin_set_at_utc'], true) : null,
            'pin_set_method' => $r['trainer_pin_set_method'] ?? null,
        ];
    }

    /** @return list<int> sorted, unique, positive */
    private static function ids(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = array_values(array_unique(array_filter(array_map('intval', $v), static fn($i) => $i > 0)));
        sort($out);
        return $out;
    }

    private static function text(mixed $v, int $max, string $field): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (!mb_check_encoding($v, 'UTF-8') || mb_strlen($v, 'UTF-8') > $max) {
            throw ApiException::validation([$field => "Too long (at most $max characters)."]);
        }
        return $v;
    }

    private function actor(): array
    {
        return [
            'actor_type' => $this->c->userId > 0 ? 'user' : 'system',
            'actor_user_id' => $this->c->userId > 0 ? $this->c->userId : null,
            'user_agent' => $this->c->userAgent,
        ];
    }
}
