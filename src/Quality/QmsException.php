<?php

namespace ITFlow\Quality;

/**
 * A failed call to the QMS (or a refusal to make one). The message is safe to show an administrator and to write to the error log: it never carries
 * a token, a secret, a request body, a response body or a stack trace. $errCode is a short stable code; $http is 0 when no response was received.
 */
final class QmsException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const URL_REJECTED = 'url_rejected';
    public const TRANSPORT = 'transport_error';
    public const TIMEOUT = 'timeout';
    public const AUTH = 'auth_failed';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const REJECTED = 'rejected';
    public const SERVER = 'server_error';
    public const RATE_LIMITED = 'rate_limited';
    public const BAD_RESPONSE = 'bad_response';
    public const CONTEXT = 'user_context_required';

    public function __construct(
        string $message,
        public readonly string $errCode,
        public readonly int $http = 0,
        public readonly string $correlationId = '',
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
