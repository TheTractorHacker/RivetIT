<?php
use ITFlow\Automation\Actions\ActionRegistry;
use ITFlow\Automation\ConditionEvaluator;
use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleForm;
use ITFlow\Automation\RuleRecipes;

/* The Event rules editor (included by admin/event_rules.php). Provides the same variables as the list, plus $edit (the saved rule or null). */

// ---- the state the editor starts from: a failed save (kept input), a saved rule, a recipe or a blank rule
$draft = null;
if (isset($_GET['draft']) && !empty($_SESSION['event_rule_draft'])) {
    $d = $_SESSION['event_rule_draft'];
    unset($_SESSION['event_rule_draft']);
    if ((int) ($d['rule_id'] ?? 0) === (int) ($edit['rule_id'] ?? 0)) {
        $draft = $d;
    }
}
$recipe = null;
if (!$edit && isset($_GET['recipe'])) {
    foreach ($recipes as $rc) {
        if ($rc['key'] === $_GET['recipe']) {
            $recipe = $rc;
        }
    }
}
$stored_invalid = false;
if ($draft) {
    $pf = RuleForm::parse($draft['post']);
    $state = ['rule_id' => (int) ($edit['rule_id'] ?? 0), 'name' => $pf['name'], 'trigger' => $pf['trigger'], 'mode' => in_array($pf['mode'], ['all', 'any'], true) ? $pf['mode'] : 'all',
        'rows' => array_values(array_filter($pf['rows'], static fn ($r) => $r['field'] !== '')), 'groupModes' => $pf['groupModes'], 'action' => $pf['action'], 'form' => $draft['post'],
        'enabled' => $pf['enabled'], 'priority' => $pf['priority'], 'stop' => $pf['stop'], 'rate' => $pf['rate']];
} elseif ($edit) {
    $cfg = json_decode((string) $edit['action_config_json'], true);
    $model = ConditionEvaluator::normalize($edit['condition_json'] ?? null);
    $stored_invalid = $model === null;
    $rows = RuleForm::modelToRows($model ?? ['mode' => 'all', 'conditions' => []]);
    $state = ['rule_id' => (int) $edit['rule_id'], 'name' => $edit['name'], 'trigger' => $edit['trigger_event'], 'mode' => $rows['mode'], 'rows' => $rows['rows'], 'groupModes' => $rows['groupModes'],
        'action' => $edit['action_type'], 'form' => RuleForm::configToForm($edit['action_type'], is_array($cfg) ? $cfg : []), 'enabled' => (bool) $edit['is_enabled'],
        'priority' => (int) ($edit['priority'] ?? 100), 'stop' => !empty($edit['stop_on_match']), 'rate' => (int) ($edit['rate_limit_per_min'] ?? RuleEngine::DEFAULT_RATE)];
} elseif ($recipe) {
    $state = RuleRecipes::toState($recipe);
} else {
    $state = ['rule_id' => 0, 'name' => '', 'trigger' => '', 'mode' => 'all', 'rows' => [], 'groupModes' => [], 'action' => '', 'form' => [], 'enabled' => true, 'priority' => 100, 'stop' => false, 'rate' => RuleEngine::DEFAULT_RATE];
}
$fv = $state['form'];
$val = static fn (string $k, $d = '') => is_scalar($fv[$k] ?? $d) ? (string) ($fv[$k] ?? $d) : '';
$sel = static fn (string $k, $v, $d = ''): string => $val($k, $d) === (string) $v ? 'selected' : '';
$pool = array_map('intval', is_array($fv['cfg_as_pool'] ?? null) ? $fv['cfg_as_pool'] : []);
$section_names = ['name' => 'Settings: name', 'trigger' => 'When', 'conditions' => 'If', 'action' => 'Then', 'settings' => 'Settings', 'form' => 'Rule'];
$er_data['state'] = $state;
$er_data['touched'] = $draft !== null;
$er_data['recipe'] = $recipe['key'] ?? null;
$er_data['ticketActions'] = ActionRegistry::TICKET_ACTIONS;
$title = $state['rule_id'] ? 'Edit rule' : ($recipe ? 'New rule from a recipe' : 'New rule');
?>

