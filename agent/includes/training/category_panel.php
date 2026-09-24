<?php
defined('TRAINING_PAGE') || exit;

/*
 * "Manage categories…" (spec §5.2, level 3): a .tr-panel slide-over, not a modal. Rows are
 * rendered by agent/js/training_list.js; saves go to category_save / category_archive.
 */
?>
<div class="tr-panel-scrim tr-panel-scrim--fixed" id="tr-cat-scrim" hidden></div>
<aside class="tr-panel tr-panel--fixed" id="tr-cat-panel" role="dialog" aria-modal="true" aria-labelledby="tr-cat-panel-title" hidden>
    <div class="tr-panel__header">
        <div>
            <h2 class="tr-panel__title" id="tr-cat-panel-title">Categories</h2>
            <div class="text-muted small">Group courses on the list and in the Learning Center.</div>
        </div>
        <button type="button" class="btn-close" id="tr-cat-close" aria-label="Close"></button>
    </div>
    <div class="tr-panel__body">
        <ul class="list-unstyled d-flex flex-column gap-2 mb-3" id="tr-cat-list"></ul>
        <form class="card card-body p-3" id="tr-cat-form" novalidate autocomplete="off">
            <div class="fw-semibold mb-2" id="tr-cat-form-title">New category</div>
            <input type="hidden" id="tr-cat-id" value="">
            <div class="mb-2">
                <label class="form-label" for="tr-cat-name">Name</label>
                <input type="text" class="form-control" id="tr-cat-name" maxlength="100" required>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-5">
                    <label class="form-label" for="tr-cat-color">Color</label>
                    <input type="color" class="form-control form-control-color w-100" id="tr-cat-color" value="#0D9488">
                </div>
                <div class="col-7">
                    <label class="form-label" for="tr-cat-icon">Icon</label>
                    <select class="form-select" id="tr-cat-icon"></select>
                </div>
            </div>
            <div class="tr-field-error mb-2" id="tr-cat-error" role="alert"></div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-link" id="tr-cat-reset">Clear</button>
                <button type="submit" class="btn btn-primary" id="tr-cat-save">Save category</button>
            </div>
        </form>
    </div>
</aside>
