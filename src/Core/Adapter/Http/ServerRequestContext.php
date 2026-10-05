<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Http;

use RivetCore\Contracts\RequestContextInterface;

/** Supplies per-request facts to RivetCore from the web server's request, the way the legacy audit code read them. */
final class ServerRequestContext implements RequestContextInterface
{
    public function ipAddress(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    public function userAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    public function requestId(): ?string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    }
}
