<?php

namespace ITFlow\Training\Kiosk\Core;

/**
 * The kiosk session cookie is missing, unknown or no longer valid (P3 spec §3.1). $reason is one of
 * missing | ended | device | idle | absolute | contact_ineligible | pin_locked. API: 401 session_ended {reason};
 * pages: 302 to /kiosk/. The cookie is cleared by the caller.
 */
final class KioskAuthException extends \RuntimeException
{
    public function __construct(public string $reason)
    {
        parent::__construct('kiosk session: ' . $reason);
    }
}
