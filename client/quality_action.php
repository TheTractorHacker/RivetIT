<?php
/*
 * Client Portal
 * Quality write actions (issue #52): acknowledge a controlled document, answer a corrective action. POST only.
 * Every request: CSRF, a real department person (not a preview, not a shared screen), the module/department/feature gate again, a fresh sign-in or the
 * password typed again (Reauth), then ONE call to the QMS as that person with an idempotency key (a double click is one acknowledgment).
 */

require_once '../config.php';
require_once '../functions.php';
require_once '../includes/load_global_settings.php';
require_once 'includes/check_login.php';
require_once 'functions.php';
require_once __DIR__ . '/includes/quality_common.php';

use ITFlow\Quality\QualityConfig;
use ITFlow\Quality\QualityLog;
use ITFlow\Quality\QualityService;
use ITFlow\Quality\Reauth;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: quality.php');
    exit();
}
validateCSRFToken($_POST['csrf_token'] ?? null);

$back = static function (string $type, string $message, string $tab = 'documents'): never {
    $_SESSION['alert_type'] = $type;
    $_SESSION['alert_message'] = $message;
    header('Location: quality.php?tab=' . rawurlencode($tab));
    exit();
};

if (!$q_allowed) {
    $q_deny();
}
$do = (string) ($_POST['do'] ?? '');
if (!in_array($do, ['acknowledge', 'respond'], true)) {
    $back('warning', 'That action is not available.');
}
$tab = $do === 'acknowledge' ? 'documents' : 'actions';
$id = (string) ($_POST['id'] ?? '');
$idem = (string) ($_POST['idem'] ?? '');
if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $id) !== 1 || preg_match('/^[a-f0-9]{16,64}$/D', $idem) !== 1) {
    $back('warning', 'That request was not valid. Please try again.', $tab);
}

// Re-authentication.
$minutes = (int) QualityConfig::load($mysqli)['qs_reauth_minutes'];
if (!Reauth::fresh($_SESSION, $minutes)) {
    $r = Reauth::confirm($mysqli, $_SESSION, intval($session_user_id), (string) ($_POST['password'] ?? ''));
    if ($r !== 'ok') {
        QualityLog::record($mysqli, 'portal', 'reauth_' . $r, 'Re-authentication ' . $r . ' for a Quality action.', null, null, intval($session_contact_id));
        $back('warning', match ($r) {
            'wrong' => 'That password was not right.',
            'locked' => 'Too many wrong passwords. Sign out and sign in again to continue.',
            default => 'Please sign out and sign in again, then try once more.',
        }, $tab);
    }
}

$svc = new QualityService($mysqli);
if ($do === 'acknowledge') {
    $r = $svc->acknowledge(intval($session_contact_id), $id, 'ack-' . $idem);
    $okMsg = 'Thank you. Your acknowledgment was recorded.';
} else {
    $r = $svc->respond(intval($session_contact_id), $id, (string) ($_POST['text'] ?? ''), 'resp-' . $idem);
    $okMsg = 'Your response was sent.';
}
if (!empty($r['ok'])) {
    logAction('Quality', $do === 'acknowledge' ? 'Acknowledge' : 'Respond', "$session_contact_name used Quality: $do " . substr($id, 0, 64), intval($session_client_id), intval($session_contact_id));
    $back('success', $okMsg, $tab);
}
$back('warning', $r['message'] . (!empty($r['reference']) ? ' (reference ' . $r['reference'] . ')' : ''), $tab);
