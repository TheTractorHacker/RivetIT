<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Kiosk\Core\KioskKeys;

/**
 * Super-admin trainer PIN administration (2.6.104): direct set/reset, gated on a real
 * Administrator (Ctx::isAdmin / user_roles.role_is_admin), not just module_training_kiosk level -
 * the owner asked for "super admins" specifically. Mirrors Pin\PinAdmin's shape (a Ctx-driven
 * agent-side operation, PinHasher/PinPolicy reused unchanged) but writes training_trainers'
 * trainer_pin_* columns through Pin\TrainerCredentialRepo, never training_learner_credentials.
 */
final class TrainerPinAdmin
{
    private readonly PinHasher $hasher;

    public function __construct(private readonly Ctx $c, private readonly KioskKeys $keys)
    {
        $this->hasher = new PinHasher($keys);
    }

    /**
     * Sets (or resets) a trainer's PIN to an admin-chosen value: real edit/reset, not just
     * "revoke and start over" - the trainer can sign in with it immediately. Refuses the same
     * six-digit policy (PinPolicy, including "not the immediately-previous PIN") as every other
     * local training PIN.
     */
    public function setPin(int $contactId, mixed $pin, mixed $pin2): array
    {
        $db = $this->c->db;
        $row = TrainerCredentialRepo::load($db, $contactId);
        if ($row === null) {
            throw ApiException::notFound('That trainer was not found.');
        }
        if (!is_string($pin) || !is_string($pin2)) {
            throw ApiException::validation(['pin' => 'Enter a PIN with the number keys.']);
        }
        if (!hash_equals($pin, $pin2)) {
            throw new ApiException(422, 'pin_mismatch', "The two PINs don't match.");
        }
        $rule = PinPolicy::check($contactId, $pin, $this->hasher, $row['trainer_pin_prev_hash']);
        if ($rule !== null) {
            throw new ApiException(422, 'pin_policy', 'Pick a different PIN.', [], ['rule' => $rule]);
        }
        $hash = $this->hasher->hashPin($contactId, $pin);   // bcrypt BEFORE the transaction
        unset($pin, $pin2);
        TrainerCredentialRepo::setPin($db, $contactId, $hash, 'admin', $this->c->userId, $this->eventBase());
        return ['pin_set' => true];
    }

    private function eventBase(): array
    {
        return ['actor_type' => 'user', 'actor_user_id' => $this->c->userId, 'user_agent' => $this->c->userAgent];
    }
}
