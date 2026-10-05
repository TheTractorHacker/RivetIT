<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/event_bus.php";
require_once "includes/webhook_events.php";

use RivetCore\Automation\AutomationRuleStore;

$csrf = $_SESSION['csrf_token'];
$ready = class_exists(AutomationRuleStore::class) && rivetTableExists($mysqli, 'automation_rules');
$store = $ready ? new AutomationRuleStore(rivetCoreDb($mysqli)) : null;
$rules = $ready ? $store->all() : [];
$edit = null;
if ($ready && isset($_GET['edit'])) {
    $edit = $store->find(intval($_GET['edit']));
}
$edit_conditions = $edit ? (json_decode((string) $edit['condition_json'], true) ?: []) : [];
$edit_config = $edit ? (json_decode((string) $edit['action_config_json'], true) ?: []) : [];
$condition_rows = array_slice(array_merge(array_map(null, array_keys($edit_conditions), array_values($edit_conditions)), [[null, null], [null, null], [null, null], [null, null]]), 0, max(4, count($edit_conditions) + 1));

$runs = [];
if ($ready) {
    $res = mysqli_query($mysqli, "SELECT created_at, action, summary FROM audit_events WHERE event_type = 'automation.rule_fired' ORDER BY audit_id DESC LIMIT 25");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $runs[] = $r;
    }
}
$agents = [];
$res = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $agents[(int) $r['user_id']] = $r['user_name'];
}
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>

<div class="card mb-3">
    <div class="card-header py-3"><h3 class="card-title mb-0"><i class="fas fa-fw fa-bolt me-2"></i>Event rules</h3></div>
    <div class="card-body">
        <p class="text-muted mb-1">When something happens in <?= $h(APP_NAME) ?> (a ticket is created, a login fails, a backup runs, a setting changes), a rule can create a ticket, notify someone or call another service. This is the same stream of events webhooks can subscribe to.</p>
        <p class="text-muted small mb-0">Rules run in the background through the job queue, so a slow or failing action never slows what a person is doing; failures are retried and shown on the <a href="job_queue.php">Job queue</a> page. A condition compares a field of the event with a value; all conditions must match (leave empty to fire every time).</p>
    </div>
</div>

