<?php

namespace ITFlow\Mcp;

use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/**
 * Wraps the external-provider JWT validator so that a failure to reach or read the identity provider (discovery or JWKS
 * fetch, a malformed document) answers a plain 401 instead of an uncaught exception (HTTP 500 with a stack trace in the
 * server log). The request is refused either way; nothing is let through, and the exception text stays in the log.
 */
final class FailClosedTokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(private AuthorizationTokenValidatorInterface $inner) {}

    public function validate(string $accessToken): AuthorizationResult
    {
        try {
            return $this->inner->validate($accessToken);
        } catch (\Throwable $e) {
            error_log('MCP token validation failed closed: ' . $e::class);

            return AuthorizationResult::unauthorized('invalid_token', 'The token could not be verified.');
        }
    }
}
