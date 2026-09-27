<?php
defined('TRAINING_PAGE') || exit;

/*
 * The Create Content window (spec §5.4, plan A13): one static modal shell, driven by
 * agent/js/training_content_modal.js through TrainingContentModal.open({...}).
 *
 *   header   large title, tags, thumbnail (disabled until a type is chosen), EN | ES switch
 *   tabs     Content | Description | Resources (n) | Quiz (n) | Settings
 *   footer   save state + issues | Preview as learner, Delete, Done & add another, Done
 *
 * Nothing ever opens on top of it: KB search, confirm-replace, bank picker, move-to-section and
 * the like are .tr-panel slide-overs or .tr-confirm-bar strips inside .modal-content.
 */
$tr_cm_types = [
    'article' => ['Article', 'file-alt', 'Write, or import Word or a KB article'],
    'document' => ['Document', 'file-pdf', 'PDF, shown page by page'],
    'video' => ['Video', 'play-circle', 'Upload MP4, YouTube or Vimeo'],
    'image' => ['Image', 'image', 'Photo or diagram'],
    'quiz' => ['Quiz', 'question-circle', 'Graded questions'],
    'acknowledgment' => ['Acknowledgment', 'file-signature', 'A statement signed during the course'],
];
$tr_cm_limits = $tr_ctx->settings->clientLimits();
$tr_cm_mb = static fn(int $b): string => (string) max(1, intdiv($b, 1048576));
?>
<div class="modal fade tr-cm" id="tr-cm" tabindex="-1" aria-labelledby="tr-cm-title" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
        <div class="modal-content tr-panel-host" id="tr-cm-content">
            <div class="tr-cm__head">
                <h2 id="tr-cm-title">Add content</h2>
                <span class="tr-cm__context" id="tr-cm-context"></span>
                <div class="tr-cm__head-right">
                    <div class="tr-segment tr-segment--sm tr-lang-seg" role="radiogroup" aria-label="Language" id="tr-cm-langs" hidden></div>
                    <button type="button" class="btn-close tr-cm__close" id="tr-cm-close" aria-label="Close"></button>
                </div>
            </div>

            <div id="tr-cm-alerts" class="px-4 pt-2" aria-live="polite"></div>

            <div class="tr-cm__deleted" id="tr-cm-deleted" hidden>
                <div class="tr-empty__icon" aria-hidden="true"><i class="fas fa-trash-alt"></i></div>
                <p class="tr-empty__title">This was deleted</p>
                <p class="tr-empty__text">Someone deleted this content in another tab or window.</p>
                <div class="tr-empty__actions">
                    <button type="button" class="btn btn-primary" id="tr-cm-deleted-restore">Restore</button>
                    <button type="button" class="btn btn-outline-secondary" id="tr-cm-deleted-close">Close</button>
                </div>
            </div>

            <div class="tr-cm__top" id="tr-cm-top">
                <div class="tr-cm__top-main">
                    <input type="text" class="tr-cm__title" id="tr-cm-name" maxlength="200" placeholder="Content title" aria-label="Content title" autocomplete="off">
                    <div class="tr-cm__ref" id="tr-cm-name-ref"></div>
                    <div class="tr-field-error" id="tr-cm-name-error" role="alert"></div>
                    <div class="tr-cm__tags" id="tr-cm-tags" aria-label="Tags">
                        <i class="fas fa-tag" aria-hidden="true"></i>
                        <span class="tr-contents" id="tr-cm-tag-list"></span>
                        <input type="text" class="tr-tag-input" id="tr-cm-tag-input" list="tr-cm-tag-options" maxlength="60" placeholder="Tag name" aria-label="New tag" hidden>
                        <datalist id="tr-cm-tag-options"></datalist>
                        <button type="button" class="btn btn-sm btn-link text-decoration-none tr-edit-only" id="tr-cm-tag-add"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add tag</button>
                    </div>
                </div>
                <div class="tr-cm__thumb-wrap">
                    <div class="tr-cm__thumb tr-drop" id="tr-cm-thumb" aria-label="Thumbnail image">
                        <img id="tr-cm-thumb-img" alt="" hidden>
                        <span class="tr-cm__thumb-auto" id="tr-cm-thumb-auto" hidden>Auto</span>
                        <span class="tr-cm__thumb-label"><i class="fas fa-camera" aria-hidden="true"></i><span>Thumbnail</span></span>
                        <div class="tr-drop__progress" id="tr-cm-thumb-progress"></div>
                    </div>
                    <div class="d-flex gap-2 justify-content-center tr-edit-only">
                        <button type="button" class="btn btn-link btn-sm" id="tr-cm-thumb-upload" data-tr-upload-button>Upload…</button>
                        <button type="button" class="btn btn-link btn-sm text-danger" id="tr-cm-thumb-remove" hidden>Remove</button>
                    </div>
                </div>
            </div>

            <ul class="nav tr-tabs tr-cm__tabs" role="tablist" id="tr-cm-tabs">
                <li class="nav-item" role="presentation"><button type="button" class="nav-link active" id="tr-cm-tab-content" data-bs-toggle="tab" data-bs-target="#tr-cm-pane-content" role="tab" aria-controls="tr-cm-pane-content" aria-selected="true" data-tab="content">Content</button></li>
                <li class="nav-item" role="presentation"><button type="button" class="nav-link" id="tr-cm-tab-description" data-bs-toggle="tab" data-bs-target="#tr-cm-pane-description" role="tab" aria-controls="tr-cm-pane-description" aria-selected="false" tabindex="-1" data-tab="description">Description</button></li>
                <li class="nav-item" role="presentation"><button type="button" class="nav-link" id="tr-cm-tab-resources" data-bs-toggle="tab" data-bs-target="#tr-cm-pane-resources" role="tab" aria-controls="tr-cm-pane-resources" aria-selected="false" tabindex="-1" data-tab="resources">Resources <span class="tr-count" id="tr-cm-res-count">0</span></button></li>
                <li class="nav-item" role="presentation" id="tr-cm-tab-quiz-li"><button type="button" class="nav-link" id="tr-cm-tab-quiz" data-bs-toggle="tab" data-bs-target="#tr-cm-pane-quiz" role="tab" aria-controls="tr-cm-pane-quiz" aria-selected="false" tabindex="-1" data-tab="quiz">Quick check <span class="tr-count" id="tr-cm-quiz-count">0</span></button></li>
                <li class="nav-item" role="presentation"><button type="button" class="nav-link" id="tr-cm-tab-settings" data-bs-toggle="tab" data-bs-target="#tr-cm-pane-settings" role="tab" aria-controls="tr-cm-pane-settings" aria-selected="false" tabindex="-1" data-tab="settings">Settings</button></li>
            </ul>

            <div class="modal-body tr-cm__body" id="tr-cm-body">
                <div class="tab-content">
                    <!-- ============================== CONTENT ============================== -->
                    <div class="tab-pane active" id="tr-cm-pane-content" role="tabpanel" aria-labelledby="tr-cm-tab-content">
                        <div id="tr-cm-typepick">
                            <div class="tr-cm__label-row">
                                <span class="tr-cm__label" id="tr-cm-type-label">Content type</span>
                                <span class="tr-cm__hint" id="tr-cm-type-hint">Pick one, or drop a file anywhere in this window.</span>
                            </div>
                            <div class="tr-cm__types" role="radiogroup" aria-labelledby="tr-cm-type-label" id="tr-cm-types">
                                <?php foreach ($tr_cm_types as $tr_t => [$tr_l, $tr_i, $tr_h]) { ?>
                                <button type="button" class="tr-type-tile" role="radio" aria-checked="false" data-type="<?= $tr_t ?>">
                                    <span class="tr-type-tile__check" aria-hidden="true"><i class="fas fa-check"></i></span>
                                    <span class="tr-type-icon tr-type--<?= $tr_t ?>" aria-hidden="true"><i class="fas fa-<?= $tr_i ?>"></i></span>
                                    <span><span class="tr-type-tile__name"><?= $tr_l ?></span><span class="tr-type-tile__hint"><?= $tr_h ?></span></span>
                                </button>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap" id="tr-cm-typepill" hidden>
                            <div class="dropdown">
                                <button type="button" class="tr-cm__type-pill" data-bs-toggle="dropdown" aria-expanded="false" id="tr-cm-typepill-btn">
                                    <span class="tr-type-icon" id="tr-cm-typepill-icon" aria-hidden="true"><i class="fas fa-file-alt"></i></span>
                                    <span id="tr-cm-typepill-text">Type: Article</span>
                                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu" id="tr-cm-typepill-menu">
                                    <?php foreach ($tr_cm_types as $tr_t => [$tr_l, $tr_i]) { ?>
                                    <li><button type="button" class="dropdown-item" data-type="<?= $tr_t ?>"><span class="tr-type-icon tr-type-icon--sm tr-type--<?= $tr_t ?> me-2" aria-hidden="true"><i class="fas fa-<?= $tr_i ?>"></i></span><?= $tr_l ?></button></li>
                                    <?php } ?>
                                </ul>
                            </div>
                            <span class="text-muted small" id="tr-cm-typepill-note"></span>
                        </div>
                        <div id="tr-cm-type-confirm"></div>

                        <div class="tr-cm__content-grid mt-3" id="tr-cm-grid">
                            <div class="tr-min0">
                                <!-- no type yet -->
                                <div class="tr-cm__pane" data-pane="none">
                                    <div class="tr-cm__gate"><i class="fas fa-hand-pointer" aria-hidden="true"></i>Choose what kind of content this is. You can also drop a PDF, video, image or Word file here.</div>
                                </div>

                                <!-- ARTICLE -->
                                <div class="tr-cm__pane" data-pane="article" hidden>
                                    <div class="tr-cm__toolbar tr-edit-only">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="tr-cm-kb-btn" hidden><i class="fas fa-book me-1" aria-hidden="true"></i>Import from KB</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="tr-cm-docx-btn"><i class="fas fa-file-word me-1" aria-hidden="true"></i>Import Word (.docx)</button>
                                        <span class="tr-cm__wc" id="tr-cm-wc" aria-live="polite"></span>
                                    </div>
                                    <div id="tr-cm-kbchip"></div>
                                    <div id="tr-cm-docx-status" class="small" aria-live="polite"></div>
                                    <details class="tr-cm__refblock" id="tr-cm-body-ref" hidden><summary class="small text-muted">Show the English text</summary><div class="tr-article-box mt-2" id="tr-cm-body-ref-box"></div></details>
                                    <div class="tr-cm__editor"><textarea class="tr-tinymce" id="tr-cm-body-editor" aria-label="Article text"></textarea></div>
                                    <div class="tr-field-error" id="tr-cm-body-error" role="alert"></div>
                                    <p class="small text-muted mb-0">Imports keep headings, lists and pictures.<span id="tr-cm-kb-help" hidden> A KB import is a copy: later KB edits show "KB changed" here until you re-import.</span></p>
                                </div>

                                <!-- DOCUMENT -->
                                <div class="tr-cm__pane" data-pane="document" hidden>
                                    <div class="tr-drop" id="tr-cm-doc-drop">
                                        <span class="tr-drop__icon" aria-hidden="true"><i class="fas fa-file-upload"></i></span>
                                        <span class="tr-drop__title">Drop a PDF here or <button type="button" class="tr-drop__browse" data-tr-upload-button>browse</button></span>
                                        <span class="tr-drop__hint">Up to <?= $tr_cm_mb((int) $tr_cm_limits['pdf_max_bytes']) ?> MB · <?= (int) $tr_cm_limits['pdf_max_pages'] ?> pages</span>
                                        <span class="tr-drop__progress"></span>
                                    </div>
                                    <div id="tr-cm-doc-state" hidden>
                                        <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                                            <span class="tr-type-icon tr-type-icon--sm tr-type--document" aria-hidden="true"><i class="fas fa-file-pdf"></i></span>
                                            <span class="fw-semibold" id="tr-cm-doc-meta"></span>
                                            <span class="ms-auto d-flex gap-2">
                                                <a class="btn btn-sm btn-outline-secondary" id="tr-cm-doc-download" href="#" target="_blank" rel="noopener"><i class="fas fa-download me-1" aria-hidden="true"></i>Original</a>
                                                <button type="button" class="btn btn-sm btn-outline-secondary tr-edit-only" id="tr-cm-doc-replace"><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Replace…</button>
                                            </span>
                                        </div>
                                        <div class="tr-pages" id="tr-cm-doc-pages" aria-label="Pages"></div>
                                        <div class="mt-2" id="tr-cm-doc-progress-wrap" hidden>
                                            <div class="d-flex justify-content-between small text-muted mb-1"><span id="tr-cm-doc-progress-text">Preparing pages…</span><button type="button" class="btn btn-link btn-sm p-0" id="tr-cm-doc-resume" hidden>Resume</button></div>
                                            <div class="tr-progress"><div class="tr-progress__bar" id="tr-cm-doc-progress-bar"></div></div>
                                        </div>
                                    </div>
                                    <div class="tr-note" id="tr-cm-doc-restricted" hidden><i class="fas fa-lock" aria-hidden="true"></i><span>This PDF has copy/print restrictions; it's shown as page images.</span></div>
                                    <div class="tr-note"><i class="fas fa-info-circle" aria-hidden="true"></i><span>PowerPoint or Excel? Use File › Save As › PDF first. Word files work best as an Article: choose Article, then Import Word.</span></div>
                                </div>

                                <!-- VIDEO -->
                                <div class="tr-cm__pane" data-pane="video" hidden>
                                    <!-- Another language's tab with no video yet: play the default language's video (the translated text stays). -->
                                    <div class="tr-cm-vshare" id="tr-cm-vshare" hidden>
                                        <span class="tr-cm-vshare__icon" aria-hidden="true"><i class="fas fa-film"></i></span>
                                        <span class="tr-cm-vshare__body"><strong id="tr-cm-vshare-title"></strong><span class="small text-muted" id="tr-cm-vshare-sub"></span></span>
                                        <button type="button" class="btn btn-primary tr-edit-only" id="tr-cm-vshare-btn"></button>
                                    </div>
                                    <div>
                                        <div class="tr-cm__label mb-2" id="tr-cm-vsrc-label">Source</div>
                                        <div class="tr-segment" role="radiogroup" aria-labelledby="tr-cm-vsrc-label" id="tr-cm-vsrc">
                                            <button type="button" class="tr-segment__btn" role="radio" aria-checked="false" data-src="upload">Upload MP4</button>
                                            <button type="button" class="tr-segment__btn" role="radio" aria-checked="true" data-src="youtube"><i class="fab fa-youtube me-1" aria-hidden="true"></i>YouTube</button>
                                            <button type="button" class="tr-segment__btn" role="radio" aria-checked="false" data-src="vimeo"><i class="fab fa-vimeo-v me-1" aria-hidden="true"></i>Vimeo</button>
                                        </div>
                                    </div>
                                    <div data-vsrc="upload" hidden class="d-flex flex-column gap-3">
                                        <div class="tr-drop tr-drop--row" id="tr-cm-vid-drop">
                                            <span class="tr-drop__icon" aria-hidden="true"><i class="fas fa-upload"></i></span>
                                            <span class="d-flex flex-column gap-1">
                                                <span class="tr-drop__title">Drop an MP4 or MOV here or <button type="button" class="tr-drop__browse" data-tr-upload-button>browse</button></span>
                                                <span class="tr-drop__hint">Up to <?= $tr_cm_mb((int) $tr_cm_limits['video_max_bytes']) ?> MB. For longer videos, upload to the company YouTube channel as Unlisted and paste the link.</span>
                                                <span class="tr-drop__progress"></span>
                                            </span>
                                        </div>
                                        <div id="tr-cm-vid-state" hidden class="d-flex flex-column gap-2">
                                            <video class="tr-video" id="tr-cm-vid-player" controls preload="metadata" playsinline></video>
                                            <div class="tr-probe" id="tr-cm-vid-probe"></div>
                                            <div class="tr-note" id="tr-cm-vid-hevc" hidden><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span>This video uses HEVC. Windows PCs may not play it. On iPhone: Settings › Camera › Formats › Most Compatible.</span></div>
                                            <div class="tr-note" id="tr-cm-vid-faststart" hidden><i class="fas fa-info-circle" aria-hidden="true"></i><span>Plays fine; the first play may take a moment longer to start.</span></div>
                                        </div>
                                        <!-- Closed captions for this language's uploaded video (DB 2.6.98; shown only when flags.captions). -->
                                        <div class="tr-cm-cap" id="tr-cm-cap" hidden>
                                            <div class="tr-cm__label mb-2"><i class="fas fa-closed-captioning me-1" aria-hidden="true"></i>Captions (CC) <span class="tr-cm-cap__lang" id="tr-cm-cap-lang"></span></div>
                                            <div class="tr-drop tr-drop--row tr-drop--compact tr-edit-only" id="tr-cm-cap-drop">
                                                <span class="tr-drop__icon" aria-hidden="true"><i class="fas fa-closed-captioning"></i></span>
                                                <span class="d-flex flex-column gap-1">
                                                    <span class="tr-drop__title">Drop a caption file (.vtt or .srt) here or <button type="button" class="tr-drop__browse" data-tr-upload-button>browse</button></span>
                                                    <span class="tr-drop__hint">One file for this language, up to 1 MB. Learners turn captions on with the CC button; formatting and links are removed.</span>
                                                    <span class="tr-drop__progress"></span>
                                                </span>
                                            </div>
                                            <div class="tr-cap-file" id="tr-cm-cap-state" hidden>
                                                <span class="tr-cap-file__icon" aria-hidden="true"><i class="fas fa-closed-captioning"></i></span>
                                                <span class="tr-cap-file__body"><span class="tr-cap-file__name" id="tr-cm-cap-name"></span><span class="small text-muted" id="tr-cm-cap-meta"></span></span>
                                                <button type="button" class="btn btn-sm btn-outline-secondary tr-edit-only" id="tr-cm-cap-replace"><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Replace…</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger tr-edit-only" id="tr-cm-cap-remove"><i class="fas fa-times me-1" aria-hidden="true"></i>Remove</button>
                                            </div>
                                            <div class="tr-field-error" id="tr-cm-cap-error" role="alert"></div>
                                        </div>
                                    </div>
                                    <div data-vsrc="link" class="d-flex flex-column gap-3">
                                        <div>
                                            <label class="tr-cm__label d-block mb-2" for="tr-cm-vurl" id="tr-cm-vurl-label">Video link</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-link" aria-hidden="true"></i></span>
                                                <input type="url" class="form-control tr-mono" id="tr-cm-vurl" placeholder="https://youtu.be/…" autocomplete="off" inputmode="url">
                                                <button type="button" class="btn btn-outline-secondary tr-edit-only" id="tr-cm-vurl-check">Check link</button>
                                            </div>
                                            <div class="tr-field-error" id="tr-cm-vurl-error" role="alert"></div>
                                        </div>
                                        <div id="tr-cm-vcard" hidden></div>
                                        <div class="tr-frame-wrap" id="tr-cm-vframe-wrap" hidden>
                                            <div class="tr-frame-caption" id="tr-cm-vframe-caption"><i class="fas fa-play-circle" aria-hidden="true"></i><span>Press play once to confirm this video works.</span></div>
                                            <div id="tr-cm-vframe-host"></div>
                                        </div>
                                        <div class="tr-note"><i class="fas fa-closed-captioning" aria-hidden="true"></i><span>Captions (CC) come from the video itself: add them in YouTube Studio or Vimeo. Learners turn them on with the CC button.</span></div>
                                    </div>
                                    <div class="tr-range">
                                        <div class="d-flex align-items-baseline justify-content-between">
                                            <label class="tr-cm__label" for="tr-cm-watch">Must watch</label>
                                            <span class="tr-mono fw-semibold" id="tr-cm-watch-val">90%</span>
                                        </div>
                                        <input type="range" id="tr-cm-watch" min="50" max="100" step="5" value="90" data-tr-lfield="min_watch_pct">
                                        <div class="tr-range__scale" aria-hidden="true"><span>50%</span><span>75%</span><span>100%</span></div>
                                        <div class="small text-muted">The next lesson stays locked until they've watched this much. Skipping ahead doesn't count.</div>
                                    </div>
                                </div>

                                <!-- IMAGE -->
                                <div class="tr-cm__pane" data-pane="image" hidden>
                                    <div class="tr-drop" id="tr-cm-img-drop">
                                        <span class="tr-drop__icon" aria-hidden="true"><i class="fas fa-image"></i></span>
                                        <span class="tr-drop__title">Drop a photo or diagram here or <button type="button" class="tr-drop__browse" data-tr-upload-button>browse</button></span>
                                        <span class="tr-drop__hint">JPG, PNG, WebP or GIF, up to <?= $tr_cm_mb((int) $tr_cm_limits['image_max_bytes']) ?> MB. People can pinch to zoom.</span>
                                        <span class="tr-drop__progress"></span>
                                    </div>
                                    <div id="tr-cm-img-state" hidden class="d-flex flex-column gap-2 align-items-start">
                                        <button type="button" class="tr-image-preview" id="tr-cm-img-zoom" aria-label="Zoom the image"><img id="tr-cm-img" alt=""></button>
                                        <div class="d-flex gap-2 align-items-center">
                                            <span class="small text-muted" id="tr-cm-img-meta"></span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary tr-edit-only" id="tr-cm-img-replace"><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Replace…</button>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="tr-cm__label d-block mb-2" for="tr-cm-caption">Caption</label>
                                        <input type="text" class="form-control" id="tr-cm-caption" maxlength="500" placeholder="For example: Main disconnect for Press Brake 3">
                                        <div class="tr-lang-ref" id="tr-cm-caption-ref"></div>
                                        <div class="tr-field-error" id="tr-cm-caption-error"></div>
                                    </div>
                                </div>

                                <!-- QUIZ -->
                                <div class="tr-cm__pane" data-pane="quiz" hidden>
                                    <div class="tr-switch-row border rounded-3 px-3 tr-edit-only" id="tr-cm-exam-row">
                                        <div>
                                            <label class="tr-switch-row__label" for="tr-cm-exam">Final exam (must pass to finish)</label>
                                            <div class="tr-switch-row__hint">One per course. People must pass it to complete the course.</div>
                                        </div>
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-exam"></div>
                                    </div>
                                    <div id="tr-cm-exam-confirm"></div>
                                    <div class="tr-cm__gate" id="tr-cm-quiz-gate" hidden><i class="fas fa-pen" aria-hidden="true"></i>Give the quiz a title above to start adding questions.</div>
                                    <div class="tr-quiz-host" id="tr-cm-quiz-host"></div>
                                    <div class="d-flex justify-content-end"><a class="small" id="tr-cm-quiz-full" href="#" target="_blank" rel="noopener" hidden>Open full quiz builder <i class="fas fa-external-link-alt" aria-hidden="true"></i></a></div>
                                </div>

                                <!-- ACKNOWLEDGMENT -->
                                <div class="tr-cm__pane" data-pane="acknowledgment" hidden>
                                    <div>
                                        <label class="tr-cm__label d-block mb-2" for="tr-cm-ack-editor">Statement people sign</label>
                                        <details class="tr-cm__refblock mb-2" id="tr-cm-ack-ref" hidden><summary class="small text-muted">Show the English statement</summary><div class="tr-article-box mt-2" id="tr-cm-ack-ref-box"></div></details>
                                        <div class="tr-cm__editor"><textarea class="tr-tinymce" id="tr-cm-ack-editor" aria-label="Acknowledgment statement"></textarea></div>
                                        <div class="tr-field-error" id="tr-cm-ack-error" role="alert"></div>
                                        <div class="small text-muted mt-1">Keep it to one or two sentences. It shows right above the signature box.</div>
                                    </div>
                                    <div>
                                        <div class="tr-cm__label mb-2">On the tablet <span class="text-muted fw-normal small">(updates when saved)</span></div>
                                        <div class="tr-learner-block" aria-label="Learner preview">
                                            <div class="tr-statement" id="tr-cm-ack-preview"></div>
                                            <div class="tr-learner-block__check"><span class="tr-learner-block__box" aria-hidden="true"></span><span>I have read and understand</span></div>
                                            <div class="tr-learner-block__row">
                                                <div class="tr-learner-block__sig" id="tr-cm-ack-prev-sig"><span class="tr-learner-block__label position-absolute ms-2 mt-1">Signature</span></div>
                                                <div class="tr-learner-block__pin" id="tr-cm-ack-prev-pin">••••</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tr-switch-row border rounded-3 px-3">
                                        <div>
                                            <label class="tr-switch-row__label" for="tr-cm-ack-sig">Require finger signature</label>
                                            <div class="tr-switch-row__hint">They sign on the tablet. Name, time and device are saved with the record.</div>
                                        </div>
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-ack-sig" data-tr-lfield="ack_require_signature"></div>
                                    </div>
                                    <div class="tr-switch-row border rounded-3 px-3">
                                        <div>
                                            <label class="tr-switch-row__label" for="tr-cm-ack-pin">Require PIN</label>
                                            <div class="tr-switch-row__hint">They confirm with their PIN, so nobody signs for someone else.</div>
                                        </div>
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-ack-pin" data-tr-lfield="ack_require_pin"></div>
                                    </div>
                                </div>
                            </div>

                            <aside class="tr-cm__aside" id="tr-cm-aside" aria-label="Content settings">
                                <h3 class="tr-cm__aside-title">Settings</h3>
                                <dl class="mb-0">
                                    <dt>Responsible</dt>
                                    <dd id="tr-cm-aside-resp">—</dd>
                                    <dt>Duration</dt>
                                    <dd><span class="tr-mono fw-semibold" id="tr-cm-aside-dur">—</span> <span class="small text-muted" id="tr-cm-aside-dur-note"></span></dd>
                                </dl>
                                <hr>
                                <div class="tr-switch-row">
                                    <div><label class="tr-switch-row__label" for="tr-cm-aside-req">Required</label><div class="tr-switch-row__hint">Must finish to complete the course</div></div>
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-aside-req" data-tr-lfield="required"></div>
                                </div>
                                <div class="tr-switch-row" data-tr-show-types="document,video,image">
                                    <div><label class="tr-switch-row__label" for="tr-cm-aside-dl">Allow download</label><div class="tr-switch-row__hint">People can save a copy from the tablet</div></div>
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-aside-dl" data-tr-lfield="allow_download"></div>
                                </div>
                                <div class="tr-switch-row">
                                    <div><label class="tr-switch-row__label" for="tr-cm-aside-prev">Open without starting</label><div class="tr-switch-row__hint">Visible from the course page before someone starts</div></div>
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-aside-prev" data-tr-lfield="preview_enabled"></div>
                                </div>
                                <button type="button" class="btn btn-link btn-sm px-0" data-tr-cm-tab="settings">All settings</button>
                            </aside>
                        </div>
                    </div>

                    <!-- ============================== DESCRIPTION ============================== -->
                    <div class="tab-pane" id="tr-cm-pane-description" role="tabpanel" aria-labelledby="tr-cm-tab-description">
                        <div class="tr-cm__label-row">
                            <label class="tr-cm__label" for="tr-cm-desc-editor">Description</label>
                            <span class="tr-cm__hint">Shown above the lesson.</span>
                        </div>
                        <details class="tr-cm__refblock mb-2" id="tr-cm-desc-ref" hidden><summary class="small text-muted">Show the English description</summary><div class="tr-article-box mt-2" id="tr-cm-desc-ref-box"></div></details>
                        <div class="tr-cm__editor"><textarea class="tr-tinymce" id="tr-cm-desc-editor" aria-label="Description"></textarea></div>
                        <div class="tr-field-error" id="tr-cm-desc-error" role="alert"></div>
                    </div>

                    <!-- ============================== RESOURCES ============================== -->
                    <div class="tab-pane" id="tr-cm-pane-resources" role="tabpanel" aria-labelledby="tr-cm-tab-resources">
                        <div class="tr-cm__label-row">
                            <span class="tr-cm__label">Resources</span>
                            <span class="tr-cm__hint">Checklists, SDS sheets, forms and links people can open from the lesson.</span>
                        </div>
                        <div class="tr-cm__gate" id="tr-cm-res-gate" hidden><i class="fas fa-pen" aria-hidden="true"></i>Give the content a title first; then you can attach files and links.</div>
                        <ul class="tr-res-list mb-3" id="tr-cm-res-list"></ul>
                        <div class="tr-empty py-3" id="tr-cm-res-empty" hidden><p class="tr-empty__text mb-0">No resources yet.</p></div>
                        <div class="d-flex flex-wrap gap-2 tr-edit-only" id="tr-cm-res-actions">
                            <button type="button" class="btn btn-outline-secondary" id="tr-cm-res-upload"><i class="fas fa-upload me-1" aria-hidden="true"></i>Upload file…</button>
                            <button type="button" class="btn btn-outline-secondary" id="tr-cm-res-link"><i class="fas fa-link me-1" aria-hidden="true"></i>Add link</button>
                            <span class="small text-muted align-self-center" id="tr-cm-res-progress" aria-live="polite"></span>
                        </div>
                        <form class="tr-inline-form mt-3" id="tr-cm-res-linkform" hidden novalidate>
                            <input type="text" class="form-control" id="tr-cm-res-link-title" maxlength="200" placeholder="Title" aria-label="Link title">
                            <input type="url" class="form-control tr-mono" id="tr-cm-res-link-url" maxlength="500" placeholder="https://…" aria-label="Link address">
                            <button type="submit" class="btn btn-primary">Add</button>
                            <button type="button" class="btn btn-link" id="tr-cm-res-link-cancel">Cancel</button>
                            <div class="tr-field-error" id="tr-cm-res-link-error" role="alert"></div>
                        </form>
                    </div>

                    <!-- ============================== QUIZ (quick check) ============================== -->
                    <div class="tab-pane" id="tr-cm-pane-quiz" role="tabpanel" aria-labelledby="tr-cm-tab-quiz">
                        <div class="tr-switch-row border rounded-3 px-3 mb-3 tr-edit-only">
                            <div>
                                <label class="tr-switch-row__label" for="tr-cm-check">Quick check after this lesson</label>
                                <div class="tr-switch-row__hint">A few questions right after the content. Doesn't count toward completion; missed questions show their explanation.</div>
                            </div>
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-check"></div>
                        </div>
                        <div class="tr-cm__gate" id="tr-cm-check-gate" hidden><i class="fas fa-pen" aria-hidden="true"></i>Give the content a title first; then you can add a quick check.</div>
                        <div class="tr-quiz-host" id="tr-cm-check-host"></div>
                        <div class="d-flex justify-content-end"><a class="small" id="tr-cm-check-full" href="#" target="_blank" rel="noopener" hidden>Open full quiz builder <i class="fas fa-external-link-alt" aria-hidden="true"></i></a></div>
                    </div>

                    <!-- ============================== SETTINGS ============================== -->
                    <div class="tab-pane" id="tr-cm-pane-settings" role="tabpanel" aria-labelledby="tr-cm-tab-settings">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <label class="tr-cm__label d-block mb-2" for="tr-cm-resp">Responsible</label>
                                <select id="tr-cm-resp" placeholder="Choose a person"></select>
                                <div class="tr-field-error" id="tr-cm-resp-error"></div>
                                <div class="small text-muted mt-1">Who keeps this content up to date.</div>
                            </div>
                            <div class="col-lg-6">
                                <label class="tr-cm__label d-block mb-2" for="tr-cm-dur">Duration</label>
                                <div class="tr-duration">
                                    <input type="text" class="form-control" id="tr-cm-dur" placeholder="mm:ss" inputmode="numeric" aria-describedby="tr-cm-dur-auto" autocomplete="off">
                                    <span class="small text-muted" id="tr-cm-dur-auto"></span>
                                    <button type="button" class="btn btn-link btn-sm tr-edit-only" id="tr-cm-dur-reset" hidden>Reset</button>
                                </div>
                                <div class="tr-field-error" id="tr-cm-dur-error"></div>
                            </div>
                            <div class="col-lg-6">
                                <div class="tr-switch-row">
                                    <div><label class="tr-switch-row__label" for="tr-cm-set-req">Required</label><div class="tr-switch-row__hint">Off: optional. People can finish the course without it.</div></div>
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-set-req" data-tr-lfield="required"></div>
                                </div>
                                <div class="tr-switch-row" data-tr-show-types="document,video,image">
                                    <div><label class="tr-switch-row__label" for="tr-cm-set-dl">Allow download</label><div class="tr-switch-row__hint">People can save a copy of the file.</div></div>
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-set-dl" data-tr-lfield="allow_download"></div>
                                </div>
                                <div class="tr-switch-row">
                                    <div><label class="tr-switch-row__label" for="tr-cm-set-prev">Open without starting the course</label><div class="tr-switch-row__hint">Learners can open this from the course page before starting.</div></div>
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-cm-set-prev" data-tr-lfield="preview_enabled"></div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div data-tr-show-types="video" class="mb-3">
                                    <label class="tr-cm__label d-block mb-2" for="tr-cm-set-watch">Minimum watch</label>
                                    <div class="input-group tr-w-180">
                                        <input type="number" class="form-control" id="tr-cm-set-watch" min="0" max="100" step="5" data-tr-lfield="min_watch_pct">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <label class="tr-cm__label d-block mb-2" for="tr-cm-section">Section</label>
                                <select class="form-select" id="tr-cm-section"></select>
                                <div class="small text-muted mt-1">Moves the content to the end of that section.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tr-cm__foot">
                <div class="tr-cm__foot-left">
                    <span class="tr-save-state" id="tr-cm-save" data-state="idle" aria-live="polite"></span>
                    <div class="dropdown" id="tr-cm-issues" hidden>
                        <button type="button" class="btn btn-outline-secondary tr-cm__issues-btn" data-bs-toggle="dropdown" aria-expanded="false" id="tr-cm-issues-btn"></button>
                        <div class="dropdown-menu tr-issue-menu" id="tr-cm-issues-menu"></div>
                    </div>
                </div>
                <div class="tr-cm__foot-right">
                    <button type="button" class="btn btn-link" id="tr-cm-preview" hidden><i class="fas fa-eye me-1" aria-hidden="true"></i>Preview as learner</button>
                    <button type="button" class="btn btn-link text-danger tr-edit-only" id="tr-cm-delete" hidden>Delete</button>
                    <button type="button" class="btn btn-outline-secondary tr-edit-only" id="tr-cm-again">Done &amp; add another</button>
                    <button type="button" class="btn btn-primary" id="tr-cm-done">Done</button>
                </div>
            </div>

            <!-- in-window panels (never a stacked modal) -->
            <div class="tr-panel-scrim" id="tr-cm-scrim" hidden></div>
            <aside class="tr-panel" id="tr-cm-panel" role="dialog" aria-modal="true" aria-labelledby="tr-cm-panel-title" hidden>
                <div class="tr-panel__header">
                    <div>
                        <h3 class="tr-panel__title" id="tr-cm-panel-title"></h3>
                        <div class="text-muted small" id="tr-cm-panel-sub"></div>
                    </div>
                    <button type="button" class="btn-close" id="tr-cm-panel-close" aria-label="Close"></button>
                </div>
                <div class="tr-panel__body" id="tr-cm-panel-body"></div>
                <div class="tr-panel__footer" id="tr-cm-panel-footer"></div>
            </aside>
            <div class="tr-zoom" id="tr-cm-zoom" hidden role="dialog" aria-label="Image, full size"><img alt="" id="tr-cm-zoom-img"></div>
        </div>
    </div>
</div>
