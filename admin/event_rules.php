<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/event_bus.php";
require_once "includes/webhook_events.php";

use ITFlow\Automation\Actions\ActionRegistry;
use ITFlow\Automation\ConditionEvaluator;
use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleView;

$csrf = $_SESSION['csrf_token'];
$ready = class_exists(RuleEngine::class) && rivetTableExists($mysqli, 'automation_rules');
$engine = $ready ? new RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli)) : null;
$has_engine_columns = $ready && mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM automation_rules LIKE 'rate_limit_per_min'")) > 0;
$rules = [];
if ($ready) {
    $res = mysqli_query($mysqli, 'SELECT * FROM automation_rules ORDER BY trigger_event, ' . ($has_engine_columns ? 'priority, ' : '') . 'rule_id');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rules[] = $r;
    }
}
$edit = null;
if ($ready && isset($_GET['edit'])) {
    $edit = $engine->find(intval($_GET['edit']));
}
$edit_config = $edit ? (json_decode((string) $edit['action_config_json'], true) ?: []) : [];
$cond_model = ConditionEvaluator::normalize($edit['condition_json'] ?? null) ?? ['mode' => 'all', 'conditions' => []];
// Flatten the model into builder rows: [field, op, value, group letter ('' = top level)]
$cond_rows = [];
$group_modes = ['A' => 'any', 'B' => 'any', 'C' => 'any'];
$gi = 0;
foreach ($cond_model['conditions'] as $c) {
    if (isset($c['conditions'])) {
        $letter = ['A', 'B', 'C'][min($gi++, 2)];
        $group_modes[$letter] = $c['mode'];
        foreach ($c['conditions'] as $leaf) {
            $cond_rows[] = [$leaf['field'], $leaf['op'], $leaf['value'], $letter];
        }
    } else {
        $cond_rows[] = [$c['field'], $c['op'], $c['value'], ''];
    }
}
while (count($cond_rows) < 3) {
    $cond_rows[] = ['', 'eq', '', ''];
}

$last_run = [];
if ($ready && rivetTableExists($mysqli, 'automation_rule_runs')) {
    $res = mysqli_query($mysqli, 'SELECT r.rule_id, r.status, r.created_at FROM automation_rule_runs r JOIN (SELECT rule_id, MAX(run_id) AS m FROM automation_rule_runs GROUP BY rule_id) x ON x.m = r.run_id');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $last_run[(int) $r['rule_id']] = $r;
    }
}
$agents = [];
$res = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $agents[(int) $r['user_id']] = $r['user_name'];
}
$status_names = [];
$res = mysqli_query($mysqli, "SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order, ticket_status_id");
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $status_names[] = $r['ticket_status_name'];
}
$categories = [];
$res = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name");
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $categories[(int) $r['category_id']] = $r['category_name'];
}
$rule_actions = ActionRegistry::labels();
$workflow_templates = [];
$res = mysqli_query($mysqli, "SELECT workflow_template_id, name, type FROM workflow_templates WHERE archived_at IS NULL AND is_active = 1 ORDER BY type, name");
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $workflow_templates[] = $r;
}
$field_hints = ['event', 'entity_type', 'entity_id', 'action', 'summary', 'actor_user_id', 'ticket_id', 'ticket_number', 'ticket_subject', 'ticket_priority', 'ticket_status', 'client_id', 'client_name', 'contact_id', 'contact_name', 'contact_email', 'assigned_to_user_id', 'assigned_to_user_name', 'changed', 'sla_clock', 'sla_percent_used', 'asset_id', 'asset_name', 'asset_type', 'request_id', 'task_title', 'run_id'];
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-bolt me-2"></i>Event rules</h3>
        <a class="btn btn-sm btn-outline-secondary" href="automation.php"><i class="fas fa-cogs me-1"></i>Automation overview, run log &amp; on/off switch</a>
    </div>
    <div class="card-body">
        <p class="text-muted mb-1">When something happens in <?= $h(APP_NAME) ?> (a ticket is created or changes, an SLA is about to be missed, a request is approved, a login fails, a backup runs), a rule can create or change a ticket, add a note, assign it, send an email, notify someone or call another service. This is the same stream of events webhooks can subscribe to.</p>
        <p class="text-muted small mb-0">Rules run in the background through the job queue, so a slow or failing action never slows what a person is doing; failures are retried and shown on the <a href="job_queue.php">Job queue</a> page. Use <strong>Test</strong> on a rule to see exactly what it would do for a sample event, without changing or sending anything. Safety limits: a rule never re-triggers itself within one chain of events (at most <?= (int) RuleEngine::MAX_DEPTH ?> rules deep) and is throttled to its runs-per-minute limit.</p>
    </div>