<?php if (!$ready) { ?>
    <div class="alert alert-warning">Run the database update first (Administration &rarr; Update) to turn on event rules.</div>
<?php } else { ?>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><?= $edit ? 'Edit rule' : 'Add a rule' ?></h4></div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
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
                    <label class="form-label">Only if <span class="text-muted small">(optional; field = value, for example <code>priority</code> = <code>High</code> or <code>ticket.priority</code> = <code>High</code>)</span></label>
                    <?php foreach ($condition_rows as $cr) { ?>
                        <div class="row g-2 mb-1">
                            <div class="col-md-4"><input class="form-control form-control-sm" name="cond_field[]" maxlength="100" placeholder="field" value="<?= $h($cr[0] ?? '') ?>"></div>
                            <div class="col-md-4"><input class="form-control form-control-sm" name="cond_value[]" maxlength="200" placeholder="value" value="<?= $h($cr[1] ?? '') ?>"></div>
                        </div>
                    <?php } ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Then</label>
                    <select class="form-select" name="action_type" required>
                        <?php foreach (AutomationRuleStore::ACTIONS as $k => $label) { ?><option value="<?= $h($k) ?>" <?= ($edit['action_type'] ?? 'notify_user') === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php } ?>
                    </select>
                    <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="rule_enabled" <?= !$edit || $edit['is_enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="rule_enabled">Rule is on</label></div>
                </div>
                <div class="col-md-8">
                    <div class="border rounded p-3 small">
                        <div class="fw-bold mb-2">Details for the chosen action <span class="text-muted fw-normal">(use <code>{field}</code> to insert a value from the event, e.g. <code>{ticket.id}</code>)</span></div>
                        <div class="row g-2">
                            <div class="col-md-12"><label class="form-label mb-0">Create a ticket: subject</label><input class="form-control form-control-sm" name="cfg_subject" maxlength="500" value="<?= $h($edit_config['subject'] ?? '') ?>"></div>
                            <div class="col-md-12"><label class="form-label mb-0">Create a ticket: details</label><textarea class="form-control form-control-sm" name="cfg_details" rows="2" maxlength="5000"><?= $h($edit_config['details'] ?? '') ?></textarea></div>
                            <div class="col-md-6"><label class="form-label mb-0">Create a ticket: priority</label><select class="form-select form-select-sm" name="cfg_priority"><?php foreach (['Low', 'Medium', 'High'] as $pr) { ?><option <?= ($edit_config['priority'] ?? 'Low') === $pr ? 'selected' : '' ?>><?= $pr ?></option><?php } ?></select></div>
                            <div class="col-md-12"><label class="form-label mb-0">Send a webhook: URL</label><input class="form-control form-control-sm" name="cfg_url" maxlength="500" placeholder="https://..." value="<?= $h($edit_config['url'] ?? '') ?>"></div>
                            <div class="col-md-12"><label class="form-label mb-0">Send a webhook: signing secret (optional)</label><input class="form-control form-control-sm" name="cfg_secret" maxlength="200" value="<?= $h($edit_config['secret'] ?? '') ?>"></div>
                            <div class="col-md-12"><label class="form-label mb-0">Notify: message</label><input class="form-control form-control-sm" name="cfg_message" maxlength="1000" value="<?= $h($edit_config['message'] ?? '') ?>"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" name="save_event_rule" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save rule</button>
                <?php if ($edit) { ?><a class="btn btn-light" href="event_rules.php">Cancel</a><?php } ?>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Your rules</h4></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Rule</th><th>When</th><th>Only if</th><th>Then</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rules) { ?><tr><td colspan="6" class="text-center text-muted py-3">No rules yet.</td></tr><?php } ?>
            <?php foreach ($rules as $r) {
                $conds = json_decode((string) $r['condition_json'], true) ?: []; ?>
                <tr>
                    <td><strong><?= $h($r['name']) ?></strong></td>
                    <td><code><?= $h($r['trigger_event']) ?></code></td>
                    <td class="small"><?php foreach ($conds as $f => $v) { ?><span class="badge text-bg-secondary me-1"><?= $h($f) ?> = <?= $h($v) ?></span><?php } ?><?= $conds ? '' : '<span class="text-muted">every time</span>' ?></td>
                    <td><?= $h(AutomationRuleStore::ACTIONS[$r['action_type']] ?? $r['action_type']) ?></td>
                    <td><?= $r['is_enabled'] ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="event_rules.php?edit=<?= (int) $r['rule_id'] ?>" title="Edit"><i class="fas fa-edit"></i></a>
                        <form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="rule_id" value="<?= (int) $r['rule_id'] ?>"><button class="btn btn-sm btn-light" name="toggle_event_rule" title="Turn <?= $r['is_enabled'] ? 'off' : 'on' ?>"><i class="fas fa-power-off"></i></button></form>
                        <a class="btn btn-sm btn-outline-danger confirm-link" href="post.php?delete_event_rule=<?= (int) $r['rule_id'] ?>&csrf_token=<?= $csrf ?>" title="Delete"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Recent activity</h4></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>When</th><th>Result</th><th>What happened</th></tr></thead>
            <tbody>
            <?php if (!$runs) { ?><tr><td colspan="3" class="text-center text-muted py-3">Nothing has run yet.</td></tr><?php } ?>
            <?php foreach ($runs as $r) { ?>
                <tr><td class="text-nowrap text-secondary"><?= $h($r['created_at']) ?></td><td><?= $r['action'] === 'ok' ? '<span class="badge text-bg-success">ok</span>' : '<span class="badge text-bg-danger">failed</span>' ?></td><td class="small"><?= $h($r['summary']) ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>

<?php require_once "../includes/footer.php";
