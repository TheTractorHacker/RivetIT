<?php
defined('TRAINING_PAGE') || exit;

/*
 * Content tab, training kind (spec §5.3): the outline (sections, drag-drop lessons, inline
 * section rename, bulk upload) and the sticky aside (course card, to-do, languages, versions,
 * quick add). agent/js/training_builder.js renders every list from the page data.
 */
$tr_types = [
    'article' => ['Article', 'file-alt'],
    'document' => ['Document', 'file-pdf'],
    'video' => ['Video', 'play-circle'],
    'image' => ['Image', 'image'],
    'quiz' => ['Quiz', 'question-circle'],
    'acknowledgment' => ['Acknowledgment', 'file-signature'],
];
?>
<div class="tr-b-grid">
    <div class="tr-b-main tr-min0">
        <div class="tr-outline-bar">
            <span class="tr-outline-bar__summary" id="tr-outline-summary" aria-live="polite"></span>
            <button type="button" class="btn btn-link tr-edit-only" id="tr-bulk-upload"><i class="fas fa-upload me-1" aria-hidden="true"></i>Upload files…</button>
            <button type="button" class="btn btn-link" id="tr-collapse-all" hidden><i class="fas fa-angle-double-up me-1" aria-hidden="true"></i><span>Collapse all</span></button>
        </div>
        <div id="tr-outline-confirm"></div>
        <div class="tr-outline" id="tr-outline" aria-busy="true">
            <div class="tr-section tr-skeleton" aria-hidden="true">
                <div class="p-3"><span class="tr-skeleton__line tr-skeleton__line--title"></span><span class="tr-skeleton__row"></span><span class="tr-skeleton__row"></span></div>
            </div>
        </div>
        <button type="button" class="tr-add-btn tr-add-section mt-3 tr-edit-only" id="tr-add-section"><i class="fas fa-plus" aria-hidden="true"></i>Add section</button>
    </div>

    <aside class="tr-sticky-aside" id="tr-aside" aria-label="Course overview">
        <section class="tr-aside-card" aria-labelledby="tr-aside-course-title">
            <div class="tr-aside-card__head">
                <h2 class="tr-aside-card__title" id="tr-aside-course-title">Course</h2>
                <button type="button" class="tr-aside-link tr-edit-only" data-tr-goto="settings"><i class="fas fa-pen" aria-hidden="true"></i>Edit details</button>
            </div>
            <div class="tr-cover tr-drop" id="tr-cover" aria-label="Course cover">
                <span class="tr-cover__glyph" id="tr-cover-glyph" aria-hidden="true"><i class="fas fa-graduation-cap"></i></span>
                <img id="tr-cover-img" alt="" hidden>
                <div class="tr-drop__progress" id="tr-cover-progress" aria-live="polite"></div>
                <div class="tr-cover__actions tr-edit-only">
                    <button type="button" class="tr-cover__btn" id="tr-cover-upload" data-tr-upload-button><i class="fas fa-image" aria-hidden="true"></i><span id="tr-cover-upload-label">Add cover</span></button>
                    <button type="button" class="tr-cover__btn" id="tr-cover-video" hidden><i class="fas fa-film" aria-hidden="true"></i>Use video thumbnail</button>
                    <button type="button" class="tr-cover__btn" id="tr-cover-remove" hidden aria-label="Remove cover"><i class="fas fa-times" aria-hidden="true"></i></button>
                </div>
            </div>
            <dl class="tr-facts" id="tr-facts"></dl>
            <p class="tr-aside-summary" id="tr-aside-summary" hidden></p>
            <div class="tr-aside-tags" id="tr-aside-tags"></div>
        </section>

        <section class="tr-aside-card tr-edit-only" aria-labelledby="tr-todo-title">
            <div class="tr-aside-card__head">
                <h2 class="tr-aside-card__title" id="tr-todo-title">To do</h2>
                <button type="button" class="tr-aside-link" id="tr-todo-refresh"><i class="fas fa-sync-alt" aria-hidden="true"></i>Check again</button>
            </div>
            <div id="tr-todo" aria-live="polite">
                <span class="tr-skeleton__line"></span><span class="tr-skeleton__line tr-skeleton__line--short"></span>
            </div>
        </section>

        <section class="tr-aside-card" aria-labelledby="tr-langs-title" id="tr-langs-card">
            <div class="tr-aside-card__head">
                <h2 class="tr-aside-card__title" id="tr-langs-title">Languages</h2>
            </div>
            <p class="tr-aside-card__sub">Learners switch between languages on the tablet.</p>
            <div id="tr-langs"></div>
        </section>

        <section class="tr-aside-card" aria-labelledby="tr-vmini-title">
            <div class="tr-aside-card__head">
                <h2 class="tr-aside-card__title" id="tr-vmini-title">Versions</h2>
                <button type="button" class="tr-aside-link tr-aside-link--accent" data-tr-goto="versions">See all</button>
            </div>
            <ul class="tr-versions-mini" id="tr-vmini"></ul>
            <div class="tr-note" id="tr-vmini-note" hidden><i class="fas fa-info-circle" aria-hidden="true"></i><span></span></div>
        </section>

        <section class="tr-aside-card tr-edit-only" aria-labelledby="tr-quick-title">
            <div class="tr-aside-card__head">
                <h2 class="tr-aside-card__title" id="tr-quick-title">Quick add</h2>
            </div>
            <div class="tr-type-grid tr-type-grid--compact" id="tr-quick">
                <?php foreach ($tr_types as $tr_type => [$tr_label, $tr_icon]) { ?>
                <button type="button" class="tr-type-tile" data-tr-quick="<?= $tr_type ?>">
                    <span class="tr-type-icon tr-type-icon--sm tr-type--<?= $tr_type ?>" aria-hidden="true"><i class="fas fa-<?= $tr_icon ?>"></i></span>
                    <?= $tr_label ?>
                </button>
                <?php } ?>
            </div>
        </section>
    </aside>
</div>
