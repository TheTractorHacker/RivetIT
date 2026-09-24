<?php
defined('TRAINING_PAGE') || exit;

/*
 * Content tab, document kind (spec §5.3, plan A14): one centred column with three steps -
 * the document itself (PDF or article), the acknowledgment (fixed; cannot be deleted) and an
 * optional knowledge check (the embedded quiz builder). Rendered by training_builder.js.
 */
?>
<div class="tr-doc" id="tr-doc">
    <section class="tr-step" id="tr-doc-step-doc" aria-labelledby="tr-doc-step-doc-title">
        <span class="tr-step__num" aria-hidden="true">1</span>
        <div class="tr-min0">
            <div class="tr-step__head">
                <h2 class="tr-step__title" id="tr-doc-step-doc-title">Document</h2>
                <span class="tr-step__status" id="tr-doc-step-doc-status"></span>
            </div>
            <div class="tr-step__body" id="tr-doc-step-doc-body">
                <span class="tr-skeleton__row"></span>
            </div>
        </div>
    </section>

    <section class="tr-step" id="tr-doc-step-ack" aria-labelledby="tr-doc-step-ack-title">
        <span class="tr-step__num" aria-hidden="true">2</span>
        <div class="tr-min0">
            <div class="tr-step__head">
                <h2 class="tr-step__title" id="tr-doc-step-ack-title">Acknowledgment</h2>
                <span class="tr-step__status" id="tr-doc-step-ack-status"></span>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto tr-edit-only" id="tr-doc-ack-edit"><i class="fas fa-pen me-1" aria-hidden="true"></i>Edit</button>
            </div>
            <div class="tr-step__body" id="tr-doc-step-ack-body">
                <span class="tr-skeleton__row"></span>
            </div>
        </div>
    </section>

    <section class="tr-step" id="tr-doc-step-check" aria-labelledby="tr-doc-step-check-title">
        <span class="tr-step__num" aria-hidden="true">3</span>
        <div class="tr-min0">
            <div class="tr-step__head">
                <h2 class="tr-step__title" id="tr-doc-step-check-title">Knowledge check <span class="text-muted fw-normal">(optional)</span></h2>
                <div class="form-check form-switch ms-auto mb-0 tr-edit-only">
                    <input class="form-check-input" type="checkbox" role="switch" id="tr-doc-check-switch">
                    <label class="form-check-label" for="tr-doc-check-switch">Add 3–5 questions</label>
                </div>
            </div>
            <p class="text-muted small mb-0">A few questions after reading. Missed questions show their explanation; there is no pass mark to fail.</p>
            <div class="tr-step__body" id="tr-doc-step-check-body"></div>
        </div>
    </section>

    <div class="tr-note">
        <i class="fas fa-info-circle" aria-hidden="true"></i>
        <span>A required document is always one document plus its acknowledgment. Need sections, videos or an exam? Use <strong>Duplicate as training course</strong> in the <i class="fas fa-ellipsis-h" aria-hidden="true"></i> menu.</span>
    </div>
</div>
