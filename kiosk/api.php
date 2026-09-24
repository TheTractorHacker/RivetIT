<?php

/*
 * Kiosk JSON API: /kiosk/api.php?action=<name> (P3 spec §4.1). No PHP session; the device and
 * kiosk-session cookies and the X-Kiosk-Token header authenticate each request.
 */

$KIOSK_CSP_PROFILE = 'api';
require __DIR__ . '/includes/bootstrap.php';

\ITFlow\Training\Kiosk\Api\KioskRouter::handle($kctx);
