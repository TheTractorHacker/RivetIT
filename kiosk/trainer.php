<?php

/*
 * /kiosk/trainer.php - trainer home (P3 spec §5.7, lane K5). Trainer role only. Tiles are gated
 * by the trainer's flags (re-read on every request) and by whether the Phase 2 session and
 * evaluation back ends are in this build. Everything is rendered by js/training_kiosk_trainer.js
 * from k-page-data with textContent.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Kiosk\Trainer\TrainerService;

$k_session = kiosk_require_session(['trainer']);
$k_home = null;
try {
    $k_home = (new TrainerService($kctx))->home();
} catch (ApiException $e) {
    $k_home = null;   // not (or no longer) an active trainer: the page says so; Done still works
}

$k_page = [
    'title' => \ITFlow\Training\Kiosk\Core\KioskStrings::t($kctx->lang, 'trn.title'),
    'css' => ['/css/itflow_training_kiosk_trainer.css'],
    'js' => ['/js/training_kiosk_trainer.js'],
    'body_class' => 'kx-trainer',
    'data' => ['view' => 'home', 'home' => $k_home, 'device_label' => (string) ($kctx->device['kiosk_label'] ?? '')],
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kx-wrap kx-wrap--wide" id="kt-root" aria-live="polite"><div class="kx-center"><span class="kx-spin" aria-hidden="true"></span></div></div>
<?php require __DIR__ . '/includes/layout_bottom.php';
