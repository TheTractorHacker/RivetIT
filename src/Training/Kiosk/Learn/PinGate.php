<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;

/**
 * The learner engine's PIN re-entry (ack_sign, attest) through lane K2's frozen
 * Kiosk\Pin\PinService::{stepUp, pad} (§9.2). The PIN is never logged, stored or echoed here;
 * callers unset() it after this call and pad the response in a finally (§0.6).
 *
 * stepUp() returns the verified source for the evidence ({source, odoo_employee_id}) or throws the
 * §4.4 error for the PinResult status. A hard lock on the session's own contact also ends the
 * kiosk session (PinService does it; repeated here idempotently) and clears the cookie.
 * Without K2's PinService (a partial deploy) it refuses with 503 signin_unavailable.
 */
final class PinGate
{
    public const PIN_SERVICE = 'ITFlow\\Training\\Kiosk\\Pin\\PinService';
    public const MIN_RESPONSE_MS = 800;

    /** @return array{source:string, odoo_employee_id:?int} */
    public static function stepUp(KioskCtx $k, mixed $pin, string $purpose): array
    {
        $cls = self::PIN_SERVICE;
        if (!class_exists($cls)) {
            throw new ApiException(503, 'signin_unavailable', 'PIN check is not available right now. See your trainer.', [], ['reason' => 'disabled']);
        }
        $res = (new $cls($k))->stepUp($k->contactId(), $pin, $purpose);
        unset($pin);
        $status = (string) ($res->status ?? 'unavailable');
        if ($status === 'ok') {
            $src = (string) ($res->source ?? ($k->ksess['ksess_pin_source'] ?? 'local'));
            $emp = $res->odooEmployeeId ?? ($k->ksess['ksess_odoo_employee_id'] ?? null);
            return ['source' => in_array($src, ['odoo', 'local'], true) ? $src : 'local', 'odoo_employee_id' => $emp === null ? null : (int) $emp];
        }
        if ($status === 'locked_hard' && $k->ksessId() !== null) {
            try {
                KioskAuth::endSession($k, (int) $k->ksessId(), 'pin_locked');
            } catch (\Throwable $e) {
                error_log('Kiosk PinGate: ' . get_class($e));
            }
            KioskAuth::clearSessionCookie();
        }
        throw self::error($status, $res->triesLeft ?? null, $res->lockMinutes ?? null, $res->reason ?? null);
    }

    /** The §4.4 error for a non-ok PinResult status. */
    public static function error(string $status, ?int $triesLeft = null, ?int $lockMinutes = null, ?string $reason = null): ApiException
    {
        return match ($status) {
            'wrong' => new ApiException(422, 'pin_wrong', 'PIN not accepted.', ['pin' => 'PIN not accepted.'],
                $triesLeft === null ? [] : ['tries_left' => $triesLeft]),
            'format' => new ApiException(422, 'pin_format', 'Enter your PIN with the number keys.', ['pin' => 'Enter your PIN.']),
            'locked' => new ApiException(423, 'pin_locked', 'Your PIN is locked for now.', [], ['minutes' => max(1, (int) $lockMinutes)]),
            'locked_hard' => new ApiException(423, 'pin_locked_hard', 'Your PIN is locked. See your supervisor.'),
            'paused' => new ApiException(429, 'paused', 'Sign-in is paused for a few minutes.', [], ['minutes' => max(1, (int) $lockMinutes)]),
            'cooldown' => new ApiException(429, 'kiosk_cooldown', 'This device paused PIN entry for a few minutes.', [], ['minutes' => max(1, (int) $lockMinutes)]),
            'setup_needed' => new ApiException(409, 'setup_needed', 'You need a training PIN first. Ask your supervisor for a setup slip.'),
            'busy' => new ApiException(409, 'busy', 'Busy - try again.'),
            default => new ApiException(503, 'signin_unavailable', 'PIN check is not available right now. Try again in a minute.', [],
                ['reason' => in_array($reason, ['odoo', 'odoo_auth', 'link_check', 'disabled'], true) ? $reason : 'odoo']),
        };
    }

    /** Pads a PIN-verifying response to >= 800 ms since the request started (PinService::pad when deployed). */
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
