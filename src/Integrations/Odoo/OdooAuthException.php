<?php

namespace ITFlow\Integrations\Odoo;

/**
 * Odoo refused the credentials (legacy login returned no uid, AccessDenied,
 * or JSON-2 HTTP 401) or denied access to what was asked (AccessError, or
 * JSON-2 HTTP 403). A \RuntimeException subclass on purpose: every existing
 * `catch (\RuntimeException $e)` around an Odoo call keeps catching it.
 */
class OdooAuthException extends \RuntimeException
{
}
