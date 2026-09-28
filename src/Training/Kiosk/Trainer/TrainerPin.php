<?php

namespace ITFlow\Training\Kiosk\Trainer;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Pin\PinService;
use ITFlow\Training\Kiosk\Pin\TrainerPinService;

/**
 * PIN step-ups for trainer mode (P3 spec §3.2 "stepUp", §3.6, §8 "Trainer power").
 *
 * Every record-creating trainer action re-enters a PIN here - trainer purposes (trainer_exit,
 * trainer_finalize, trainer_action, evaluator, handoff_cancel; Pin\PinService::TRAINER_PURPOSES)
 * check the TRAINER'S OWN trainer PIN (Pin\TrainerPinService, 2.6.104: training_trainers, never
 * training_learner_credentials) and skip the kiosk cooldown and global pause but keep the
 * per-person locks; the EMPLOYEE purpose 'evaluatee' is different in kind - it is the person
 * being evaluated re-entering THEIR OWN learner/Odoo PIN (Pin\PinService::stepUp(), untouched),
 * not a trainer credential at all, and does not skip anything. A result other than ok becomes
 * the §4.4 error. The PIN is never logged, stored or echoed: callers pass it straight through,
 * unset() it afterwards, and pad the response in a finally (§0.6) with TrainerPin::pad().
 */
final class TrainerPin
{
    public const MIN_RESPONSE_MS = PinService::MIN_RESPONSE_MS;

    /** Lane K2's PIN service ships in the same build. */
    public static function available(): bool
    {
        return true;
    }

    /**
     * Re-enter a PIN or throw the §4.4 error for the result. $contactId is the trainer's own
     * contact id for every TRAINER_PURPOSES purpose, or the evaluatee's for 'evaluatee' - callers
     * decide which, this just routes to the matching credential.
     */
    public static function require(KioskCtx $k, int $contactId, mixed $pin, string $purpose): void
    {
        $result = in_array($purpose, PinService::TRAINER_PURPOSES, true)
            ? (new TrainerPinService($k))->stepUp($contactId, $pin, $purpose)
            : (new PinService($k))->stepUp($contactId, $pin, $purpose);
        unset($pin);
        self::assertOk($result);
    }

    /** Maps a K2 PinResult to the §4.4 error (nothing when ok). */
    public static function assertOk(object $r): void
    {
        $status = (string) ($r->status ?? '');
        $minutes = isset($r->lockMinutes) && $r->lockMinutes !== null ? (int) $r->lockMinutes : null;
        switch ($status) {
            case 'ok':
                return;
            case 'wrong':
                $data = isset($r->triesLeft) && $r->triesLeft !== null ? ['tries_left' => (int) $r->triesLeft] : [];
                throw new ApiException(422, 'pin_wrong', 'PIN not accepted.', [], $data);
            case 'format':
                throw new ApiException(422, 'pin_format', 'Enter the PIN with the number keys.');
            case 'locked':
                throw new ApiException(423, 'pin_locked', 'This PIN is locked for now.', [], ['minutes' => max(1, (int) $minutes)]);
            case 'locked_hard':
                throw new ApiException(423, 'pin_locked_hard', 'This PIN is locked. Ask an administrator.');
            case 'paused':
                throw new ApiException(429, 'paused', 'Sign-in is paused for a few minutes.', [], ['minutes' => max(1, (int) $minutes)]);
            case 'cooldown':
                throw new ApiException(429, 'kiosk_cooldown', 'This device paused PIN entry for a few minutes.', [], ['minutes' => max(1, (int) $minutes)]);
            case 'setup_needed':
                throw new ApiException(409, 'setup_needed', 'This person has no training PIN yet.');
            case 'busy':
                throw new ApiException(409, 'busy', 'Busy - try again.');
            case 'unavailable':
                $reason = isset($r->reason) && is_string($r->reason) && in_array($r->reason, ['odoo', 'odoo_auth', 'link_check', 'disabled'], true) ? $r->reason : 'odoo';
                throw new ApiException(503, 'signin_unavailable', 'PIN checks are not available right now.', [], ['reason' => $reason]);
            default:
                throw new ApiException(503, 'signin_unavailable', 'PIN checks are not available right now.', [], ['reason' => 'disabled']);
        }
    }

    /** Pads a PIN-verifying response to >= 800 ms from the request start (K2's pad). */
    public static function pad(KioskCtx $k): void
    {
        PinService::pad($k->startedNs);
    }
}