</div>

<?php if (!$ready) { ?>
    <div class="alert alert-warning">Run the database update first (Administration &rarr; Update) to turn on event rules.</div>
<?php } else { ?>
<?php if (!$has_engine_columns) { ?>
    <div class="alert alert-warning">The automation engine tables are not installed yet. Run the database update (Administration &rarr; Update) to use conditions, new actions and the run log.</div>
<?php } ?>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><?= $edit ? 'Edit rule' : 'Add a rule' ?></h4></div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off" id="ruleForm">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <?php if ($edit) { ?><input type="hidden" name="rule_id" value="<?= (int) $edit['rule_id'] ?>"><?php } ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="rule_name" maxlength="200" required value="<?= $h($edit['name'] ?? '') ?>" placeholder="e.g. Alert the team when a High ticket arrives">
                </div>
                <div class="col-md-6">
                    <label class="form-label">When this happens</label>
                    <select class="form-select" name="trigger_event" required>
                        <option value="">Choose an event...</option>
                        <?php foreach (webhook_event_groups() as $group => $events) { ?>
                            <optgroup label="<?= $h($group) ?>">
                                <?php foreach ($events as $ev) { ?><option value="<?= $h($ev) ?>" <?= ($edit['trigger_event'] ?? '') === $ev ? 'selected' : '' ?>><?= $h($ev) ?></option><?php } ?>
                            </optgroup>
                        <?php } ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Only if <span class="text-muted small">(optional; compare a field of the event with a value; leave the rows empty to fire every time)</span></label>
                    <div class="mb-2">Match
                        <select class="form-select form-select-sm d-inline-block w-auto" style="min-width:4.5rem" name="cond_mode">
                            <option value="all" <?= $cond_model['mode'] === 'all' ? 'selected' : '' ?>>all</option>
                            <option value="any" <?= $cond_model['mode'] === 'any' ? 'selected' : '' ?>>any</option>
                        </select> of the rows below.
                        <span class="text-muted small ms-2">Rows given the same group letter (A, B or C) are combined together with
                            <?php foreach (['A', 'B', 'C'] as $gl) { ?>group <?= $gl ?>: <select class="form-select form-select-sm d-inline-block w-auto" style="min-width:4.5rem" name="group_mode[<?= $gl ?>]"><option value="any" <?= $group_modes[$gl] === 'any' ? 'selected' : '' ?>>any</option><option value="all" <?= $group_modes[$gl] === 'all' ? 'selected' : '' ?>>all</option></select> <?php } ?>
                            and then count as one row above.</span>
                    </div>
                    <datalist id="fieldHints"><?php foreach ($field_hints as $fh) { ?><option value="<?= $h($fh) ?>"><?php } ?></datalist>
                    <div id="condRows">
                    <?php foreach ($cond_rows as $cr) { ?>
                        <div class="row g-2 mb-1 cond-row">
                            <div class="col-md-3"><input class="form-control form-control-sm" name="cond_field[]" maxlength="100" list="fieldHints" placeholder="field, e.g. ticket_priority" value="<?= $h($cr[0]) ?>"></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" name="cond_op[]"><?php foreach (ConditionEvaluator::OPERATORS as $ok => $ol) { ?><option value="<?= $h($ok) ?>" <?= $cr[1] === $ok ? 'selected' : '' ?>><?= $h($ol) ?></option><?php } ?></select></div>
                            <div class="col-md-4"><input class="form-control form-control-sm" name="cond_value[]" maxlength="500" placeholder="value (one of: a, b, c)" value="<?= $h($cr[2]) ?>"></div>
                            <div class="col-md-1"><select class="form-select form-select-sm" name="cond_group[]" title="Group"><option value="">-</option><?php foreach (['A', 'B', 'C'] as $gl) { ?><option <?= $cr[3] === $gl ? 'selected' : '' ?>><?= $gl ?></option><?php } ?></select></div>
                            <div class="col-md-1"><button type="button" class="btn btn-sm btn-light cond-remove" title="Remove this row" aria-label="Remove this row"><i class="fas fa-times"></i></button></div>
                        </div>
                    <?php } ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-light mt-1" id="condAdd"><i class="fas fa-plus me-1"></i>Add a row</button>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Then</label>
                    <select class="form-select" name="action_type" id="actionType" required>
                        <?php foreach ($rule_actions as $k => $label) { ?><option value="<?= $h($k) ?>" <?= ($edit['action_type'] ?? 'notify_user') === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php } ?>
                    </select>
                    <div class="form-text">Ticket actions (<?= $h(implode(', ', array_map(static fn ($k) => $rule_actions[$k], ActionRegistry::TICKET_ACTIONS))) ?>) need a ticket event such as <code>ticket.created</code>.</div>
                    <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="rule_enabled" <?= !$edit || $edit['is_enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="rule_enabled">Rule is on</label></div>
                    <div class="mt-3">
                        <label class="form-label mb-0 small">Order (priority)</label>
                        <input class="form-control form-control-sm" type="number" name="rule_priority" min="1" max="9999" value="<?= (int) ($edit['priority'] ?? 100) ?>">
                        <div class="form-text">Lower numbers run first among rules for the same event.</div>
                        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="stop_on_match" value="1" id="stop_on_match" <?= !empty($edit['stop_on_match']) ? 'checked' : '' ?>><label class="form-check-label" for="stop_on_match">Stop on first match: if this rule matches, later rules for the event are skipped</label></div>
                        <label class="form-label mb-0 small mt-2">Max runs per minute</label>
                        <input class="form-control form-control-sm" type="number" name="rate_limit" min="1" max="1000" value="<?= (int) ($edit['rate_limit_per_min'] ?? RuleEngine::DEFAULT_RATE) ?>">
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="border rounded p-3 small">
                        <div class="fw-bold mb-2">Details for the chosen action <span class="text-muted fw-normal">(use <code>{field}</code> to insert a value from the event, e.g. <code>{ticket_number}</code>; values are escaped in emails and notes)</span></div>
                        <div class="row g-2">
                            <div class="col-12 act-box" data-for="create_ticket">
                                <label class="form-label mb-0">Subject</label><input class="form-control form-control-sm mb-2" name="cfg_subject" maxlength="500" value="<?= $h($edit_config['subject'] ?? '') ?>">
                                <label class="form-label mb-0">Details</label><textarea class="form-control form-control-sm mb-2" name="cfg_details" rows="2" maxlength="5000"><?= $h($edit_config['details'] ?? '') ?></textarea>
                                <label class="form-label mb-0">Priority</label><select class="form-select form-select-sm" name="cfg_priority"><?php foreach (['Low', 'Medium', 'High'] as $pr) { ?><option <?= ($edit_config['priority'] ?? 'Low') === $pr ? 'selected' : '' ?>><?= $pr ?></option><?php } ?></select>
                            </div>
                            <div class="col-12 act-box" data-for="send_webhook">
                                <label class="form-label mb-0">Webhook URL</label><input class="form-control form-control-sm mb-2" name="cfg_url" maxlength="500" placeholder="https://..." value="<?= $h($edit_config['url'] ?? '') ?>">
                                <label class="form-label mb-0">Signing secret (optional)</label><input class="form-control form-control-sm" name="cfg_secret" maxlength="200" value="<?= $h($edit_config['secret'] ?? '') ?>">
                            </div>
                            <div class="col-12 act-box" data-for="notify_user">
                                <label class="form-label mb-0">Message</label><input class="form-control form-control-sm" name="cfg_message" maxlength="1000" value="<?= $h($edit_config['message'] ?? '') ?>">
                            </div>
                            <div class="col-12 act-box" data-for="start_workflow">
                                <label class="form-label mb-0">Employee workflow template <span class="text-muted">(for the person the event is about; best with <code>employee.hired</code> / <code>employee.terminated</code>; once per person while one is open; needs auto-start turned on under <a href="employee_workflow_templates.php">Employee workflows</a>)</span></label>
                                <select class="form-select form-select-sm" name="cfg_template_id"><option value="0">-</option>
                                    <?php foreach ($workflow_templates as $wt) { ?><option value="<?= (int) $wt['workflow_template_id'] ?>" <?= (int) ($edit_config['template_id'] ?? 0) === (int) $wt['workflow_template_id'] ? 'selected' : '' ?>>[<?= $h(ucfirst($wt['type'])) ?>] <?= $h($wt['name']) ?></option><?php } ?>
                                </select>
                            </div>
                            <div class="col-12 act-box" data-for="set_ticket_field">
                                <div class="text-muted mb-2">Fill in only the fields to change; the rest stay as they are.</div>
                                <div class="row g-2">
                                    <div class="col-md-6"><label class="form-label mb-0">Status (by name)</label><select class="form-select form-select-sm" name="cfg_sf_status"><option value="">(leave as is)</option><?php foreach ($status_names as $sn) { ?><option <?= ($edit_config['status'] ?? '') === $sn ? 'selected' : '' ?>><?= $h($sn) ?></option><?php } ?></select></div>
                                    <div class="col-md-6"><label class="form-label mb-0">Priority</label><select class="form-select form-select-sm" name="cfg_sf_priority"><option value="">(leave as is)</option><?php foreach (['Low', 'Medium', 'High'] as $pr) { ?><option <?= ($edit_config['priority'] ?? '') === $pr ? 'selected' : '' ?>><?= $pr ?></option><?php } ?></select></div>
                                    <div class="col-md-6"><label class="form-label mb-0">Category</label><select class="form-select form-select-sm" name="cfg_sf_category"><option value="0">(leave as is)</option><?php foreach ($categories as $cid => $cn) { ?><option value="<?= (int) $cid ?>" <?= (int) ($edit_config['category_id'] ?? 0) === $cid ? 'selected' : '' ?>><?= $h($cn) ?></option><?php } ?></select></div>
                                    <div class="col-md-6"><label class="form-label mb-0">Assignee</label><select class="form-select form-select-sm" name="cfg_sf_assignee"><option value="">(leave as is)</option><option value="none" <?= isset($edit_config['assignee']) && (int) $edit_config['assignee'] === 0 && ($edit['action_type'] ?? '') === 'set_ticket_field' ? 'selected' : '' ?>>Unassigned</option><?php foreach ($agents as $uid => $un) { ?><option value="<?= (int) $uid ?>" <?= (int) ($edit_config['assignee'] ?? 0) === $uid && ($edit['action_type'] ?? '') === 'set_ticket_field' ? 'selected' : '' ?>><?= $h($un) ?></option><?php } ?></select></div>
                                </div>
                            </div>
                            <div class="col-12 act-box" data-for="add_ticket_note">
                                <label class="form-label mb-0">Internal note (staff only; not shown to the requester)</label><textarea class="form-control form-control-sm" name="cfg_note" rows="3" maxlength="2000"><?= $h($edit_config['note'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12 act-box" data-for="assign_ticket">
                                <label class="form-label mb-0">Assign to</label>
                                <select class="form-select form-select-sm mb-2" name="cfg_as_mode"><option value="user" <?= ($edit_config['mode'] ?? 'user') === 'user' ? 'selected' : '' ?>>One technician</option><option value="round_robin" <?= ($edit_config['mode'] ?? '') === 'round_robin' ? 'selected' : '' ?>>Rotate through several technicians (round robin)</option></select>
                                <label class="form-label mb-0">Technician</label>
                                <select class="form-select form-select-sm mb-2" name="cfg_as_user"><option value="0">-</option><?php foreach ($agents as $uid => $un) { ?><option value="<?= (int) $uid ?>" <?= (int) ($edit_config['user_id'] ?? 0) === $uid ? 'selected' : '' ?>><?= $h($un) ?></option><?php } ?></select>
                                <label class="form-label mb-0">Rotation (pick two or more; hold Ctrl/Cmd)</label>
                                <select class="form-select form-select-sm" name="cfg_as_pool[]" multiple size="5"><?php foreach ($agents as $uid => $un) { ?><option value="<?= (int) $uid ?>" <?= in_array($uid, array_map('intval', (array) ($edit_config['pool'] ?? [])), true) ? 'selected' : '' ?>><?= $h($un) ?></option><?php } ?></select>
                            </div>
                            <div class="col-12 act-box" data-for="send_mail">
                                <label class="form-label mb-0">Send to</label>
                                <select class="form-select form-select-sm mb-2" name="cfg_mail_to"><?php foreach (\ITFlow\Automation\Actions\SendMailAction::TO as $k => $l) { ?><option value="<?= $h($k) ?>" <?= ($edit_config['to_type'] ?? 'assignee') === $k ? 'selected' : '' ?>><?= $h(ucfirst($l)) ?></option><?php } ?></select>
                                <div class="row g-2 mb-2"><div class="col-md-6"><label class="form-label mb-0">Fixed address (if chosen above)</label><input class="form-control form-control-sm" type="email" name="cfg_mail_address" maxlength="200" value="<?= $h($edit_config['address'] ?? '') ?>"></div>
                                    <div class="col-md-6"><label class="form-label mb-0">Technician (if chosen above)</label><select class="form-select form-select-sm" name="cfg_mail_user"><option value="0">-</option><?php foreach ($agents as $uid => $un) { ?><option value="<?= (int) $uid ?>" <?= (int) ($edit_config['user_id'] ?? 0) === $uid ? 'selected' : '' ?>><?= $h($un) ?></option><?php } ?></select></div></div>
                                <label class="form-label mb-0">Subject</label><input class="form-control form-control-sm mb-2" name="cfg_mail_subject" maxlength="200" value="<?= $h($edit_config['subject'] ?? '') ?>" placeholder="e.g. SLA warning: {ticket_number}">
                                <label class="form-label mb-0">Message</label><textarea class="form-control form-control-sm" name="cfg_mail_body" rows="4" maxlength="5000"><?= $h($edit_config['body'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12 act-box" data-for="create_task">
                                <label class="form-label mb-0">Task name</label><input class="form-control form-control-sm mb-2" name="cfg_task_name" maxlength="255" value="<?= $h($edit_config['name'] ?? '') ?>" placeholder="e.g. Confirm backups for {client_name}">
                                <div class="row g-2"><div class="col-md-6"><label class="form-label mb-0">Assign task to</label><select class="form-select form-select-sm" name="cfg_task_assignee"><option value="0">(nobody)</option><?php foreach ($agents as $uid => $un) { ?><option value="<?= (int) $uid ?>" <?= (int) ($edit_config['assignee_id'] ?? 0) === $uid ? 'selected' : '' ?>><?= $h($un) ?></option><?php } ?></select></div>
                                    <div class="col-md-6"><label class="form-label mb-0">Due in (days, 0 = no date)</label><input class="form-control form-control-sm" type="number" name="cfg_task_due_days" min="0" max="365" value="<?= (int) ($edit_config['due_days'] ?? 0) ?>"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" name="save_event_rule" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save rule</button>
                <?php if ($edit) { ?><a class="btn btn-light" href="event_rules.php">Cancel</a><a class="btn btn-outline-secondary" href="automation_test.php?rule_id=<?= (int) $edit['rule_id'] ?>"><i class="fas fa-vial me-1"></i>Test this rule</a><?php } ?>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Your rules</h4></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Rule</th><th>When</th><th>Only if</th><th>Then</th><th>Last run</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rules) { ?><tr><td colspan="7" class="text-center text-muted py-3">No rules yet.</td></tr><?php } ?>
            <?php foreach ($rules as $r) {
                $conds = RuleView::conditions($r['condition_json']);
                $lr = $last_run[(int) $r['rule_id']] ?? null; ?>
                <tr>
                    <td><strong><?= $h($r['name']) ?></strong><?php if ((int) ($r['priority'] ?? 100) !== 100) { ?> <span class="badge text-bg-light" title="Order">#<?= (int) $r['priority'] ?></span><?php } ?><?php if (!empty($r['stop_on_match'])) { ?> <span class="badge text-bg-warning" title="Later rules for this event are skipped when this one matches">stops</span><?php } ?></td>
                    <td><code><?= $h($r['trigger_event']) ?></code></td>
                    <td class="small"><?php foreach ($conds as $c) { ?><span class="badge text-bg-secondary me-1 text-wrap text-start"><?= $h($c) ?></span><?php } ?><?= $conds ? '' : '<span class="text-muted">every time</span>' ?></td>
                    <td class="small"><?= $h(RuleView::action($mysqli, $r)) ?></td>
                    <td class="small text-nowrap"><?php if ($lr) { ?><span class="badge text-bg-<?= $lr['status'] === 'ok' ? 'success' : ($lr['status'] === 'failed' ? 'danger' : 'warning') ?>"><?= $h($lr['status']) ?></span> <span class="text-secondary"><?= $h($lr['created_at']) ?></span><?php } else { ?><span class="text-muted">never</span><?php } ?></td>
                    <td><?= $r['is_enabled'] ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="automation_test.php?rule_id=<?= (int) $r['rule_id'] ?>" title="Test rule"><i class="fas fa-vial"></i></a>
                        <a class="btn btn-sm btn-light" href="automation.php?tab=log&amp;rule_id=<?= (int) $r['rule_id'] ?>" title="Run log"><i class="fas fa-history"></i></a>
                        <a class="btn btn-sm btn-light" href="event_rules.php?edit=<?= (int) $r['rule_id'] ?>" title="Edit"><i class="fas fa-edit"></i></a>
                        <form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="rule_id" value="<?= (int) $r['rule_id'] ?>"><button class="btn btn-sm btn-light" name="toggle_event_rule" title="Turn <?= $r['is_enabled'] ? 'off' : 'on' ?>"><i class="fas fa-power-off"></i></button></form>
                        <a class="btn btn-sm btn-outline-danger confirm-link" href="post.php?delete_event_rule=<?= (int) $r['rule_id'] ?>&csrf_token=<?= $h($csrf) ?>" title="Delete"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>

<script nonce="<?= $h($csp_nonce ?? '') ?>">
(function () {
    var sel = document.getElementById('actionType');
    function showAct() {
        document.querySelectorAll('.act-box').forEach(function (b) { b.style.display = (sel && b.getAttribute('data-for') === sel.value) ? '' : 'none'; });
    }
    if (sel) { sel.addEventListener('change', showAct); showAct(); }
    var rows = document.getElementById('condRows');
    if (!rows) { return; }
    document.getElementById('condAdd').addEventListener('click', function () {
        var last = rows.querySelector('.cond-row:last-child');
        var c = last.cloneNode(true);
        c.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        c.querySelectorAll('select').forEach(function (s) { s.selectedIndex = (s.name === 'cond_op[]') ? 0 : 0; });
        rows.appendChild(c);
    });
    rows.addEventListener('click', function (e) {
        var b = e.target.closest('.cond-remove');
        if (!b) { return; }
        var all = rows.querySelectorAll('.cond-row');
        if (all.length > 1) { b.closest('.cond-row').remove(); } else { b.closest('.cond-row').querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
    });
})();
</script>

<?php require_once "../includes/footer.php";
