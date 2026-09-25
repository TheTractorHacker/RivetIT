<?php
defined('TRAINING_PAGE') || exit;

/*
 * Publish modal (spec §5.10, level 3): a top-level modal opened from the builder header,
 * never from inside the Create Content window. Body sections are filled by
 * agent/js/training_publish.js from publish_check?network=1:
 *   1 Readiness (errors link to the lesson; warnings need "I've reviewed these warnings")
 *   2 What's changing (the diff)
 *   3 Change note (required, 5-1000 characters; "First version" for the first one)
 *   4 Retrain / re-acknowledge (training kind with a previous version; document kind)
 */
?>
<div class="modal fade tr-pub" id="tr-pub" tabindex="-1" aria-labelledby="tr-pub-title" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h3 mb-0 text-truncate" id="tr-pub-title">Publish</h2>
                <span class="tr-chip tr-chip--accent tr-pub__version" id="tr-pub-version"></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="tr-pub-body">
                <div class="tr-pub__checking" id="tr-pub-checking">
                    <span class="spinner-border text-secondary" aria-hidden="true"></span>
                    <span><strong class="d-block text-body">Checking the course…</strong><span id="tr-pub-checking-sub">Checking videos with YouTube and Vimeo. This takes up to 20 seconds.</span></span>
                </div>
                <div class="alert alert-danger d-none" id="tr-pub-load-error" role="alert"></div>

                <div id="tr-pub-form" hidden>
                    <section aria-labelledby="tr-pub-s1">
                        <h3 class="tr-pub__section-title" id="tr-pub-s1"><span class="tr-pub__step" aria-hidden="true">1</span>Readiness</h3>
                        <div id="tr-pub-ready"></div>
                    </section>
                    <section class="mt-3" aria-labelledby="tr-pub-s2">
                        <h3 class="tr-pub__section-title" id="tr-pub-s2"><span class="tr-pub__step" aria-hidden="true">2</span>What's changing</h3>
                        <div id="tr-pub-summary" class="tr-pub__summary mb-2"></div>
                        <ul class="tr-diff" id="tr-pub-diff"></ul>
                    </section>
                    <section class="mt-3" aria-labelledby="tr-pub-s3">
                        <h3 class="tr-pub__section-title" id="tr-pub-s3"><span class="tr-pub__step" aria-hidden="true">3</span><label for="tr-pub-note" class="mb-0">Change note</label></h3>
                        <textarea class="form-control" id="tr-pub-note" rows="3" maxlength="1000" required aria-describedby="tr-pub-note-hint tr-pub-note-error" placeholder="What changed and why. Shown in the version history."></textarea>
                        <div class="d-flex justify-content-between small mt-1">
                            <span class="text-muted" id="tr-pub-note-hint">5 to 1000 characters.</span>
                            <span class="text-muted tr-mono" id="tr-pub-note-count">0 / 1000</span>
                        </div>
                        <div class="tr-field-error" id="tr-pub-note-error" role="alert"></div>
                    </section>
                    <section class="mt-3" aria-labelledby="tr-pub-s4" id="tr-pub-retrain-section" hidden>
                        <h3 class="tr-pub__section-title" id="tr-pub-s4"><span class="tr-pub__step" aria-hidden="true">4</span><span id="tr-pub-retrain-heading">Retrain</span></h3>
                        <div class="tr-switch-row border rounded-3 px-3">
                            <div>
                                <label class="tr-switch-row__label" for="tr-pub-retrain" id="tr-pub-retrain-label">People who finished an earlier version must take it again</label>
                                <div class="tr-switch-row__hint">Takes effect when assignments go live.</div>
                            </div>
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-pub-retrain"></div>
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-2" id="tr-pub-days-row" hidden>
                            <label class="small" for="tr-pub-days">Due within</label>
                            <input type="number" class="form-control form-control-sm tr-w-180" id="tr-pub-days" min="1" max="365" value="30">
                            <span class="small text-muted">days</span>
                        </div>
                        <div class="tr-field-error" id="tr-pub-days-error" role="alert"></div>
                    </section>
                    <p class="tr-pub__explainer mt-3 mb-0"><i class="fas fa-user-shield mt-1" aria-hidden="true"></i><span>Publishing makes this the version employees take. They see it on the tablet once Training is switched on for them and the course is assigned to them. Later edits stay in your draft until you publish again.</span></p>
                </div>

                <div class="tr-pub__success" id="tr-pub-success" hidden>
                    <div class="tr-pub__success-icon" aria-hidden="true"><i class="fas fa-check"></i></div>
                    <h3 class="h2 mb-1" id="tr-pub-success-title">Version 1 published</h3>
                    <p class="text-muted mb-3" id="tr-pub-success-sub"></p>
                    <div class="d-flex justify-content-center gap-2 flex-wrap mb-3">
                        <a class="btn btn-outline-secondary" id="tr-pub-success-preview" href="#" target="_blank" rel="noopener" hidden><i class="fas fa-eye me-1" aria-hidden="true"></i>Preview</a>
                        <button type="button" class="btn btn-primary" id="tr-pub-success-done">Done</button>
                    </div>
                    <details class="d-inline-block text-start small"><summary class="text-muted">Details</summary><div class="tr-details mt-2" id="tr-pub-success-details"></div></details>
                </div>
            </div>
            <div class="modal-footer" id="tr-pub-footer">
                <span class="text-danger small me-auto" id="tr-pub-error" role="alert"></span>
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="tr-pub-submit" disabled><span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span><span id="tr-pub-submit-label">Publish</span></button>
            </div>
        </div>
    </div>
</div>
