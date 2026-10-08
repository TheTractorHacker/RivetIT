<?php

namespace ITFlow\Integrations\Microsoft;

/**
 * A Microsoft Graph / Entra failure with a machine-readable classification, so the settings page and the sync log can say
 * what is actually wrong (wrong secret vs. missing admin consent vs. throttling) instead of a bare HTTP status.
 * Extends RuntimeException, so every existing `catch (RuntimeException)` around GraphClient keeps working.
 */
class GraphException extends \RuntimeException
{
    /** Tenant, client ID or secret rejected by Entra (token request failed, or the token was refused). */
    public const AUTH_FAILED = 'auth_failed';
    /** Signed in fine, but the app registration lacks an application permission or admin consent for it. */
    public const CONSENT_MISSING = 'consent_missing';
    /** HTTP 429 / 503 / 504 that did not clear within the retry budget. */
    public const THROTTLED = 'throttled';
    /** Could not connect (DNS, TLS, timeout). */
    public const UNREACHABLE = 'unreachable';
    /** Microsoft answered with a 5xx that did not clear. */
    public const SERVER_ERROR = 'server_error';
    /** Graph says the request does not apply to this tenant (for example no Intune license). */
    public const NOT_APPLICABLE = 'tenant_not_applicable';
    /** The response was not what Graph documents (not JSON, pagination loop, foreign nextLink, page cap). */
    public const BAD_RESPONSE = 'bad_response';
    /** The caller's time limit ran out. */
    public const TIME_LIMIT = 'time_limit';
    /** An account change was attempted while 'Allow RivetIT to change Entra accounts' is off. Nothing was sent. */
    public const WRITES_DISABLED = 'writes_disabled';
    public const OTHER = 'other';

    public function __construct(
        string $message,
        public readonly string $errorCode = self::OTHER,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($message);
    }
}
