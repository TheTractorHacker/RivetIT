<?php

namespace ITFlow\Integrations;

class ConnectionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly array $details = []
    ) {
    }
}
