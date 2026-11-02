<?php
// GET /api/v1/portal_devices        -> 200 {devices:[...]}   the devices assigned to the signed-in employee
// GET /api/v1/portal_devices/{id}   -> 200 {device:{...}} | 404 (not theirs, archived, or no such asset)
// Device facts: name, type, make/model, serial, OS, status, purchase/install date, warranty (date, days left, state), last seen
// (built-in agent check-in or RMM link, whichever is newer) and restart-pending. No credentials, IPs, notes or logged-in user.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/portal_api.php';
require_once $DOCUMENT_ROOT . '/src/Portal/EmployeeHome.php';

portal_api_get_only();
$me = portal_api_require_employee();
$devices = (new \ITFlow\Portal\EmployeeHome($mysqli))->myDeviceDetails($me['contact_id'], $me['client_id']);

if ($id !== null) {
    foreach ($devices as $d) {
        if ($d['id'] === $id) {
            api_response(200, ['device' => $d]);
        }
    }
    api_error(404, 'Not found');
}
api_response(200, ['devices' => $devices]);
