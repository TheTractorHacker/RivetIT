<?php
/*
 * Client Portal
 * "Start training" for a signed-in department user: no kiosk device setup, no name search. POST only.
 * The portal login IS the identity; PortalLaunch mints a portal-scoped kiosk device + learner session and this
 * hands the browser to the training module. The training PIN is still required where it counts (signing).
 */

require_once '../config.php';
require_once '../functions.php';
require_once '../includes/load_global_settings.php';
require_once 'includes/check_login.php';
require_once 'functions.php';

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Device\PortalLaunch;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: training.php');
    exit();
}

// An admin portal preview is read-only and holds no real identity (user id / contact id are 0).
if (!empty($portal_preview_active) || intval($session_user_id) < 1 || intval($session_contact_id) < 1) {
    $_SESSION['alert_type'] = 'warning';
    $_SESSION['alert_message'] = 'Training cannot be started from a portal preview.';
    header('Location: training.php');
    exit();
}

validateCSRFToken($_POST['csrf_token'] ?? null);

if (intval($config_module_enable_training ?? 0) !== 1 || empty($config_training_schema_ready)) {
    header('Location: index.php');
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

$course = intval($_POST['course'] ?? 0);

try {
    $ks = KioskSettings::fromDb($mysqli);
    if (!$ks->schemaReady) {
        throw new ApiException(404, 'not_found', 'Training is not available.');
    }
    $tokens = PortalLaunch::start($mysqli, $ks, intval($session_user_id), intval($session_contact_id), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
} catch (ApiException $e) {
    $_SESSION['alert_type'] = 'warning';
    $_SESSION['alert_message'] = $e->getMessage();
    header('Location: training.php');
    exit();
}

KioskAuth::setDeviceCookie($tokens['device_token']);
KioskAuth::setSessionCookie($tokens['session_token']);

header('Location: ' . ($course > 0 ? '/kiosk/course.php?c=' . $course : '/kiosk/me.php'));
exit();
