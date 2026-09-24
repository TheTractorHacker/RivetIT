<?php
defined('TRAINING_PAGE') || exit;

/*
 * Settings tab (spec §5.3). Every field autosaves through the page's course store (text 800 ms,
 * toggles at once); each card shows its own .tr-save-state. Fields carry
 *   data-tr-field  the course_update field (name, code, summary, …)
 *   data-tr-type   text | int | bool | select | html
 *   data-tr-i18n   translatable: the "Editing text in" language picks the value (course_update lang)
 * Tags, languages, prerequisites and the responsible person use their own actions.
 */
$tr_offered = $tr_course['languages'];
$tr_settings_langs = $tr_ctx->settings->languages;
$tr_lang_names = \ITFlow\Training\Core\TrainingSettings::KNOWN_LANGUAGES;
$tr_default = (string) $tr_course['default_language'];
$tr_ro = $tr_archived ? ' disabled' : '';
?>
<div class="tr-settings" id="tr-settings">
    <?php if (count($tr_settings_langs) > 1) { ?>
    <div class="d-flex flex-wrap align-items-center gap-2" id="tr-set-langbar"<?= count($tr_offered) > 1 ? '' : ' hidden' ?>>
        <span class="text-muted small">Editing text in</span>
        <div class="tr-segment tr-segment--sm tr-lang-seg" role="radiogroup" aria-label="Language of the text fields" id="tr-set-lang"></div>
        <span class="text-muted small" id="tr-set-lang-hint"></span>
    </div>
    <?php } ?>

    <section class="card" data-tr-card="basics" aria-labelledby="tr-set-basics">
        <div class="card-header">
            <h2 class="card-title" id="tr-set-basics">Basics</h2>
            <span class="tr-save-state" data-state="idle" aria-live="polite"></span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required" for="tr-set-name">Name</label>
                    <input type="text" class="form-control" id="tr-set-name" maxlength="200" data-tr-field="name" data-tr-type="text" data-tr-i18n="1"<?= $tr_ro ?>>
                    <div class="tr-lang-ref" data-tr-ref="name"></div>
                    <div class="tr-field-error" data-tr-error="name"></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tr-set-code">Code</label>
                    <input type="text" class="form-control tr-mono" id="tr-set-code" maxlength="40" placeholder="e.g. SAF-LOTO-01" data-tr-field="code" data-tr-type="text"<?= $tr_ro ?>>
                    <div class="tr-field-error" data-tr-error="code"></div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="tr-set-summary">Summary</label>
                    <textarea class="form-control" id="tr-set-summary" rows="2" maxlength="500" placeholder="One or two sentences shown on the course card." data-tr-field="summary" data-tr-type="text" data-tr-i18n="1"<?= $tr_ro ?>></textarea>
                    <div class="tr-lang-ref" data-tr-ref="summary"></div>
                    <div class="tr-field-error" data-tr-error="summary"></div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="tr-set-description">Description</label>
                    <textarea class="form-control tr-tinymce" id="tr-set-description" rows="6" data-tr-field="description_html" data-tr-type="html" data-tr-i18n="1"<?= $tr_ro ?>></textarea>
                    <div class="form-hint">Shown on the course page before someone starts.</div>
                    <div class="tr-field-error" data-tr-error="description_html"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="tr-set-category">Category</label>
                    <select class="form-select" id="tr-set-category" data-tr-field="category_id" data-tr-type="select"<?= $tr_ro ?>></select>
                    <div class="tr-field-error" data-tr-error="category_id"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="tr-set-tags">Tags</label>
                    <select id="tr-set-tags" multiple placeholder="Add tags"<?= $tr_ro ?>></select>
                    <div class="tr-field-error" id="tr-set-tags-error"></div>
                </div>
                <div class="col-12">
                    <span class="form-label d-block">Cover</span>
                    <div class="tr-cover-field">
                        <div class="tr-cover tr-drop" id="tr-set-cover">
                            <span class="tr-cover__glyph" aria-hidden="true"><i class="fas fa-image"></i></span>
                            <img alt="" hidden>
                            <div class="tr-drop__progress" aria-live="polite"></div>
                        </div>
                        <div class="d-flex flex-column gap-2 align-items-start">
                            <div class="d-flex flex-wrap gap-2 tr-edit-only">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="tr-set-cover-upload"><i class="fas fa-upload me-1" aria-hidden="true"></i>Upload…</button>
                                <button type="button" class="btn btn-link btn-sm text-danger" id="tr-set-cover-remove" hidden>Remove</button>
                            </div>
                            <div class="form-hint">A wide photo works best (16:9). JPG, PNG or WebP. Without one, the card uses the category colour.</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="tr-set-responsible">Responsible</label>
                    <select id="tr-set-responsible" placeholder="Choose a person"<?= $tr_ro ?>></select>
                    <div class="form-hint">Filters "Mine" on the course list. Receives broken-video and KB-changed notices once reminders go live.</div>
                    <div class="tr-field-error" data-tr-error="responsible_user_id"></div>
                </div>
            </div>
        </div>
    </section>

    <?php if (count($tr_settings_langs) > 1) { ?>
    <section class="card" data-tr-card="languages" aria-labelledby="tr-set-languages">
        <div class="card-header">
            <h2 class="card-title" id="tr-set-languages">Languages</h2>
            <span class="tr-save-state" data-state="idle" aria-live="polite" id="tr-set-lang-state"></span>
        </div>
        <div class="card-body">
            <div class="tr-switch-row">
                <div>
                    <div class="tr-switch-row__label"><?= nullable_htmlentities($tr_lang_names[$tr_default] ?? strtoupper($tr_default)) ?> <span class="text-muted fw-normal">(default)</span></div>
                    <div class="tr-switch-row__hint">Always offered and always required.</div>
                </div>
                <span class="tr-chip tr-chip--ok"><i class="fas fa-lock" aria-hidden="true"></i>Default</span>
            </div>
            <?php foreach ($tr_settings_langs as $tr_l) {
                if ($tr_l === $tr_default) { continue; }
                $tr_ln = $tr_lang_names[$tr_l] ?? strtoupper($tr_l);
                $tr_ln_en = $tr_l === 'es' ? 'Spanish' : $tr_ln; ?>
            <div class="border-top pt-2 mt-1" data-tr-langrow="<?= nullable_htmlentities($tr_l) ?>">
                <div class="tr-switch-row">
                    <div>
                        <label class="tr-switch-row__label" for="tr-set-offer-<?= nullable_htmlentities($tr_l) ?>">Offer <?= nullable_htmlentities($tr_ln_en) ?></label>
                        <div class="tr-switch-row__hint">Learners can switch to <?= nullable_htmlentities($tr_ln) ?>. It is published only once it is complete, unless you require it below.</div>
                    </div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-set-offer-<?= nullable_htmlentities($tr_l) ?>" data-tr-offer="<?= nullable_htmlentities($tr_l) ?>"<?= $tr_ro ?>></div>
                </div>
                <div class="tr-switch-row">
                    <div>
                        <label class="tr-switch-row__label" for="tr-set-require-<?= nullable_htmlentities($tr_l) ?>"><?= nullable_htmlentities($tr_ln_en) ?> required for this course</label>
                        <div class="tr-switch-row__hint">Blocks publishing until every lesson and quiz is translated. Off: an unfinished translation is left out and the course publishes in <?= nullable_htmlentities($tr_lang_names[$tr_default] ?? $tr_default) ?> only.</div>
                    </div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-set-require-<?= nullable_htmlentities($tr_l) ?>" data-tr-require="<?= nullable_htmlentities($tr_l) ?>"<?= $tr_ro ?>></div>
                </div>
            </div>
            <?php } ?>
            <div id="tr-set-lang-confirm"></div>
            <div class="tr-field-error" id="tr-set-lang-error" role="alert"></div>
        </div>
    </section>
    <?php } ?>

    <section class="card" data-tr-card="flow" aria-labelledby="tr-set-flow">
        <div class="card-header">
            <h2 class="card-title" id="tr-set-flow">Learning flow</h2>
            <span class="tr-save-state" data-state="idle" aria-live="polite"></span>
        </div>
        <div class="card-body">
            <div class="tr-switch-row">
                <div>
                    <label class="tr-switch-row__label" for="tr-set-sequential">Take lessons in order</label>
                    <div class="tr-switch-row__hint">Each lesson opens when the one before it is done. Lessons marked "Open without starting" are never locked.</div>
                </div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-set-sequential" data-tr-field="sequential" data-tr-type="bool"<?= $tr_ro ?>></div>
            </div>
            <div class="border-top pt-3 mt-1">
                <label class="form-label" for="tr-set-est">Estimated time</label>
                <div class="tr-est">
                    <input type="number" class="form-control" id="tr-set-est" min="1" max="6000" step="1" data-tr-field="est_minutes" data-tr-type="int" aria-describedby="tr-set-est-auto"<?= $tr_ro ?>>
                    <span class="text-muted">minutes</span>
                    <span class="text-muted small" id="tr-set-est-auto"></span>
                    <button type="button" class="btn btn-link btn-sm tr-edit-only" id="tr-set-est-reset" hidden>Use the automatic estimate</button>
                </div>
                <div class="tr-field-error" data-tr-error="est_minutes"></div>
            </div>
        </div>
    </section>

    <section class="card" data-tr-card="prereqs" aria-labelledby="tr-set-prereqs">
        <div class="card-header">
            <h2 class="card-title" id="tr-set-prereqs">Prerequisites</h2>
            <span class="tr-save-state" data-state="idle" aria-live="polite" id="tr-set-prereq-state"></span>
        </div>
        <div class="card-body">
            <label class="form-label" for="tr-set-prereq">People must complete these first</label>
            <select id="tr-set-prereq" multiple placeholder="Choose courses"<?= $tr_ro ?>></select>
            <div class="tr-field-error" id="tr-set-prereq-error" role="alert"></div>
            <div class="form-hint">Up to 10. A loop (A needs B, B needs A) is not allowed.</div>
        </div>
    </section>

    <details class="card" data-tr-card="completion">
        <summary class="card-header">
            <h2 class="card-title"><i class="fas fa-chevron-right tr-summary-chev me-2" aria-hidden="true"></i>Completion rules <span class="text-muted fw-normal small">(used when assignments go live)</span></h2>
            <span class="tr-save-state" data-state="idle" aria-live="polite"></span>
        </summary>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12">
                    <div class="tr-switch-row pt-0">
                        <div>
                            <label class="tr-switch-row__label" for="tr-set-signature">Sign to finish the course</label>
                            <div class="tr-switch-row__hint">People sign the statement below on the tablet when they complete it.</div>
                        </div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-set-signature" data-tr-field="requires_signature" data-tr-type="bool"<?= $tr_ro ?>></div>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="tr-set-attestation">Completion statement</label>
                    <textarea class="form-control" id="tr-set-attestation" rows="2" maxlength="5000" data-tr-field="attestation_text" data-tr-type="text" data-tr-i18n="1"<?= $tr_ro ?>></textarea>
                    <div class="tr-lang-ref" data-tr-ref="attestation_text"></div>
                    <div class="tr-field-error" data-tr-error="attestation_text"></div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="tr-set-validity">Valid for (months)</label>
                    <input type="number" class="form-control" id="tr-set-validity" min="1" max="600" placeholder="Never expires" data-tr-field="validity_months" data-tr-type="int"<?= $tr_ro ?>>
                    <div class="tr-field-error" data-tr-error="validity_months"></div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="tr-set-lead">Remind before expiry (days)</label>
                    <input type="number" class="form-control" id="tr-set-lead" min="0" max="365" data-tr-field="renewal_lead_days" data-tr-type="int" data-tr-required="1"<?= $tr_ro ?>>
                    <div class="tr-field-error" data-tr-error="renewal_lead_days"></div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="tr-set-regref">Regulation reference</label>
                    <input type="text" class="form-control tr-mono" id="tr-set-regref" maxlength="100" placeholder="e.g. 1910.147(c)(7)" data-tr-field="regulation_ref" data-tr-type="text"<?= $tr_ro ?>>
                    <div class="tr-field-error" data-tr-error="regulation_ref"></div>
                </div>
                <div class="col-12">
                    <div class="tr-switch-row">
                        <div>
                            <label class="tr-switch-row__label" for="tr-set-qual">Qualification</label>
                            <div class="tr-switch-row__hint">Completing it qualifies someone for a job or a machine (for example forklift or crane).</div>
                        </div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-set-qual" data-tr-field="is_qualification" data-tr-type="bool"<?= $tr_ro ?>></div>
                    </div>
                </div>
                <div class="col-12">
                    <span class="form-label d-block">Parts of the course</span>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="tr-set-online" data-tr-field="needs_online" data-tr-type="bool"<?= $tr_ro ?>><label class="form-check-label" for="tr-set-online">Online lessons</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="tr-set-session" data-tr-field="needs_session" data-tr-type="bool"<?= $tr_ro ?>><label class="form-check-label" for="tr-set-session">Classroom session</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="tr-set-practical" data-tr-field="needs_practical" data-tr-type="bool"<?= $tr_ro ?>><label class="form-check-label" for="tr-set-practical">Practical evaluation</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="tr-set-external" data-tr-field="external_only" data-tr-type="bool"<?= $tr_ro ?>><label class="form-check-label" for="tr-set-external">External only (card from an outside trainer)</label></div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="tr-set-window">Finish all parts within (days)</label>
                    <input type="number" class="form-control" id="tr-set-window" min="1" max="3650" data-tr-field="component_window_days" data-tr-type="int" data-tr-required="1"<?= $tr_ro ?>>
                    <div class="tr-field-error" data-tr-error="component_window_days"></div>
                </div>
                <div class="col-sm-8">
                    <div class="tr-switch-row pt-4">
                        <div>
                            <label class="tr-switch-row__label" for="tr-set-attest">Trainer can sign off parts</label>
                            <div class="tr-switch-row__hint">A trainer may record a session or practical on someone's behalf.</div>
                        </div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tr-set-attest" data-tr-field="allow_trainer_attest" data-tr-type="bool"<?= $tr_ro ?>></div>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="tr-set-checklist">Practical evaluation checklist</label>
                    <textarea class="form-control" id="tr-set-checklist" rows="4" maxlength="10000" placeholder="One item per line. Start a line with * when missing it fails the evaluation." data-tr-field="eval_checklist" data-tr-type="text"<?= $tr_ro ?>></textarea>
                    <div class="tr-field-error" data-tr-error="eval_checklist"></div>
                </div>
            </div>
        </div>
    </details>

    <?php if ($tr_can_full) { ?>
    <section class="card tr-danger" aria-labelledby="tr-set-danger">
        <div class="card-header"><h2 class="card-title text-danger" id="tr-set-danger">Danger zone</h2></div>
        <div class="card-body py-1">
            <?php if ($tr_archived) { ?>
            <div class="tr-danger__row">
                <div><div class="fw-semibold">Restore this course</div><div class="text-muted small">It becomes editable again and returns to the course list.</div></div>
                <button type="button" class="btn btn-outline-secondary" data-tr-act="restore">Restore</button>
            </div>
            <?php } else { ?>
            <div class="tr-danger__row">
                <div><div class="fw-semibold">Archive this course</div><div class="text-muted small">It leaves the list and becomes read-only. Published versions, media and records are kept.</div></div>
                <button type="button" class="btn btn-outline-danger" data-tr-act="archive">Archive…</button>
            </div>
            <?php } ?>
            <?php if (empty($tr_course['current_revision'])) { ?>
            <div class="tr-danger__row">
                <div><div class="fw-semibold">Delete this draft</div><div class="text-muted small">Only for courses that were never published. Removes every section, lesson and question in it.</div></div>
                <button type="button" class="btn btn-outline-danger" data-tr-act="delete">Delete draft…</button>
            </div>
            <?php } ?>
            <div id="tr-danger-confirm"></div>
        </div>
    </section>
    <?php } ?>
</div>