<div class="er-head er-head-editor">
    <div>
        <h3 class="er-title"><i class="fas fa-fw fa-bolt me-2" aria-hidden="true"></i><?= $h($title) ?><?= $state['rule_id'] ? ': <span class="er-title-name">' . $h($state['name']) . '</span>' : '' ?></h3>
        <?php if ($recipe) { ?><p class="er-sub"><i class="fas <?= $h($recipe['icon']) ?> me-1" aria-hidden="true"></i><strong><?= $h($recipe['title']) ?></strong>: <?= $h($recipe['blurb']) ?> Nothing is saved until you press Save.</p><?php } ?>
    </div>
</div>

<?php if ($stored_invalid) { ?>
<div class="alert alert-warning">The conditions stored for this rule are not valid, so the rule never fires. Set them again below; saving replaces them.</div>
<?php } ?>
<?php if ($draft) { ?>
<div class="alert alert-danger" role="alert" id="er-error-summary" tabindex="-1">
    <strong>The rule was not saved.</strong> Your input is still here; fix the following and save again.
    <ul class="mb-0 mt-1">
        <?php foreach ($draft['errors'] as $sec => $msg) { ?><li><strong><?= $h($section_names[$sec] ?? $sec) ?>:</strong> <?= $h($msg) ?></li><?php } ?>
    </ul>
</div>
<?php } ?>

