<?php

/*
 * Training › Assignments › Rule editor (Phase 2 spec §5.2, mockup Admin-AssignmentRule).
 *
 * Level 3 creates and edits requirement rules; levels 1 and 2 see a rule read-only. The page
 * frame is server-rendered; agent/js/training_rule.js builds the condition rows, the course
 * card and the live preview (rule_preview, debounced 400 ms) with DOM nodes only, and saves
 * through rule_save with a request_uid generated on page load. Nothing is assigned until Save.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_ops.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
require_once __DIR__ . '/includes/training_ops/ops.php';

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_can_edit = lookupUserPermission('module_training') >= 3;
$tr_id = tro_get_id('id');
$tr_scope = tro_scope($mysqli, $tr_ctx);

$tr_rule = $tr_id !== null ? tro_action($mysqli, 'rule_get', ['requirement_id' => $tr_id]) : null;
$tr_rule_name = ($tr_rule !== null && $tr_rule['state'] === 'ok' && is_array($tr_rule['data'])) ? (string) ($tr_rule['data']['name'] ?? '') : '';

$tr_data = [
    'level' => $tr_ctx->level,
    'user_id' => $tr_ctx->userId,
    'today' => tro_today(),
    'can_edit' => $tr_can_edit,
    'rule_id' => $tr_id,
    'rule' => $tr_rule,
    'scope' => $tr_scope['state'],
    'courses' => tro_course_cards($mysqli),
    // The editor matches over everyone (preview is level 3, all scope); readers only see labels.
    'departments' => $tr_can_edit ? tro_departments($mysqli, ['state' => 'all', 'client_ids' => []]) : tro_departments($mysqli, $tr_scope),
    'attr_options' => tro_action($mysqli, 'odoo_attr_options'),
    'jobgroups' => tro_action($mysqli, 'jobgroup_list'),
    'settings' => tro_records_settings($mysqli),
    'routes' => ['rule_preview' => tro_has_route('rule_preview'), 'rule_save' => tro_has_route('rule_save'), 'rule_archive' => tro_has_route('rule_archive')],
];

$tr_title = $tr_id === null ? 'New assignment rule' : ($tr_rule_name !== '' ? $tr_rule_name : 'Assignment rule');
$tr_actions = '';
if ($tr_id === null) {
    $tr_actions = '<span class="tro-draft" id="tro-rule-draft"><span class="tro-chip tro-chip--outline">Draft</span>Nothing is assigned until you save.</span>';
} elseif ($tr_can_edit) {
    $tr_actions = '<button type="button" class="btn btn-outline-danger" id="tro-rule-archive" hidden><i class="fas fa-archive me-2" aria-hidden="true"></i>Archive…</button>';
}
?>

<div class="tro-page tro-rule-page" id="tro-rule-page">
    <?php render_page_header($tr_title, null, $tr_actions, [
        ['label' => 'Training', 'url' => '/agent/training.php'],
        ['label' => 'Assignments', 'url' => '/agent/training_assignments.php?tab=rules'],
        ['label' => $tr_id === null ? 'New rule' : 'Rule'],
    ]); ?>

    <div id="tro-rule-alerts"></div>

    <div class="tro-rule" id="tro-rule">
        <form class="tro-card tro-rule__main" id="tro-rule-form" novalidate autocomplete="off">
            <div class="tro-rule__section">
                <label class="form-label fw-semibold" for="tro-rule-name">Rule name</label>
                <input type="text" class="form-control tro-name-input" id="tro-rule-name" maxlength="150" placeholder="Named from the course and who it is for">
                <div class="tro-auto-name" id="tro-rule-name-hint">Filled in for you from the course and who it applies to. Type your own name any time.</div>
                <div class="invalid-feedback" data-field="name"></div>
            </div>

            <section class="tro-rule__section" aria-labelledby="tro-step-what">
                <div class="tro-step">
                    <span class="tro-step__n" aria-hidden="true">1</span>
                    <h2 class="tro-step__title" id="tro-step-what">What</h2>
                    <span class="tro-step__hint">The course or document people must complete</span>
                </div>
                <div id="tro-rule-course"></div>
                <div class="invalid-feedback d-block" data-field="course_id"></div>
            </section>

            <section class="tro-rule__section" aria-labelledby="tro-step-who">
                <div class="tro-step">
                    <span class="tro-step__n" aria-hidden="true">2</span>
                    <h2 class="tro-step__title" id="tro-step-who">Who</h2>
                    <span class="tro-step__hint">People who match <strong>all</strong> of these</span>
                </div>
                <div class="tro-conds" id="tro-rule-conds">
                    <div id="tro-rule-rows"></div>
                    <div class="tro-conds__foot" id="tro-rule-conds-foot">
                        <div class="dropdown">
                            <button type="button" class="btn tro-add-cond" id="tro-rule-add" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-plus me-2" aria-hidden="true"></i>Add condition
                            </button>
                            <ul class="dropdown-menu" id="tro-rule-add-menu" aria-labelledby="tro-rule-add"></ul>
                        </div>
                        <span class="tro-hint" id="tro-rule-conds-hint"></span>
                    </div>
                    <div class="tro-switch-row" id="tro-rule-newhires-row">
                        <div class="tro-switch-row__text">
                            <label class="tro-switch-row__title" for="tro-rule-newhires">New hires only</label>
                            <div class="tro-switch-row__hint" id="tro-rule-newhires-hint">Uses the employee's hire date; set it on the transcript, or turn on hire-date fill in Admin › Training compliance.</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="tro-rule-newhires" aria-describedby="tro-rule-newhires-hint">
                        </div>
                    </div>
                </div>
                <div class="invalid-feedback d-block" data-field="criteria"></div>
            </section>

            <section class="tro-rule__section" aria-labelledby="tro-step-when">
                <div class="tro-step">
                    <span class="tro-step__n" aria-hidden="true">3</span>
                    <h2 class="tro-step__title" id="tro-step-when">When</h2>
                    <span class="tro-step__hint">Due dates and renewal</span>
                </div>
                <div class="tro-when">
                    <div class="tro-when__row" id="tro-rule-staff-row">
                        <div class="tro-when__label">
                            <div class="tro-when__title">Current staff</div>
                            <div class="tro-when__hint" id="tro-rule-staff-hint">People already on file when you save</div>
                        </div>
                        <div class="tro-when__ctl">
                            <label for="tro-rule-due-days">Due within</label>
                            <div class="input-group tro-days">
                                <input type="number" class="form-control" id="tro-rule-due-days" min="0" max="365" step="1" inputmode="numeric" value="30">
                                <span class="input-group-text">days</span>
                            </div>
                            <i class="fas fa-arrow-right text-muted" aria-hidden="true"></i>
                            <label class="visually-hidden" for="tro-rule-due-date">Due date for current staff</label>
                            <input type="date" class="form-control tro-date-chip" id="tro-rule-due-date">
                        </div>
                        <div class="invalid-feedback d-block w-100" data-field="due_days"></div>
                        <div class="invalid-feedback d-block w-100" data-field="baseline_due_on"></div>
                    </div>
                    <div class="tro-when__row">
                        <div class="tro-when__label">
                            <div class="tro-when__title">New hires</div>
                            <div class="tro-when__hint" id="tro-rule-hire-hint">People whose hire date is on or after the day you save</div>
                        </div>
                        <div class="tro-when__ctl">
                            <label for="tro-rule-hire-days">Due</label>
                            <div class="input-group tro-days">
                                <input type="number" class="form-control" id="tro-rule-hire-days" min="0" max="365" step="1" inputmode="numeric" value="7">
                                <span class="input-group-text">days</span>
                            </div>
                            <span>after hire date</span>
                        </div>
                        <div class="invalid-feedback d-block w-100" data-field="due_days_from_hire"></div>
                    </div>
                    <div class="tro-when__row tro-when__row--muted">
                        <div class="tro-when__label">
                            <div class="tro-when__title">Renewal</div>
                            <div class="tro-when__hint">Set on the course, not on the rule</div>
                        </div>
                        <div class="tro-when__ctl" id="tro-rule-renewal"><span class="text-muted">Choose a course first</span></div>
                    </div>
                    <div class="tro-when__row">
                        <div class="tro-when__label">
                            <label class="tro-when__title" for="tro-rule-required">Required</label>
                            <div class="tro-when__hint" id="tro-rule-required-hint">Counts toward compliance, and shows on the kiosk as a must-do</div>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="tro-rule-required" checked aria-describedby="tro-rule-required-hint">
                        </div>
                    </div>
                    <div class="tro-when__row">
                        <div class="tro-when__label">
                            <label class="tro-when__title" for="tro-rule-onetime">Assign once only</label>
                            <div class="tro-when__hint" id="tro-rule-onetime-hint">Do not assign a renewal when the certificate expires</div>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="tro-rule-onetime" aria-describedby="tro-rule-onetime-hint">
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label" for="tro-rule-note">Note <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea class="form-control" id="tro-rule-note" rows="2" maxlength="500" placeholder="For example: required by our insurer after the March audit"></textarea>
                    <div class="invalid-feedback" data-field="note"></div>
                </div>
            </section>

            <div class="tro-savebar" id="tro-rule-savebar" hidden>
                <span class="tro-savebar__msg" id="tro-rule-savemsg" aria-live="polite"></span>
                <a class="btn btn-outline-secondary" href="/agent/training_assignments.php?tab=rules" id="tro-rule-cancel">Cancel</a>
                <button type="submit" class="btn btn-primary" id="tro-rule-save"><i class="fas fa-check me-2" aria-hidden="true"></i>Save rule</button>
            </div>
        </form>

        <aside class="tro-card tro-preview" id="tro-rule-preview" aria-label="Preview of who this rule applies to"></aside>
    </div>
</div>

<?php
tro_page_data($tr_data);
tro_scripts('/agent/js/training_rule.js');
require_once "../includes/footer.php";
