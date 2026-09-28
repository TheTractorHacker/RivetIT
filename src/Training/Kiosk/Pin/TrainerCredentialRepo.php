<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * A trainer's OWN kiosk sign-in PIN (2.6.104; P3 spec §3.2 style, adapted): stored directly on
 * training_trainers (trainer_pin_* columns), a row Records\TrainerService already creates when
 * someone becomes a trainer - genuinely separate from that same person's learner/Odoo PIN in
 * training_learner_credentials (Kiosk\Pin\CredentialRepo). There is no "create" here: a trainer
 * row must already exist (Seam::isActiveTrainer said so) before anything in this file runs, and
 * a missing row is a bug, not a state to paper over.
 *
 * No Odoo source concept: a trainer PIN has no Odoo counterpart, so it is always local. Hashing
 * reuses Pin\PinHasher/Pin\PinPolicy completely unchanged (same pepper, same six-digit rules,
 * same DENY list) - the separation the owner asked for is the STORAGE (its own column, its own
 * table, its own lockout state, never touched by the learner reserve/verify/settle flow), not the
 * cryptographic material, and reusing the already-reviewed hasher/policy is safer than forking them.
 */
final class TrainerCredentialRepo
{
    public const COLUMNS = 'trainer_contact_id, trainer_user_id, trainer_active, trainer_pin_hash, trainer_pin_prev_hash,
        trainer_pin_failed_count, trainer_pin_locked_until_utc, trainer_pin_hard_locked, trainer_pin_last_success_at_utc,
        trainer_pin_set_at_utc, trainer_pin_set_method, trainer_pin_set_by_user_id';

    /** The trainer row's PIN columns, or null when there is no training_trainers row at all. */
    public static function load(\mysqli $db, int $contactId, bool $forUpdate = false): ?array
    {
        $row = Db::one($db, 'SELECT ' . self::COLUMNS . ' FROM training_trainers WHERE trainer_contact_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$contactId]);
        if ($row === null) {
            return null;
        }
        $row['has_pin'] = $row['trainer_pin_hash'] !== null && $row['trainer_pin_hash'] !== '';
        return $row;
    }

    /** The trainer row for a user's OWN agent login (self-service, agent/user/user_security.php), or null when they are not an active trainer. */
    public static function loadByUserId(\mysqli $db, int $userId, bool $forUpdate = false): ?array
    {
        $row = Db::one($db, 'SELECT ' . self::COLUMNS . ' FROM training_trainers WHERE trainer_user_id = ? AND trainer_active = 1'
            . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$userId]);
        if ($row === null) {
            return null;
        }
        $row['has_pin'] = $row['trainer_pin_hash'] !== null && $row['trainer_pin_hash'] !== '';
        return $row;
    }

    /**
     * Sets (or replaces) a trainer's PIN: the previous hash moves to trainer_pin_prev_hash (so
     * PinPolicy's "previous" rule can refuse it on the very next reset), every lock/failed-count
     * clears (a reset that left the person still locked out would defeat the point), and who set
     * it is recorded. $newHash is bcrypt, hashed by the caller BEFORE the transaction (PinHasher
     * never runs inside a lock). $method 'self' | 'admin'; $byUserId only for an admin reset.
     */
    public static function setPin(\mysqli $db, int $contactId, string $newHash, string $method, ?int $byUserId, array $ledgerBase): void
    {
        if (!in_array($method, ['self', 'admin'], true)) {
            throw new \InvalidArgumentException('TrainerCredentialRepo::setPin: bad method');
        }
        Db::tx($db, static function () use ($db, $contactId, $newHash, $method, $byUserId, $ledgerBase): void {
            $row = self::load($db, $contactId, true);
            if ($row === null) {
                throw new \RuntimeException('TrainerCredentialRepo: trainer row missing');
            }
            $now = KTime::now();
            Db::exec($db, 'UPDATE training_trainers SET trainer_pin_prev_hash = trainer_pin_hash, trainer_pin_hash = ?,
                    trainer_pin_failed_count = 0, trainer_pin_locked_until_utc = NULL, trainer_pin_hard_locked = 0,
                    trainer_pin_set_at_utc = ?, trainer_pin_set_method = ?, trainer_pin_set_by_user_id = ?
                WHERE trainer_contact_id = ?', 'sssii', [$newHash, $now, $method, $byUserId, $contactId]);
            Ledger::append($db, array_merge($ledgerBase, [
                'type' => 'pin.set',
                'subject_contact_id' => $contactId,
                'payload' => ['method' => $method, 'scope' => 'trainer'],
            ]));
        });
    }
}
