<?php

/*
 * Training › Cover Library (level 2): a standalone browse of the cover gallery the course/path cover picker
 * draws from - the 30 built-in illustrations (read-only) plus every cover an author has shared
 * (CoverLibrary::customCovers()). Upload-and-share here works the same as the picker's own "Upload your own"
 * tab: upload (purpose 'path_cover', no path - UploadPurpose's path_or_none scope allows that), then
 * cover_gallery_add. Renaming/recategorizing a shared cover re-shares it (addCustom() upserts by media_id);
 * removing one only takes it off the gallery list - the media and anything already using it as a cover are
 * untouched (CoverLibrary::removeCustom()). Both are ownership-checked server-side (the sharer, or an admin).
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_covers.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);

$tr_data = [
    'level' => $tr_ctx->level,
    'user_id' => $tr_ctx->userId,
    'is_admin' => $tr_ctx->isAdmin,
];

render_page_header(
    'Cover Library',
    'Every course cover: the built-in illustrations and every one an author has shared.',
    '',
    [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Cover Library']]
);
?>

<div class="tr-cover-lib" id="tr-cover-lib">
    <div class="tr-cover-lib__toolbar">
        <input type="search" class="form-control" id="trcl-search" placeholder="Search covers: forklift, chemical, PPE…" aria-label="Search covers">
        <div class="tr-cover-cats" id="trcl-cats" role="group" aria-label="Cover categories"></div>
    </div>

    <section class="card mb-3" id="trcl-upload-card">
        <div class="card-header py-2"><h2 class="card-title h6 mb-0"><i class="fas fa-fw fa-upload me-2" aria-hidden="true"></i>Add covers to the library</h2></div>
        <div class="card-body">
            <div class="tr-cover-drop" id="trcl-drop">
                <div class="tr-cover-drop__icon"><i class="fas fa-image" aria-hidden="true"></i></div>
                <div class="fw-medium">Drop images here</div>
                <div class="small text-muted mb-3">JPEG, PNG, WebP or GIF, one or many at once. A wide image (16:9) fills a card best; a PNG with a transparent background sits on the tint like the built-in art. Each is shared to the library right away, labeled from its file name - rename or recategorize any of them below after.</div>
                <button type="button" class="btn btn-outline-primary" data-tr-upload-button><i class="fas fa-upload me-2" aria-hidden="true"></i>Choose images…</button>
                <div class="tr-drop__progress small text-muted mt-2" aria-live="polite"></div>
            </div>
            <ul class="list-group list-group-flush mt-2" id="trcl-queue"></ul>
        </div>
    </section>

    <div class="tr-cover-lib__grid" id="trcl-grid" aria-busy="true"></div>
    <div class="tr-cover-lib__empty text-muted" id="trcl-empty" hidden>No cover matches that search.</div>
</div>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_uploader.js?v=<?= filemtime(__DIR__ . '/js/training_uploader.js') ?>" defer></script>
<script src="/agent/js/training_cover_library.js?v=<?= filemtime(__DIR__ . '/js/training_cover_library.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
