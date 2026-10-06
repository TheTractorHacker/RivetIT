<?php

namespace ITFlow\Integrations\Microsoft;

/** A token cache that lives and dies with the object: used by tests and as a safe default. */
final class InMemoryGraphTokenCache implements GraphTokenCache
{
    private ?array $entry = null;

    public function get(): ?array
    {
        return $this->entry;
    }

    public function put(string $token, int $expiresAt): void
    {
        $this->entry = ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function clear(): void
    {
        $this->entry = null;
    }
}
