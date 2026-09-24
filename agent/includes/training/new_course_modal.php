<?php
defined('TRAINING_PAGE') || exit;

/*
 * New course window (spec §5.2): left rail (Start from scratch / Templates / Safety starters),
 * cards with an outline preview in the centre, and the Name / Category / Languages form with
 * Create course at the bottom. Cards and the category list are rendered by
 * agent/js/training_list.js from the page data (TemplateCatalog, CategoryService).
 */

$tr_nc_langs = array_values(array_filter($tr_ctx->settings->languages, static fn($l) => $l !== 'en'));
$tr_nc_lang_label = ['es' => 'Spanish'];
?>
<div class="modal fade tr-nc" id="tr-new-course" tabindex="-1" aria-labelledby="tr-nc-title" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-lg-down">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title h3 mb-0" id="tr-nc-title">New course</h2>
                    <div class="text-muted small">Pick a starting point. Everything can be changed later.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="tr-nc__layout">
                    <div class="tr-nc__rail" role="tablist" aria-label="Start from" aria-orientation="vertical">
                        <button type="button" class="tr-nc__rail-btn" role="tab" id="tr-nc-tab-scratch" aria-controls="tr-nc-cards" aria-selected="true" data-group="scratch"><i class="fas fa-plus" aria-hidden="true"></i>Start from scratch</button>
                        <button type="button" class="tr-nc__rail-btn" role="tab" id="tr-nc-tab-template" aria-controls="tr-nc-cards" aria-selected="false" tabindex="-1" data-group="template"><i class="fas fa-shapes" aria-hidden="true"></i>Templates</button>
                        <button type="button" class="tr-nc__rail-btn" role="tab" id="tr-nc-tab-safety" aria-controls="tr-nc-cards" aria-selected="false" tabindex="-1" data-group="safety"><i class="fas fa-hard-hat" aria-hidden="true"></i>Safety starters</button>
                    </div>
                    <div class="tr-nc__main">
                        <div class="tr-nc__cards" id="tr-nc-cards" role="radiogroup" aria-labelledby="tr-nc-tab-scratch"></div>
                        <form class="tr-nc__form" id="tr-nc-form" novalidate autocomplete="off">
                            <div class="tr-nc__cover">
                                <span class="form-label" id="tr-nc-cover-label">Cover</span>
                                <button type="button" class="tr-nc__cover-art tr-cover-art" id="tr-nc-cover-art" aria-labelledby="tr-nc-cover-label tr-nc-cover-name" title="Change cover">
                                    <img alt="" id="tr-nc-cover-img" hidden>
                                    <span class="tr-nc__cover-none" id="tr-nc-cover-none" hidden><i class="fas fa-image" aria-hidden="true"></i>No cover</span>
                                </button>
                                <span class="tr-nc__cover-name" id="tr-nc-cover-name" aria-live="polite"></span>
                                <button type="button" class="btn btn-link btn-sm tr-nc__cover-change" id="tr-nc-cover-change"><i class="fas fa-images me-1" aria-hidden="true"></i>Change cover</button>
                            </div>
                            <div>
                                <label class="form-label required" for="tr-nc-name">Name</label>
                                <input type="text" class="form-control" id="tr-nc-name" maxlength="200" required placeholder="Lockout/Tagout - Authorized Employee">
                                <div class="invalid-feedback" id="tr-nc-name-error"></div>
                            </div>
                            <div>
                                <label class="form-label" for="tr-nc-category">Category</label>
                                <select class="form-select" id="tr-nc-category"></select>
                            </div>
                            <div class="d-flex flex-column gap-1 pb-1">
                                <?php foreach ($tr_nc_langs as $tr_nc_l) { ?>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="tr-nc-lang-<?= nullable_htmlentities($tr_nc_l) ?>" data-tr-lang="<?= nullable_htmlentities($tr_nc_l) ?>">
                                    <label class="form-check-label text-nowrap" for="tr-nc-lang-<?= nullable_htmlentities($tr_nc_l) ?>">Add <?= nullable_htmlentities($tr_nc_lang_label[$tr_nc_l] ?? strtoupper($tr_nc_l)) ?></label>
                                </div>
                                <?php } ?>
                            </div>
                            <div class="tr-nc__note" id="tr-nc-note" aria-live="polite"></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <span class="text-danger small me-auto" id="tr-nc-error" role="alert"></span>
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="tr-nc-create"><span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span>Create course</button>
            </div>
        </div>
    </div>
</div>
