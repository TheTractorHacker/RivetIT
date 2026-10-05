<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Http;

use RivetCore\Contracts\RequestContextInterface;

/**
 * Supplies per-request facts to RivetCore. The request id is assigned by the server (or by mcp_server/index.php, which
 * sets $_SERVER['RIVET_REQUEST_ID']); a client-supplied X-Request-ID header is never trusted, so a caller cannot choose
 * what lands in audit rows or tool envelopes.
 */
final class ServerRequestContext implements RequestContextInterface
{
    private static ?string $generated = null;

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
        return $_SERVER['RIVET_REQUEST_ID'] ?? (self::$generated ??= 'req_' . bin2hex(random_bytes(8)));
    }
}
