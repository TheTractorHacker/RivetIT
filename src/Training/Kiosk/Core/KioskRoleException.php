<?php

namespace ITFlow\Training\Kiosk\Core;

/**
 * A live kiosk session exists but its role is not allowed here (P3 spec §3.1). NOTHING is ended:
 * the API answers 403 wrong_role (unless the route also accepts the device) and pages redirect
 * to the role's own home.
 */
final class KioskRoleException extends \RuntimeException
{
    public function __construct(public string $role)
    {
        parent::__construct('kiosk session role not allowed: ' . $role);
    }
}
