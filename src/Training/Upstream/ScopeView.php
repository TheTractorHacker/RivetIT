<?php

namespace ITFlow\Training\Upstream;

use ITFlow\Training\People\Scope;

/**
 * A read-only view of a P2 People\Scope (spec §3.1). Built only by PeopleScope. Every answer is P2's
 * (the parity test in §10.3 compares them); with no P2 Scope it answers "none" everywhere.
 */
final class ScopeView
{
    /** @param Scope|null $scope null = none (P2 absent) */
    public function __construct(private readonly ?object $scope)
    {
        if ($scope !== null && !($scope instanceof Scope)) {
            throw new \InvalidArgumentException('ScopeView wraps a People\Scope');
        }
    }

    public static function none(): self
    {
        return new self(null);
    }

    public function isAll(): bool
    {
        return $this->scope !== null && $this->scope->isAll();
    }

    public function isNone(): bool
    {
        return $this->scope === null || $this->scope->isNone();
    }

    public function allows(int $clientId): bool
    {
        return $this->scope !== null && $this->scope->allows($clientId);
    }

    /** @return list<int> the allowed department ids ([] for all-scope: check isAll() first) */
    public function clientIds(): array
    {
        return $this->scope === null ? [] : $this->scope->clientIds();
    }

    /** P2 Scope::assertContact; false on its 404 ApiException or any other error. */
    public function canSeeContact(\mysqli $db, int $contactId): bool
    {
        if ($this->scope === null || $contactId < 1) {
            return false;
        }
        try {
            $this->scope->assertContact($db, $contactId);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
