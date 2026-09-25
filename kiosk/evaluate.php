<?php

/*
 * /kiosk/evaluate.php - practical evaluation (P3 spec §5.7, lane K5).
 *   trainer role: course -> person -> checklist (server order) -> "Hand the iPad to {first}".
 *   handoff role (restricted): the employee reads the summary, signs, enters their PIN; then
 *   "Hand back" -> the evaluator signs and enters the trainer PIN. Cancel needs the trainer PIN.
 *   The hand-off role has no Done button. The evaluation token stays in this tab's
 *   sessionStorage (never in a URL); without it the only way out is Cancel.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

$k_session = kiosk_require_session(['trainer', 'handoff']);
$k_role = (string) $k_session['ksess_role'];
$k_course = 0;
$k_raw = $_GET['c'] ?? '';
if ($k_role === 'trainer' && is_string($k_raw) && preg_match('/^[1-9][0-9]{0,9}$/D', $k_raw) === 1) {
    $k_course = (int) $k_raw;
}

$k_page = [
    'title' => \ITFlow\Training\Kiosk\Core\KioskStrings::t($kctx->lang, 'trn.tile_evaluate'),
    'css' => ['/css/itflow_training_kiosk_trainer.css'],
    'js' => ['/js/training_kiosk_trainer.js'],
    'body_class' => $k_role === 'handoff' ? 'kx-trainer kx-handoff' : 'kx-trainer',
    'data' => ['view' => $k_role === 'handoff' ? 'handoff' : 'evaluate', 'course_id' => $k_course],
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kx-wrap kx-wrap--wide" id="kt-root" aria-live="polite"><div class="kx-center"><span class="kx-spin" aria-hidden="true"></span></div></div>
<?php require __DIR__ . '/includes/layout_bottom.php';
