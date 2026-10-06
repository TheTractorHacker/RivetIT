<?php

namespace ITFlow\Integrations\Microsoft;

/** Where GraphClient keeps its access token between runs (the token is valid about an hour; cron runs every few minutes). */
interface GraphTokenCache
{
    /** @return array{token:string,expires_at:int}|null  null when empty, expired data is the caller's concern */
    public function get(): ?array;

    public function put(string $token, int $expiresAt): void;

    public function clear(): void;
}
