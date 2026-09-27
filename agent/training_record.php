<?php

/*
 * Training › Records › one record (Phase 2 spec §5.2): the evidence page for a completion.
 *
 * Level 1 plus people scope: completion_get loads the row and checks scope before anything is
 * rendered (an out-of-scope or unknown id is the not-found state, never a 403). Level 3 can
 * void it with a reason of at least 10 characters in an inline .tr-confirm-bar. The page is
 * driven by CompletionDetail; agent/js/training_records.js renders it with DOM nodes only.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_ops.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
session_write_close();   // spec §0 #9: nothing below writes the session (inc_all has shown the flash); free its lock for the page's JSON calls
require_once __DIR__ . '/includes/training_ops/ops.php';

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_level = (int) lookupUserPermission('module_training');
$tr_id = tro_get_id('id');
$tr_record = $tr_id !== null ? tro_action($mysqli, 'completion_get', ['completion_id' => $tr_id]) : null;
$tr_rec = ($tr_record !== null && $tr_record['state'] === 'ok' && is_array($tr_record['data'])) ? $tr_record['data'] : null;

$tr_data = [
    'level' => $tr_level,
    'user_id' => $tr_ctx->userId,
    'today' => tro_today(),
    'record_id' => $tr_id,
    'record' => $tr_record,
    'settings' => tro_records_settings($mysqli),
    'routes' => ['completion_void' => tro_has_route('completion_void')],
    'certificate_pdf' => is_file(__DIR__ . '/training_pdf.php'),   // Phase 5 (spec §7.7): "Download PDF" on training records
];

$tr_title = $tr_rec !== null ? (string) ($tr_rec['course']['name'] ?? 'Training record') : 'Training record';
?>

<div class="tro-page" id="tro-record-page">
    <?php render_page_header($tr_title, null, '', [
        ['label' => 'Training', 'url' => '/agent/training.php'],
        ['label' => 'Records & sessions', 'url' => '/agent/training_records.php'],
        ['label' => $tr_rec !== null && !empty($tr_rec['cert_number']) ? (string) $tr_rec['cert_number'] : 'Record'],
    ]); ?>

    <div id="tro-record">
        <?php if ($tr_id === null) { ?>
        <div class="tro-card"><?php render_empty_state('fas fa-search', 'Not found', 'Open a record from the records log.'); ?></div>
        <?php } else { ?>
        <div class="tro-card" aria-busy="true">
            <div class="tro-rec-head">
                <div class="tro-rec-head__main">
                    <span class="tro-skel tro-skel--w60 mb-2"></span>
                    <span class="tro-skel tro-skel--w40"></span>
                </div>
            </div>
        </div>
        <?php } ?>
    </div>
    <?php
    // Phase 3 (P3 spec §7.9 [S]): a record the kiosk signed off carries its run's evidence - timeline,
    // credited seconds, attempts as shown and chosen, signatures. Only after completion_get found the
    // record in the agent's scope; the partial re-checks scope itself and renders nothing on any error.
    if ($tr_rec !== null && $tr_id !== null) {
        try {
            $tr_kiosk_run_id = (int) ((new \ITFlow\Training\Kiosk\Bridge\RecordsBridge($tr_ctx))->completion($tr_id)['run_id'] ?? 0);
        } catch (\Throwable $tr_e) {
            $tr_kiosk_run_id = 0;
            error_log('Training record: kiosk evidence lookup failed: ' . get_class($tr_e));
        }
        if ($tr_kiosk_run_id > 0) {
            $tr_kiosk_ctx = $tr_ctx;
            require __DIR__ . '/includes/training/kiosk_evidence_partial.php';
        }
    }
    ?>
</div>

<?php
tro_page_data($tr_data);
tro_scripts('/agent/js/training_records.js');
require_once "../includes/footer.php";
