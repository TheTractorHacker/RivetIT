<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Api\ApiException;

/**
 * The outcome of one PIN or setup-code check (P3 spec §3.2). $status:
 *   ok | wrong | locked | locked_hard | unavailable | paused | cooldown | setup_needed | busy | format
 * $triesLeft is set on `wrong` only once the person has 3 or more failures; $lockMinutes on
 * locked/paused/cooldown; $reason on unavailable ('odoo' | 'odoo_auth' | 'link_check' |
 * 'disabled'); $notices on ok (['reset' => {...}] / ['fp_changed' => {...}]).
 *
 * toApi() is the ONE mapping to the kiosk error codes (§4.4), for every lane that checks a PIN
 * (K2 sign-in; K3 attest/ack; K5 trainer actions), so the client's err.* texts always match.
 * $kind 'setup' maps a wrong answer to setup_code_wrong instead of pin_wrong.
 */
final class PinResult
{
    public const STATUSES = ['ok', 'wrong', 'locked', 'locked_hard', 'unavailable', 'paused', 'cooldown', 'setup_needed', 'busy', 'format'];

    public function __construct(
        public string $status,
        public ?int $triesLeft = null,
        public ?int $lockMinutes = null,
        public ?string $reason = null,
        public array $notices = [],
        public ?string $source = null,
        public ?int $odooEmployeeId = null,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("PinResult: unknown status $status");
        }
    }

    public function ok(): bool
    {
        return $this->status === 'ok';
    }

    /** The kiosk API error for a non-ok result (null when ok). */
    public function toApi(string $kind = 'pin'): ?ApiException
    {
        $wrongCode = $kind === 'setup' ? 'setup_code_wrong' : 'pin_wrong';
        return match ($this->status) {
            'ok' => null,
            'wrong' => new ApiException(422, $wrongCode, $kind === 'setup' ? 'That setup code is not right.' : 'PIN not accepted.', [],
                $this->triesLeft !== null ? ['tries_left' => $this->triesLeft] : []),
            'format' => new ApiException(422, $kind === 'setup' ? 'setup_code_wrong' : 'pin_format', $kind === 'setup' ? 'Enter the 8-digit code from your slip.' : 'Enter your PIN with the number keys.'),
            'locked' => new ApiException(423, 'pin_locked', 'This PIN is locked for now.', [], ['minutes' => max(1, (int) $this->lockMinutes)]),
            'locked_hard' => new ApiException(423, 'pin_locked_hard', 'This PIN is locked. See your trainer or an admin.'),
            'unavailable' => new ApiException(503, 'signin_unavailable', 'Sign-in is unavailable right now. See your trainer.', [],
                ['reason' => in_array($this->reason, ['odoo', 'odoo_auth', 'link_check', 'disabled'], true) ? $this->reason : 'odoo']),
            'paused' => new ApiException(429, 'paused', 'Sign-in is paused for a few minutes.', [], ['minutes' => max(1, (int) $this->lockMinutes)]),
            'cooldown' => new ApiException(429, 'kiosk_cooldown', 'Sign-in on this device is paused for a few minutes.', [], ['minutes' => max(1, (int) $this->lockMinutes)]),
            'setup_needed' => new ApiException(409, 'setup_needed', 'You need a training PIN first.'),
            'busy' => new ApiException(409, 'busy', 'Busy - try again.'),
        };
    }
}
