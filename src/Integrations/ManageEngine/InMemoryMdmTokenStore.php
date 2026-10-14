<?php

namespace ITFlow\Integrations\ManageEngine;

/** A token store that lives and dies with the object (tests, and the safe default). */
final class InMemoryMdmTokenStore implements MdmTokenStore
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
