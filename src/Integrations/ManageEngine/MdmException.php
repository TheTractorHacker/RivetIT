<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * A ManageEngine MDM / Zoho Accounts failure with a machine-readable classification (settings page, sync log).
 * Extends RuntimeException so a plain catch (RuntimeException) keeps working. Messages never contain a token or secret.
 */
class MdmException extends \RuntimeException
{
    /** Zoho refused the client id / secret / refresh token, or the API refused the access token (401). */
    public const AUTH_FAILED = 'auth_failed';
    /** The token works but lacks a scope (HTTP 403 from the API): add MDMOnDemand.MDMInventory.READ / MDMDeviceMgmt.READ. */
    public const SCOPE_MISSING = 'scope_missing';
    /** HTTP 429 (COM0002) / 503 / 504 that did not clear within the retry budget, or Zoho's "too many requests". */
    public const THROTTLED = 'throttled';
    public const UNREACHABLE = 'unreachable';
    public const SERVER_ERROR = 'server_error';
    /** The response was not what the docs describe (not JSON, paging loop, page cap). */
    public const BAD_RESPONSE = 'bad_response';
    public const TIME_LIMIT = 'time_limit';
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
