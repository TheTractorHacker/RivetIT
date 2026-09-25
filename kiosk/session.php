<?php

/*
 * /kiosk/session.php - a group session (P3 spec §5.7, lane K5).
 *   trainer role  (?s=<tsession_id>): roster, expected people, marks, hand the iPad around, finish.
 *   checkin role  (the ksess's own session; ?s is ignored): tap or search your name -> sign -> PIN.
 * The check-in role has no Done button (leaving needs the trainer PIN) and idles out after the
 * check-in idle limit (20 minutes by default).
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

$k_session = kiosk_require_session(['trainer', 'checkin']);
$k_role = (string) $k_session['ksess_role'];
$k_sid = 0;
if ($k_role === 'trainer') {
    $k_raw = $_GET['s'] ?? '';
    if (!is_string($k_raw) || preg_match('/^[1-9][0-9]{0,9}$/D', $k_raw) !== 1) {
        kiosk_redirect('/kiosk/trainer.php');
    }
    $k_sid = (int) $k_raw;
}

$k_page = [
    'title' => \ITFlow\Training\Kiosk\Core\KioskStrings::t($kctx->lang, $k_role === 'checkin' ? 'trn.checkin_title' : 'trn.session_title', ['course' => '']),
    'css' => ['/css/itflow_training_kiosk_trainer.css'],
    'js' => ['/js/training_kiosk_trainer.js'],
    'body_class' => $k_role === 'checkin' ? 'kx-trainer kx-checkin' : 'kx-trainer',
    'data' => ['view' => $k_role === 'checkin' ? 'checkin' : 'session', 'tsession_id' => $k_sid],
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kx-wrap kx-wrap--wide" id="kt-root" aria-live="polite"><div class="kx-center"><span class="kx-spin" aria-hidden="true"></span></div></div>
<?php require __DIR__ . '/includes/layout_bottom.php';
