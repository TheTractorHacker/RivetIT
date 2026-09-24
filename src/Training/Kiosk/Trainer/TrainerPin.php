<?php

namespace ITFlow\Training\Kiosk\Trainer;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Kiosk\Core\KioskCtx;

/**
 * PIN step-ups for trainer mode (P3 spec §3.2 "stepUp", §3.6, §8 "Trainer power").
 *
 * Every record-creating trainer action re-enters a PIN through lane K2's
 * Kiosk\Pin\PinService::stepUp() - trainer purposes (trainer_exit, trainer_finalize,
 * trainer_action, evaluator, handoff_cancel) skip the kiosk cooldown and global pause but
 * keep the per-person locks; the employee purposes (checkin, evaluatee) do not skip anything.
 * A result other than ok becomes the §4.4 error. The PIN is never logged, stored or echoed:
 * callers pass it straight through, unset() it afterwards, and pad the response in a finally
 * (§0.6) with TrainerPin::pad().
 */
final class TrainerPin
{
    public const PIN_SERVICE = '\\ITFlow\\Training\\Kiosk\\Pin\\PinService';
    public const MIN_RESPONSE_MS = 800;

    /** True when lane K2's PIN service is in this build. */
    public static function available(): bool
    {
        return class_exists(self::PIN_SERVICE);
    }

    /** Re-enter a PIN or throw the §4.4 error for the result. */
    public static function require(KioskCtx $k, int $contactId, mixed $pin, string $purpose): void
    {
        if (!self::available()) {
            throw new ApiException(503, 'signin_unavailable', 'PIN checks are not available on this device yet.', [], ['reason' => 'disabled']);
        }
        $cls = self::PIN_SERVICE;
        $result = (new $cls($k))->stepUp($contactId, $pin, $purpose);
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

    /** Pads a PIN-verifying response to >= 800 ms from the request start (K2's pad when present). */
    public static function pad(KioskCtx $k): void
    {
        $cls = self::PIN_SERVICE;
        if (class_exists($cls) && method_exists($cls, 'pad')) {
            $cls::pad($k->startedNs);
            return;
        }
        $elapsedMs = (hrtime(true) - $k->startedNs) / 1e6;
        if ($elapsedMs < self::MIN_RESPONSE_MS) {
            usleep((int) ((self::MIN_RESPONSE_MS - $elapsedMs) * 1000));
        }
    }
}
