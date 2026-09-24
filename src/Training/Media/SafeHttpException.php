<?php

namespace ITFlow\Training\Media;

/**
 * A refused or failed outbound request from SafeHttp. $reason is machine-readable:
 *   scheme | host_not_allowed | bad_url | dns | private_address | redirect | too_large |
 *   timeout | network | tls
 * The message never contains a query-string value (API keys travel in query strings).
 */
final class SafeHttpException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
