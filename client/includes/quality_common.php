<?php
/*
 * Client Portal - shared by quality.php, quality_manage.php and quality_action.php (the Quality module, issue #52).
 * Requires includes/inc_all.php (or check_login.php) to have run. Decides, on the server and from the database, whether THIS login may use Quality at all:
 * the module is on, the person's department is enabled, this is a real department person (not an admin preview, not a shared kiosk screen). Anything else
 * leaves the page without a trace of Quality. The department and the manager role come from the contact's own record, never from the request.
 */

use ITFlow\Quality\QualityConfig;
use ITFlow\Quality\QualityService;

require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

$q_allowed = false;
$q_access = ['ok' => false, 'reason' => 'none', 'client_id' => 0, 'manager' => false];
$q_features = [];
if (empty($portal_preview_active) && empty($_SESSION['kiosk_session']) && empty($_SESSION['kiosk_device']) && empty($_SESSION['is_kiosk'])
    && intval($session_contact_id ?? 0) > 0 && QualityConfig::moduleOn($mysqli)) {
    $q_access = QualityConfig::access($mysqli, intval($session_contact_id));
    // The access check reads the contact's department from the database; it must be the department of this portal session too.
    if ($q_access['ok'] && intval($q_access['client_id']) === intval($session_client_id)) {
        $q_allowed = true;
        $q_features = QualityConfig::features($mysqli);
    }
}
$q_deny = static function (string $message = 'Quality is not available.'): never {
    $_SESSION['alert_type'] = 'warning';
    $_SESSION['alert_message'] = $message;
    header('Location: index.php');
    exit();
};
