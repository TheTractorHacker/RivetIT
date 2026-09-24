<?php

/*
 * Training › Question Library: /agent/training_banks.php[?bank_id=]   (spec §5.6, level 2)
 *
 * Left: the bank tree ("Shared banks" and "Course banks"; banks written for one quiz are hidden
 * behind "Show quiz banks"), with per-language counts, a kebab per node (Add sub-bank, Rename,
 * Move, Archive) and drag-to-re-parent (depth <= 5, enforced by BankService).
 * Right: the selected bank's header (path, description, "Used by") and its questions as
 * collapsed cards (TrainingQuizBuilder.mountBank): add, import (CSV / paste), search and
 * filters, bulk move / mark critical / delete, translate. Export CSV (level 3, it contains the
 * answer key) is a plain same-origin link: bank_export_csv refuses cross-site requests.
 *
 * agent/js/training_banks.js renders everything from the JSON actions bank_tree, bank_create,
 * bank_update, bank_archive (+ the question actions through the component).
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_quiz.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_languages = $tr_ctx->settings->languages !== [] ? array_values($tr_ctx->settings->languages) : ['en'];
$tr_bank_id = (is_string($_GET['bank_id'] ?? null) && ctype_digit($_GET['bank_id'])) ? intval($_GET['bank_id']) : null;

$tr_data = [
    'level' => $tr_ctx->level,
    'user_id' => $tr_ctx->userId,
    'can_export' => $tr_ctx->level >= 3,
    'languages' => $tr_languages,
    'default_language' => $tr_languages[0],
    'limits' => $tr_ctx->settings->clientLimits(),
    'bank_id' => $tr_bank_id,
];

render_page_header(
    'Question Library',
    'Reusable question banks. Quizzes can draw questions from them at random.',
    '<button type="button" class="btn btn-primary" id="trb-new"><i class="fas fa-plus me-2"></i>New bank</button>',
    [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Question Library']]
);
?>

<div class="trb" id="trb">
    <aside class="card trb-side" aria-labelledby="trb-side-title">
        <div class="trb-side__head">
            <h2 id="trb-side-title">Banks</h2>
            <span class="tr-save-state" id="trb-tree-state" data-state="idle" aria-live="polite"></span>
        </div>
        <div class="trb-side__filters">
            <input type="search" class="form-control form-control-sm" id="trb-search" placeholder="Find a bank" aria-label="Find a bank">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="trb-quizbanks">
                <label class="form-check-label small" for="trb-quizbanks">Show quiz banks</label>
            </div>
        </div>
        <div class="trb-tree" id="trb-tree" role="tree" aria-label="Question banks" aria-busy="true">
            <div class="tr-skeleton tr-skeleton__line"></div>
            <div class="tr-skeleton tr-skeleton__line"></div>
            <div class="tr-skeleton tr-skeleton__line"></div>
        </div>
    </aside>

    <section class="trb-main" aria-label="Selected bank">
        <div class="card trb-head" id="trb-head" hidden></div>
        <div id="trb-questions"></div>
    </section>
</div>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/plugins/SortableJS/Sortable.min.js" defer></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_uploader.js?v=<?= filemtime(__DIR__ . '/js/training_uploader.js') ?>" defer></script>
<script src="/agent/js/training_quiz_builder.js?v=<?= filemtime(__DIR__ . '/js/training_quiz_builder.js') ?>" defer></script>
<script src="/agent/js/training_banks.js?v=<?= filemtime(__DIR__ . '/js/training_banks.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
