<?php

namespace ITFlow\Integrations;

/**
 * Master plan Section 10.2. Odoo is the first (and, per the AFK decision
 * log in PROGRESS.md, currently only) implementer - kept as an interface
 * so a future ERP swap doesn't require touching anything that depends on
 * it, per Section 3.7 ("Build for replacement").
 */
interface BusinessApplicationProvider
{
    public function testConnection(): ConnectionResult;

    /** @return ExternalUser[] */
    public function listUsers(?string $cursor = null): array;

    public function getUser(string $externalId): ?ExternalUser;
}
