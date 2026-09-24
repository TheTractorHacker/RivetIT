<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Core\Ctx;

/**
 * Everything a kiosk service needs about the request (P3 spec §0.3, §3.1). Built only by
 * kiosk/includes/bootstrap.php (and CLI harnesses); kiosk services never read globals.
 *
 * $core is a Ctx with userId 0, isAdmin false, level 0: the kiosk has no agent user.
 * $device is the validated KioskAuth::device() row (null before enrollment), $ksess the
 * validated KioskAuth::session() row once a route or page has checked it.
 * $startedNs is hrtime(true) at bootstrap: PinService::pad() measures the 800 ms floor from it.
 */
final class KioskCtx
{
    public function __construct(
        public readonly Ctx $core,
        public readonly KioskSettings $ks,
        public readonly KioskKeys $keys,
        public readonly ?array $device,
        public readonly ?array $ksess,
        public readonly string $lang,
        public readonly int $startedNs,
    ) {
    }

    public function db(): \mysqli
    {
        return $this->core->db;
    }

    /** The enrolled device's kiosk_id, 0 when there is none. */
    public function kioskId(): int
    {
        return (int) ($this->device['kiosk_id'] ?? 0);
    }

    /** The signed-in person (ksess contact), 0 when there is no session. */
    public function contactId(): int
    {
        return (int) ($this->ksess['ksess_contact_id'] ?? 0);
    }

    public function ksessId(): ?int
    {
        return isset($this->ksess['ksess_id']) ? (int) $this->ksess['ksess_id'] : null;
    }

    public function role(): ?string
    {
        return isset($this->ksess['ksess_role']) ? (string) $this->ksess['ksess_role'] : null;
    }

    /**
     * Ledger actor fields for this request (§0.9): a session => 'contact' (the ksess contact);
     * a device or anonymous pre-auth request => 'kiosk'. Merge into Ledger::append()'s array.
     */
    public function eventBase(): array
    {
        $cid = $this->contactId();
        return [
            'actor_type' => $cid > 0 ? 'contact' : 'kiosk',
            'actor_contact_id' => $cid > 0 ? $cid : null,
            'kiosk_id' => $this->kioskId() > 0 ? $this->kioskId() : null,
            'ksess_id' => $this->ksessId(),
            'user_agent' => $this->core->userAgent,
        ];
    }

    public function withKsess(array $ksess): self
    {
        return new self($this->core, $this->ks, $this->keys, $this->device, $ksess, (string) ($ksess['ksess_language'] ?? $this->lang), $this->startedNs);
    }

    public function withDevice(?array $device): self
    {
        return new self($this->core, $this->ks, $this->keys, $device, $this->ksess, $this->lang, $this->startedNs);
    }

    public function __debugInfo(): array
    {
        return ['kiosk_id' => $this->kioskId(), 'contact_id' => $this->contactId(), 'ksess_id' => $this->ksessId(), 'lang' => $this->lang];
    }
}
