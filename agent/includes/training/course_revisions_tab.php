<?php
defined('TRAINING_PAGE') || exit;

/*
 * Versions tab (spec §5.3): the draft card ("Unpublished changes: …" from revision_diff, with
 * Review & publish) and the timeline of published versions from revision_list (Preview,
 * Compare to draft in a panel, Details with sha12 and the hash check). Authors see
 * "Version N" and "Published"; hashes sit behind Details.
 */
?>
<div class="tr-versions tr-panel-host" id="tr-versions">
    <section class="card tr-draft-card" id="tr-draft-card" aria-labelledby="tr-draft-title">
        <div class="card-header">
            <h2 class="card-title" id="tr-draft-title">Draft</h2>
            <?php if ($tr_can_full && !$tr_archived) { ?>
            <button type="button" class="btn btn-primary btn-sm" id="tr-draft-publish" hidden><i class="fas fa-rocket me-1" aria-hidden="true"></i>Review &amp; publish</button>
            <?php } ?>
        </div>
        <div class="card-body" id="tr-draft-body" aria-live="polite">
            <span class="tr-skeleton__line"></span><span class="tr-skeleton__line tr-skeleton__line--short"></span>
        </div>
    </section>

    <section class="card" aria-labelledby="tr-timeline-title">
        <div class="card-header">
            <h2 class="card-title" id="tr-timeline-title">Published versions</h2>
        </div>
        <div class="card-body" id="tr-timeline-body">
            <span class="tr-skeleton__row"></span>
        </div>
    </section>

    <div class="tr-panel-scrim tr-panel-scrim--fixed" id="tr-compare-scrim" hidden></div>
    <aside class="tr-panel tr-panel--fixed tr-panel--wide" id="tr-compare-panel" role="dialog" aria-modal="true" aria-labelledby="tr-compare-title" hidden>
        <div class="tr-panel__header">
            <div>
                <h2 class="tr-panel__title" id="tr-compare-title">Compare to draft</h2>
                <div class="text-muted small" id="tr-compare-sub"></div>
            </div>
            <button type="button" class="btn-close" id="tr-compare-close" aria-label="Close"></button>
        </div>
        <div class="tr-panel__body" id="tr-compare-body"></div>
    </aside>
</div>
