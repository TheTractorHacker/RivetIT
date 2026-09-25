<?php

/*
 * /kiosk/checkin.php - [S] T-6 parallel check-in on another enrolled device (P3 spec §5.7, lane K5).
 * Pre-auth (device only): open group sessions -> pick one -> find your name -> sign -> your PIN.
 * No ksess is created. A device with a live session goes to that session's home instead.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

kiosk_require_device(false);
$k_live = kiosk_peek_session();
if ($k_live !== null) {
    kiosk_redirect(kiosk_role_home((string) $k_live['ksess_role'], $k_live));
}

$k_page = [
    'title' => \ITFlow\Training\Kiosk\Core\KioskStrings::t($kctx->lang, 'trn.self_title'),
    'css' => ['/css/itflow_training_kiosk_trainer.css'],
    'js' => ['/js/training_kiosk_trainer.js'],
    'body_class' => 'kx-trainer',
    'data' => ['view' => 'selfcheckin'],
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kx-wrap kx-wrap--wide" id="kt-root" aria-live="polite"><div class="kx-center"><span class="kx-spin" aria-hidden="true"></span></div></div>
<?php require __DIR__ . '/includes/layout_bottom.php';
