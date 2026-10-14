<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * Where MdmClient keeps its access token between runs. Zoho allows only 10 access tokens per refresh token in any 10 minutes, so
 * cron, Test connection and Sync now must share one token (valid one hour) instead of each asking for a new one.
 */
interface MdmTokenStore
{
    /** @return array{token:string,expires_at:int}|null */
    public function get(): ?array;

    public function put(string $token, int $expiresAt): void;

    public function clear(): void;
}
