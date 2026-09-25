<?php

/**
 * Kiosk pre-auth routes (P3 spec §4.2, lane K2): device adoption, name search, pick, PIN sign-in,
 * setup code, PIN create and the [S] setup-code enrollment. Shape: see KioskRouter.
 */

use ITFlow\Training\Kiosk\Api\PreauthActions;

return [
    'adopt_device'      => ['handler' => PreauthActions::class . '::adoptDevice', 'method' => 'POST', 'auth' => ['anon']],
    'enroll_code'       => ['handler' => PreauthActions::class . '::enrollCode', 'method' => 'POST', 'auth' => ['anon']],
    'search'            => ['handler' => PreauthActions::class . '::search', 'method' => 'POST', 'auth' => ['device']],
    'pick'              => ['handler' => PreauthActions::class . '::pick', 'method' => 'POST', 'auth' => ['device']],
    'pin_login'         => ['handler' => PreauthActions::class . '::pinLogin', 'method' => 'POST', 'auth' => ['device']],
    'setup_code_verify' => ['handler' => PreauthActions::class . '::setupCodeVerify', 'method' => 'POST', 'auth' => ['device']],
    'pin_create'        => ['handler' => PreauthActions::class . '::pinCreate', 'method' => 'POST', 'auth' => ['device']],
];