<form action="post.php" method="post" autocomplete="off" id="ruleForm" class="er-editor" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <?php if ($state['rule_id']) { ?><input type="hidden" name="rule_id" value="<?= (int) $state['rule_id'] ?>"><?php } ?>

    <div class="er-editor-grid">
    <div class="er-cards">

        <!-- 1. When -->
        <section class="er-card" id="er-sec-trigger" aria-labelledby="er-h-when">
            <h4 class="er-card-h" id="er-h-when"><span class="er-step">1</span>When this happens</h4>
            <div class="er-card-b">
                <div class="er-event-info" id="er-event-info" aria-live="polite">
                    <p class="text-muted small mb-0" data-er-empty>Choose an event below. You will see what it means and which fields of the event you can use in conditions and messages.</p>
                </div>
                <label class="visually-hidden" id="ruleTriggerLabel">When this happens</label>
                <?php eventPickerField('trigger_event', [(string) $state['trigger']], ['mode' => 'single', 'id' => 'rule-trigger-picker']); ?>
                <div class="er-problem" data-section="trigger" role="alert" hidden></div>
            </div>
        </section>

        <!-- 2. If -->
        <section class="er-card" id="er-sec-conditions" aria-labelledby="er-h-if">
            <h4 class="er-card-h" id="er-h-if"><span class="er-step">2</span>Only if <span class="er-optional">(optional)</span></h4>
            <div class="er-card-b">
                <div id="er-builder" class="er-builder"></div>
                <div id="er-cond-inputs" hidden></div>
                <div class="er-problem" data-section="conditions" role="alert" hidden></div>
                <div class="er-builder-tools">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="er-check"><i class="fas fa-search-plus me-1" aria-hidden="true"></i>Check against recent events</button>
                    <span class="small text-muted">Read only: shows which of the 10 most recent events these conditions match. Nothing runs.</span>
                </div>
                <div id="er-check-out" class="er-check-out" hidden aria-live="polite"></div>
            </div>
        </section>

        <!-- 3. Then -->
        <section class="er-card" id="er-sec-action" aria-labelledby="er-h-then">
            <h4 class="er-card-h" id="er-h-then"><span class="er-step">3</span>Then do this</h4>
            <div class="er-card-b">
                <div class="er-action-search"><i class="fas fa-search" aria-hidden="true"></i><input type="search" class="form-control form-control-sm" id="er-action-filter" placeholder="Search actions" aria-label="Search actions" autocomplete="off"></div>
                <div class="er-actions" role="radiogroup" aria-label="What should the rule do?" id="er-actions">
                    <?php foreach ($rule_actions as $k => $label) {
                        $am = $action_meta[$k] ?? ['fa-bolt', '']; ?>
                    <label class="er-action-card <?= $state['action'] === $k ? 'is-selected' : '' ?>" data-action="<?= $h($k) ?>" data-search="<?= $h(mb_strtolower($label . ' ' . $am[1])) ?>">
                        <input type="radio" class="visually-hidden" name="action_type" value="<?= $h($k) ?>" <?= $state['action'] === $k ? 'checked' : '' ?>>
                        <span class="er-action-icon"><i class="fas <?= $h($am[0]) ?>" aria-hidden="true"></i></span>
                        <span class="er-action-text"><span class="er-action-name"><?= $h($label) ?></span><span class="er-action-desc"><?= $h($am[1]) ?></span></span>
                    </label>
                    <?php } ?>
                </div>
                <p class="small text-muted mb-0 er-ticket-note" hidden><i class="fas fa-info-circle me-1" aria-hidden="true"></i>This action works on a ticket, so it needs an event that is about a ticket (for example <code>ticket.created</code>).</p>
                <div class="er-problem" data-section="action" role="alert" hidden></div>

                <div class="er-act-boxes">
                    <div class="er-act-box" data-for="create_ticket">
                        <div class="mb-2"><label class="form-label" for="cfg_subject">Subject</label><input class="form-control" id="cfg_subject" name="cfg_subject" maxlength="500" value="<?= $h($val('cfg_subject')) ?>" data-er-template="text" placeholder="e.g. Workflow step failed: {summary}"></div>
                        <div class="mb-2"><label class="form-label" for="cfg_details">Details</label><textarea class="form-control" id="cfg_details" name="cfg_details" rows="3" maxlength="5000" data-er-template="text"><?= $h($val('cfg_details')) ?></textarea></div>
                        <div><label class="form-label" for="cfg_priority">Priority</label><select class="form-select" id="cfg_priority" name="cfg_priority"><?php foreach (['Low', 'Medium', 'High'] as $pr) { ?><option <?= $sel('cfg_priority', $pr, 'Low') ?>><?= $pr ?></option><?php } ?></select></div>
                    </div>
                    <div class="er-act-box" data-for="send_webhook">
                        <div class="mb-2"><label class="form-label" for="cfg_url">Webhook URL</label><input class="form-control" id="cfg_url" name="cfg_url" maxlength="500" placeholder="https://hooks.example.com/..." value="<?= $h($val('cfg_url')) ?>" inputmode="url">
                            <div class="form-text">An http(s) address (<?= $h(rivetWebhookRuleText($mysqli)) ?>). To reach a server on your own network, add it under <a href="settings_webhooks.php">Webhooks &rarr; Internal network access</a>. The event is sent as JSON; the URL itself cannot contain placeholders.</div></div>
                        <div><label class="form-label" for="cfg_secret">Signing secret <span class="text-muted">(optional)</span></label><input class="form-control" id="cfg_secret" name="cfg_secret" maxlength="200" value="<?= $h($val('cfg_secret')) ?>" autocomplete="off"><div class="form-text">When set, the request carries an HMAC signature the receiver can verify.</div></div>
                    </div>
                    <div class="er-act-box" data-for="notify_user">
                        <label class="form-label" for="cfg_message">Message</label><input class="form-control" id="cfg_message" name="cfg_message" maxlength="1000" value="<?= $h($val('cfg_message')) ?>" data-er-template="text" placeholder="e.g. Urgent ticket {ticket_number}: {ticket_subject}">
                        <div class="form-text">Every technician gets this as a notification.</div>
                    </div>
                    <div class="er-act-box" data-for="start_workflow">
                        <label class="form-label" for="cfg_template_id">Employee workflow template</label>
                        <select class="form-select" id="cfg_template_id" name="cfg_template_id"><option value="0">-</option>
                            <?php foreach ($workflow_templates as $wt) { ?><option value="<?= (int) $wt['workflow_template_id'] ?>" <?= $sel('cfg_template_id', $wt['workflow_template_id']) ?>>[<?= $h(ucfirst($wt['type'])) ?>] <?= $h($wt['name']) ?></option><?php } ?>
                        </select>
                        <div class="form-text">Starts for the person the event is about, once per person while one is open. Works best with <code>employee.hired</code> / <code>employee.terminated</code>, and needs auto-start turned on under <a href="employee_workflow_templates.php">Employee workflows</a>.</div>
                    </div>
                    <div class="er-act-box" data-for="set_ticket_field">
                        <p class="text-muted small">Fill in only the fields to change; the rest stay as they are.</p>
                        <div class="row g-2">
                            <div class="col-md-6"><label class="form-label" for="cfg_sf_status">Status</label><select class="form-select" id="cfg_sf_status" name="cfg_sf_status"><option value="">(leave as is)</option><?php foreach ($status_names as $sn) { ?><option <?= $sel('cfg_sf_status', $sn) ?>><?= $h($sn) ?></option><?php } ?></select></div>
                            <div class="col-md-6"><label class="form-label" for="cfg_sf_priority">Priority</label><select class="form-select" id="cfg_sf_priority" name="cfg_sf_priority"><option value="">(leave as is)</option><?php foreach (['Low', 'Medium', 'High'] as $pr) { ?><option <?= $sel('cfg_sf_priority', $pr) ?>><?= $pr ?></option><?php } ?></select></div>
                            <div class="col-md-6"><label class="form-label" for="cfg_sf_category">Category</label><select class="form-select" id="cfg_sf_category" name="cfg_sf_category"><option value="0">(leave as is)</option><?php foreach ($categories as $cid => $cn) { ?><option value="<?= (int) $cid ?>" <?= $sel('cfg_sf_category', $cid) ?>><?= $h($cn) ?></option><?php } ?></select></div>
                            <div class="col-md-6"><label class="form-label" for="cfg_sf_assignee">Assignee</label><select class="form-select" id="cfg_sf_assignee" name="cfg_sf_assignee"><option value="">(leave as is)</option><option value="none" <?= $sel('cfg_sf_assignee', 'none') ?>>Unassigned</option><?php foreach ($agents as $uid => $un) { ?><option value="<?= (int) $uid ?>" <?= $sel('cfg_sf_assignee', $uid) ?>><?= $h($un) ?></option><?php } ?></select></div>
                        </div>
                    </div>
                    <div class="er-act-box" data-for="add_ticket_note">
                        <label class="form-label" for="cfg_note">Internal note <span class="text-muted">(staff only; never shown to the requester)</span></label>
                        <textarea class="form-control" id="cfg_note" name="cfg_note" rows="4" maxlength="2000" data-er-template="html"><?= $h($val('cfg_note')) ?></textarea>
                    </div>
                    <div class="er-act-box" data-for="assign_ticket">
                        <label class="form-label" for="cfg_as_mode">Assign to</label>
                        <select class="form-select mb-2" id="cfg_as_mode" name="cfg_as_mode"><option value="user" <?= $sel('cfg_as_mode', 'user', 'user') ?>>One technician</option><option value="round_robin" <?= $sel('cfg_as_mode', 'round_robin') ?>>Rotate through several technicians (round robin)</option></select>
                        <div data-as="user"><label class="form-label" for="cfg_as_user">Technician</label>
                            <select class="form-select" id="cfg_as_user" name="cfg_as_user"><option value="0">-</option><?php foreach ($agents as $uid => $un) { ?><option value="<?= (int) $uid ?>" <?= $sel('cfg_as_user', $uid) ?>><?= $h($un) ?></option><?php } ?></select></div>
                        <fieldset data-as="round_robin"><legend class="form-label">Rotation <span class="text-muted">(tick two or more; each new ticket goes to the next one)</span></legend>
                            <div class="er-checks"><?php foreach ($agents as $uid => $un) { ?><label class="form-check"><input class="form-check-input" type="checkbox" name="cfg_as_pool[]" value="<?= (int) $uid ?>" <?= in_array($uid, $pool, true) ? 'checked' : '' ?>><span class="form-check-label"><?= $h($un) ?></span></label><?php } ?></div></fieldset>
                    </div>
                    <div class="er-act-box" data-for="send_mail">
                        <label class="form-label" for="cfg_mail_to">Send to</label>
                        <select class="form-select mb-2" id="cfg_mail_to" name="cfg_mail_to"><?php foreach (\ITFlow\Automation\Actions\SendMailAction::TO as $k => $l) { ?><option value="<?= $h($k) ?>" <?= $sel('cfg_mail_to', $k, 'assignee') ?>><?= $h(ucfirst($l)) ?></option><?php } ?></select>
                        <div class="mb-2" data-mail="address"><label class="form-label" for="cfg_mail_address">Address</label><input class="form-control" type="email" id="cfg_mail_address" name="cfg_mail_address" maxlength="200" value="<?= $h($val('cfg_mail_address')) ?>"></div>
                        <div class="mb-2" data-mail="user"><label class="form-label" for="cfg_mail_user">Technician</label><select class="form-select" id="cfg_mail_user" name="cfg_mail_user"><option value="0">-</option><?php foreach ($agents as $uid => $un) { ?><option value="<?= (int) $uid ?>" <?= $sel('cfg_mail_user', $uid) ?>><?= $h($un) ?></option><?php } ?></select></div>
                        <div class="mb-2"><label class="form-label" for="cfg_mail_subject">Subject</label><input class="form-control" id="cfg_mail_subject" name="cfg_mail_subject" maxlength="200" value="<?= $h($val('cfg_mail_subject')) ?>" data-er-template="text" placeholder="e.g. SLA warning: {ticket_number}"></div>
                        <div><label class="form-label" for="cfg_mail_body">Message</label><textarea class="form-control" id="cfg_mail_body" name="cfg_mail_body" rows="5" maxlength="5000" data-er-template="html"><?= $h($val('cfg_mail_body')) ?></textarea>
                            <div class="form-text">The recipient is fixed when you save the rule; an event value can never choose who gets the email.</div></div>
                    </div>
                    <div class="er-act-box" data-for="create_task">
                        <div class="mb-2"><label class="form-label" for="cfg_task_name">Task name</label><input class="form-control" id="cfg_task_name" name="cfg_task_name" maxlength="255" value="<?= $h($val('cfg_task_name')) ?>" data-er-template="text" placeholder="e.g. Confirm backups for {client_name}"></div>
                        <div class="row g-2"><div class="col-md-6"><label class="form-label" for="cfg_task_assignee">Assign task to</label><select class="form-select" id="cfg_task_assignee" name="cfg_task_assignee"><option value="0">(nobody)</option><?php foreach ($agents as $uid => $un) { ?><option value="<?= (int) $uid ?>" <?= $sel('cfg_task_assignee', $uid) ?>><?= $h($un) ?></option><?php } ?></select></div>
                            <div class="col-md-6"><label class="form-label" for="cfg_task_due_days">Due in (days, 0 = no date)</label><input class="form-control" type="number" id="cfg_task_due_days" name="cfg_task_due_days" min="0" max="365" value="<?= (int) $val('cfg_task_due_days', 0) ?>"></div></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 4. Settings -->
        <section class="er-card" id="er-sec-settings" aria-labelledby="er-h-settings">
            <h4 class="er-card-h" id="er-h-settings"><span class="er-step"><i class="fas fa-cog" aria-hidden="true"></i></span>Settings</h4>
            <div class="er-card-b">
                <div class="mb-3">
                    <label class="form-label" for="rule_name">Rule name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" id="rule_name" name="rule_name" maxlength="200" required aria-required="true" value="<?= $h($state['name']) ?>" placeholder="e.g. Alert the team when a High ticket arrives">
                    <div class="er-problem" data-section="name" role="alert" hidden></div>
                    <div class="form-text"><button type="button" class="btn btn-link btn-sm p-0" id="er-suggest-name" hidden>Use the suggestion: <span></span></button></div>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="rule_enabled" <?= $state['enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="rule_enabled">Rule is on</label>
                    <div class="form-text">A rule that is off never runs. You can still test it.</div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="rule_priority">Order (priority)</label>
                        <input class="form-control" type="number" id="rule_priority" name="rule_priority" min="1" max="9999" value="<?= (int) $state['priority'] ?>">
                        <div class="form-text">When several rules listen for the same event, the lower number runs first. You can also drag rules on the list to reorder them.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="rate_limit">Most runs per minute</label>
                        <input class="form-control" type="number" id="rate_limit" name="rate_limit" min="1" max="1000" value="<?= (int) $state['rate'] ?>">
                        <div class="form-text">A safety limit: above this the rule is throttled until the minute is over, so a flood of events cannot flood your people or another service. Throttled runs show in the history.</div>
                    </div>
                </div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="stop_on_match" value="1" id="stop_on_match" <?= $state['stop'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="stop_on_match">Stop on first match</label>
                    <div class="form-text">If this rule matches, later rules for the same event are skipped.</div>
                </div>
                <p class="small text-muted mt-3 mb-0"><i class="fas fa-shield-alt me-1" aria-hidden="true"></i>Loop guard (always on): a rule never runs twice in the same chain of events, and a chain is at most <?= (int) RuleEngine::MAX_DEPTH ?> rules long, so a rule can never trigger itself, directly or through other rules.</p>
                <div class="er-problem" data-section="settings" role="alert" hidden></div>
            </div>
        </section>
    </div>

    <aside class="er-side" aria-label="Rule summary">
        <div class="er-summary" id="er-summary">
            <h4 class="er-side-h">Rule summary</h4>
            <p class="er-sentence-big" id="er-sentence" role="status" aria-live="polite">Choose an event and an action to see what this rule does.</p>
            <ul class="er-checks-list" id="er-checks" aria-label="Validation">
                <li data-check="trigger"><i class="fas fa-circle" aria-hidden="true"></i><span><strong>When</strong> <em>choose an event</em></span></li>
                <li data-check="conditions"><i class="fas fa-circle" aria-hidden="true"></i><span><strong>If</strong> <em>every event (no conditions)</em></span></li>
                <li data-check="action"><i class="fas fa-circle" aria-hidden="true"></i><span><strong>Then</strong> <em>choose an action</em></span></li>
                <li data-check="name"><i class="fas fa-circle" aria-hidden="true"></i><span><strong>Name</strong> <em>give the rule a name</em></span></li>
                <li data-check="settings"><i class="fas fa-circle" aria-hidden="true"></i><span><strong>Settings</strong> <em>ok</em></span></li>
            </ul>
            <div class="er-side-buttons">
                <button type="submit" name="save_event_rule" value="1" class="btn btn-primary"><i class="fas fa-check me-1" aria-hidden="true"></i>Save rule</button>
                <button type="submit" name="save_event_rule" value="test" class="btn btn-outline-primary"><i class="fas fa-vial me-1" aria-hidden="true"></i>Save and test</button>
                <button type="button" class="btn btn-outline-secondary" id="er-test-form"><i class="fas fa-play me-1" aria-hidden="true"></i>Test without saving</button>
                <a class="btn btn-link" href="event_rules.php">Cancel</a>
            </div>
        </div>
    </aside>
    </div>
</form>

<?php if ($state['rule_id'] === 0) { ?>
<details class="er-recipes-wrap" <?= $recipe ? '' : 'open' ?>>
    <summary><i class="fas fa-magic me-1" aria-hidden="true"></i>Start from a recipe instead</summary>
    <?php require __DIR__ . '/event_rules_recipes.php'; ?>
</details>
<?php } ?>
