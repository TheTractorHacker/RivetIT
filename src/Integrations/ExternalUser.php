<?php

namespace ITFlow\Integrations;

/**
 * Normalized shape for a user/account record coming back from any
 * external provider (Microsoft Graph, Odoo, future ones) - callers that
 * map into contacts/people don't need to know which provider they got
 * this from.
 */
class ExternalUser
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $displayName,
        public readonly ?string $email,
        public readonly bool $enabled,
        public readonly array $raw = []
    ) {
    }
}
