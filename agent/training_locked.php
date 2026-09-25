<?php

/*
 * Training › Locked courses (P3 spec §5.8 "agent/training_locked.php", L-10; level 2): kiosk
 * course runs that are locked after the last failed try, or blocked because a lesson video changed
 * or disappeared. Give more tries, or restart the person on the current version - each with a
 * reason (run_unlock; ledger run.unlocked + audit). Rows are limited to the agent's departments.
 */

$page_extra_css = ['/css/itflow_training.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_data = ['level' => $tr_ctx->level];

render_page_header(
    'Locked courses',
    'Kiosk courses someone cannot continue: out of tries on a must-pass quiz, or a lesson video that changed. Give another try or restart them on the current version.',
    '',
    [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Locked courses']]
);
?>

<div class="card" id="tr-locked">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <label class="form-label mb-0 me-1" for="tr-locked-dept">Department</label>
        <select class="form-select form-select-sm w-auto" id="tr-locked-dept">
            <option value="">All departments</option>
        </select>
        <span class="ms-auto text-secondary small" id="tr-locked-count" aria-live="polite"></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Person</th>
                    <th scope="col">Course</th>
                    <th scope="col">Why</th>
                    <th scope="col">Since</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="tr-locked-rows">
                <tr><td colspan="5" class="text-secondary py-4 text-center">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_locked.js?v=<?= filemtime(__DIR__ . '/js/training_locked.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
