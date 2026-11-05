<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/event_bus.php";

use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleView;
use ITFlow\Automation\TicketRuleView;
use ITFlow\Automation\LegacyRuleImporter;

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $_SESSION['csrf_token'];
$tabs = ['event' => 'Event rules', 'ticket' => 'Ticket rules', 'workflows' => 'Lifecycle workflows', 'log' => 'Run log'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'event';
$ready = class_exists(RuleEngine::class) && rivetTableExists($mysqli, 'automation_rules');
$has_runs = rivetTableExists($mysqli, 'automation_rule_runs');
$msp = class_exists('\RivetMSP\Core\CoreBridge');
$switch_ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM settings LIKE 'config_automation_enabled'")) > 0;
$switch_on = rivetCoreModuleOn('core.automation.enabled');
$extras_ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM settings LIKE 'config_automation_cert_days'")) > 0;
$extras = $extras_ready ? mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_automation_run_script_enabled AS run_script, config_automation_odoo_user_enabled AS odoo_user, config_automation_cert_days AS cert_days FROM settings WHERE company_id = 1')) : null;
$has_retry = $ready && $has_runs && \ITFlow\Automation\RuleAdmin::hasRetryColumns($mysqli);
?>

<div class="card mb-3">
    <div class="card-header py-3"><h3 class="card-title mb-0"><i class="fas fa-fw fa-cogs me-2"></i>Automation</h3></div>
    <div class="card-body">
        <p class="text-muted mb-2">Everything that happens by itself in one place: event rules (this page's first tab), the ticket rules that run from cron, employee lifecycle workflows, and a log of every rule run.</p>
        <form action="post.php" method="post" class="d-flex align-items-center gap-3 flex-wrap">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="automation_enabled" name="automation_enabled" value="1" <?= $switch_on ? 'checked' : '' ?> <?= ($msp || !$switch_ready) ? 'disabled' : '' ?>>
                <label class="form-check-label fw-bold" for="automation_enabled">Enable automation rules</label>
            </div>
            <?php if (!$msp && $switch_ready) { ?><button class="btn btn-sm btn-primary" name="set_automation_enabled" type="submit">Save</button><?php } ?>
            <span class="small text-muted">
                <?php if ($msp) { ?>Switched with the RivetMSP Core settings in this edition (currently <?= $switch_on ? 'on' : 'off' ?>).
                <?php } elseif (!$switch_ready) { ?>Run the database update to get this switch.
                <?php } else { ?>When off, no event rule runs (events still reach webhooks). Ticket rules and lifecycle workflows are not affected.<?php } ?>
            </span>
        </form>
        <?php if (!$switch_on) { ?><div class="alert alert-warning mt-3 mb-0"><i class="fas fa-pause-circle me-1"></i>Automation rules are <strong>off</strong>: event rules will not run until this is switched on.</div><?php } ?>
    </div>
</div>

<div class="card mb-3" id="automation-switches">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-shield-alt me-2"></i>Action switches</h4></div>
    <div class="card-body">
        <?php if (!$extras_ready) { ?><p class="text-muted mb-0">Run the database update to get these switches.</p><?php } else { ?>
        <form action="post.php" method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="automation_run_script_enabled" name="automation_run_script_enabled" value="1" <?= !empty($extras['run_script']) ? 'checked' : '' ?>><label class="form-check-label" for="automation_run_script_enabled">Allow rules to run RMM scripts</label></div>
                <div class="form-text">Off by default. A rule&rsquo;s &ldquo;Run an RMM script&rdquo; action does nothing until this is on. Jobs run as the technician the rule names, under the RMM approval rules.</div></div>
            <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="automation_odoo_user_enabled" name="automation_odoo_user_enabled" value="1" <?= !empty($extras['odoo_user']) ? 'checked' : '' ?>><label class="form-check-label" for="automation_odoo_user_enabled">Allow rules to create Odoo users</label></div>
                <div class="form-text">Off by default, and no Odoo write connection ships with this version, so even when on the action stops with a message.</div></div>
            <div class="col-md-2"><label class="form-label" for="automation_cert_days">Certificate warning (days)</label><input class="form-control" type="number" min="1" max="365" id="automation_cert_days" name="automation_cert_days" value="<?= (int) ($extras['cert_days'] ?? 30) ?>">
                <div class="form-text">First <code>certificate.expiring</code> event; again at 7, 1 and 0.</div></div>
            <div class="col-md-2"><button class="btn btn-primary" name="set_automation_extras" type="submit">Save</button></div>
        </form>
        <?php } ?>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $k => $label) { ?><li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="automation.php?tab=<?= $h($k) ?>"><?= $h($label) ?></a></li><?php } ?>
</ul>

<?php if ($tab === 'event') {
    $rules = [];
    if ($ready) {
        $res = mysqli_query($mysqli, 'SELECT * FROM automation_rules ORDER BY trigger_event, rule_id');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $rules[] = $r;
        }
    }
    $counts = [];
    if ($has_runs) {
        $res = mysqli_query($mysqli, "SELECT rule_id, status, COUNT(*) AS c FROM automation_rule_runs WHERE created_at >= NOW() - INTERVAL 1 DAY GROUP BY rule_id, status");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $counts[(int) $r['rule_id']][$r['status']] = (int) $r['c'];
        }
    } ?>
<div class="card mb-3">
    <div class="card-header py-3 d-flex justify-content-between align-items-center"><h4 class="card-title mb-0">Event rules</h4><a class="btn btn-primary btn-sm" href="event_rules.php"><i class="fas fa-plus me-1"></i>Add or edit rules</a></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Rule</th><th>When</th><th>Only if</th><th>Then</th><th>Last 24 hours</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$ready) { ?><tr><td colspan="7" class="text-center text-muted py-3">Run the database update first.</td></tr>
        <?php } elseif (!$rules) { ?><tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-bolt fa-2x mb-2 d-block"></i><div class="fw-semibold text-dark">No event rules yet</div><div class="mb-3">A rule runs an action when something happens, for example notify a technician when a critical ticket arrives.</div><a class="btn btn-primary btn-sm text-decoration-none" href="event_rules.php"><i class="fas fa-magic me-1"></i>Start from a ready-made recipe</a></td></tr><?php } ?>
        <?php foreach ($rules as $r) { $c = $counts[(int) $r['rule_id']] ?? []; ?>
            <tr>
                <td><strong><?= $h($r['name']) ?></strong></td>
                <td><code><?= $h($r['trigger_event']) ?></code></td>
                <td class="small"><?= $h(implode(' ', RuleView::conditions($r['condition_json'])) ?: 'every time') ?></td>
                <td class="small"><?= $h(RuleView::action($mysqli, $r)) ?></td>
                <td class="small"><?php foreach ($c as $st => $n) { ?><span class="badge text-bg-<?= $st === 'ok' ? 'success' : ($st === 'failed' ? 'danger' : 'warning') ?> me-1"><?= (int) $n ?> <?= $h($st) ?></span><?php } ?><?= $c ? '' : '<span class="text-muted">no runs</span>' ?></td>
                <td><?= $r['is_enabled'] ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
                <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-light" href="automation_test.php?rule_id=<?= (int) $r['rule_id'] ?>" title="Test rule"><i class="fas fa-vial"></i></a>
                    <a class="btn btn-sm btn-light" href="automation.php?tab=log&amp;rule_id=<?= (int) $r['rule_id'] ?>" title="Run log"><i class="fas fa-history"></i></a>
                    <a class="btn btn-sm btn-light" href="event_rules.php?edit=<?= (int) $r['rule_id'] ?>" title="Edit"><i class="fas fa-edit"></i></a>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>

<?php $unified = ($ready && LegacyRuleImporter::available($mysqli)) ? LegacyRuleImporter::unifiedRows($mysqli) : [];
    if ($unified) {
        $tview = new TicketRuleView($mysqli);
        $state_text = [
            'convertible' => ['secondary', 'Running as a ticket rule'],
            'legacy_only' => ['secondary', 'Running as a ticket rule'],
            'converted_waiting' => ['warning', 'Copy ready, switched off'],
            'both_on' => ['danger', 'Both are on'],
            'switched' => ['success', 'Switched over'],
            'both_off' => ['secondary', 'Both are off'],
        ]; ?>
<div class="card mb-3" id="legacy-rules">
    <div class="card-header py-3"><h4 class="card-title mb-0">Older ticket rules <span class="text-muted small fw-normal">(read only here)</span></h4></div>
    <div class="card-body pb-0"><p class="text-muted small">These run from cron and keep running exactly as before. Where an event rule can do the same job, <strong>Convert</strong> makes a copy that is <strong>switched off</strong>. Check the copy, then <strong>Switch over</strong> turns the copy on and the old rule off in one step, so the two never both act. Rules with no equivalent stay as they are, with the reason.</p></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Rule</th><th>When</th><th>Does</th><th>State</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($unified as $u) {
            $rule_row = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT * FROM ticket_automation_rules WHERE rule_id = ' . (int) $u['legacy_id']));
            [$badge, $label] = $state_text[$u['state']]; ?>
            <tr class="<?= $u['enabled'] ? '' : 'text-muted' ?>">
                <td><strong><?= $h($u['name']) ?></strong><div class="small text-muted">ticket rule #<?= (int) $u['legacy_id'] ?> &middot; <?= $u['enabled'] ? 'on' : 'off' ?></div></td>
                <td><span class="badge text-bg-info text-nowrap"><?= $h($tview->trigger((string) $u['trigger'])) ?></span></td>
                <td class="small"><?php if ($rule_row) { foreach (TicketRuleView::conditionsOf($rule_row) as $c) { ?><div><?= $h($tview->condition($c)) ?></div><?php } foreach (TicketRuleView::actionsOf($rule_row) as $a) { ?><div class="fw-semibold"><?= $h($tview->action($a)) ?></div><?php } } ?></td>
                <td><span class="badge text-bg-<?= $badge ?>"><?= $h($label) ?></span>
                    <?php if ($u['imported_rule_id'] !== null) { ?><div class="small"><a href="event_rules.php?edit=<?= (int) $u['imported_rule_id'] ?>">Event rule #<?= (int) $u['imported_rule_id'] ?></a> (<?= $u['new_enabled'] ? 'on' : 'off' ?>)</div>
                    <?php } elseif (!$u['importable']) { ?><div class="small text-muted"><?= $h($u['reason']) ?></div><?php } ?>
                    <?php foreach ($u['notes'] as $n) { ?><div class="small text-muted"><?= $h($n) ?></div><?php } ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($u['state'] === 'convertible' || $u['state'] === 'converted_waiting' || $u['state'] === 'both_on') { ?>
                    <form action="post.php" method="post" class="d-inline"<?= $u['state'] === 'convertible' ? '' : ' data-confirm-submit="Turn the event rule on and the old ticket rule off? From now on only the event rule acts. You can turn the ticket rule back on in the ticket rule editor."' ?>>
                        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="legacy_id" value="<?= (int) $u['legacy_id'] ?>">
                        <?php if ($u['state'] === 'convertible') { ?><button class="btn btn-sm btn-primary" name="convert_legacy_rule" type="submit"><i class="fas fa-exchange-alt me-1"></i>Convert to event rule</button>
                        <?php } else { ?><button class="btn btn-sm btn-outline-primary" name="switch_legacy_rule" type="submit"><i class="fas fa-random me-1"></i>Switch over</button><?php } ?>
                    </form>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>
<?php } ?>

<?php } elseif ($tab === 'ticket') {
    $view = new TicketRuleView($mysqli);
    $res = mysqli_query($mysqli, 'SELECT * FROM ticket_automation_rules ORDER BY rule_order ASC, rule_id ASC'); ?>
<div class="card mb-3">
    <div class="card-header py-3 d-flex justify-content-between align-items-center"><h4 class="card-title mb-0">Ticket rules <span class="text-muted small fw-normal">(read only here)</span></h4>
        <span><a class="btn btn-sm btn-primary" href="ticket_automation.php"><i class="fas fa-edit me-1"></i>Open the ticket rule editor</a> <a class="btn btn-sm btn-light" href="ticket_automation_log.php">Their run log</a></span></div>
    <div class="card-body pb-0"><p class="text-muted small">These run from cron (scheduled checks, RMM alerts, asset online/offline, requester returns) and when a ticket opens. They are a separate engine from event rules and keep working exactly as before; this list is only a read-only view with ids shown as names.</p></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Rule</th><th>Trigger</th><th>Conditions</th><th>Actions</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$res || mysqli_num_rows($res) === 0) { ?><tr><td colspan="5" class="text-center text-muted py-3">No ticket rules.</td></tr><?php } ?>
        <?php while ($res && ($r = mysqli_fetch_assoc($res))) { ?>
            <tr class="<?= $r['rule_enabled'] ? '' : 'text-muted' ?>">
                <td><strong><?= $h($r['rule_name']) ?></strong></td>
                <td><span class="badge text-bg-info"><?= $h($view->trigger((string) $r['rule_trigger'])) ?></span></td>
                <td class="small"><?php foreach (TicketRuleView::conditionsOf($r) as $c) { ?><div><?= $h($view->condition($c)) ?></div><?php } ?></td>
                <td class="small"><?php foreach (TicketRuleView::actionsOf($r) as $a) { ?><div><?= $h($view->action($a)) ?></div><?php } ?></td>
                <td><?= $r['rule_enabled'] ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>

<?php $legacy = $ready ? LegacyRuleImporter::report($mysqli) : []; ?>
<div class="card mb-3" id="legacy-import">
    <div class="card-header py-3"><h4 class="card-title mb-0">Move ticket rules to event rules</h4></div>
    <div class="card-body">
        <p class="text-muted small">Where an event rule does the same job, you can import a copy. Copies are created <strong>switched off</strong>; the ticket rule is never changed and keeps running, so review the copy, switch it on, then switch the old rule off so the two do not both act. Most ticket rules scan tickets by age on every cron pass; event rules react to single events, so those have no equivalent and are listed with the reason.</p>
        <?php if (!$legacy) { ?><p class="text-muted mb-0">No ticket rules to move.</p><?php } else { ?>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <div class="table-responsive"><table class="table table-sm align-middle mb-2">
                <thead><tr><th style="width:2.5rem"></th><th>Ticket rule</th><th>Becomes</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($legacy as $l) { ?>
                    <tr>
                        <td><?php if ($l['importable'] && $l['imported_rule_id'] === null) { ?><input class="form-check-input" type="checkbox" name="legacy_ids[]" value="<?= (int) $l['legacy_id'] ?>" aria-label="Import <?= $h($l['name']) ?>"><?php } ?></td>
                        <td><strong><?= $h($l['name']) ?></strong><div class="small text-muted"><?= $h($l['trigger']) ?></div></td>
                        <td><?php if ($l['imported_rule_id'] !== null) { ?><a href="event_rules.php?edit=<?= (int) $l['imported_rule_id'] ?>">Imported as event rule #<?= (int) $l['imported_rule_id'] ?></a>
                            <?php } elseif ($l['importable']) { ?>Event rule on <code><?= $h($l['event']) ?></code> <span class="badge text-bg-success">can import</span>
                            <?php } else { ?><span class="badge text-bg-secondary">stays a ticket rule</span><?php } ?></td>
                        <td class="small text-muted"><?= $h($l['reason']) ?><?php foreach ($l['notes'] as $n) { ?><div><?= $h($n) ?></div><?php } ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table></div>
            <button class="btn btn-primary btn-sm" name="import_legacy_rules" type="submit"><i class="fas fa-file-import me-1"></i>Import selected as event rules (switched off)</button>
        </form>
        <?php } ?>
    </div>
</div>

<?php } elseif ($tab === 'workflows') {
    $wf_ready = rivetTableExists($mysqli, 'workflow_templates');
    $tpl = [];
    $open = 0;
    $starters = 0;
    $auto = false;
    if ($wf_ready) {
        $res = mysqli_query($mysqli, "SELECT type, is_active, COUNT(*) AS c FROM workflow_templates WHERE archived_at IS NULL GROUP BY type, is_active");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $tpl[$r['type']][(int) $r['is_active']] = (int) $r['c'];
        }
        $open = (int) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM workflow_runs WHERE status IN ('in_progress','paused')"))[0] ?? 0);
        $starters = $ready ? (int) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM automation_rules WHERE action_type = 'start_workflow' AND is_enabled = 1"))[0] ?? 0) : 0;
        $auto = \ITFlow\Workflow\LifecycleEvents::enabled($mysqli);
    } ?>
<div class="card mb-3">
    <div class="card-header py-3 d-flex justify-content-between align-items-center"><h4 class="card-title mb-0">Lifecycle workflows</h4><a class="btn btn-primary btn-sm" href="employee_workflow_templates.php">Manage templates &amp; auto-start</a></div>
    <div class="card-body">
        <?php if (!$wf_ready) { ?><p class="text-muted mb-0">Run the database update to use employee workflows.</p><?php } else { ?>
        <div class="row g-3">
            <?php foreach (['onboarding', 'offboarding'] as $t) { ?><div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small"><?= $h(ucfirst($t)) ?> templates</div><div class="fs-3"><?= (int) ($tpl[$t][1] ?? 0) ?> <span class="fs-6 text-muted">active</span></div><div class="small text-muted"><?= (int) ($tpl[$t][0] ?? 0) ?> inactive</div></div></div><?php } ?>
            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Workflows in progress</div><div class="fs-3"><?= $open ?></div></div></div>
            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Auto-start</div><div class="fs-5"><?= $auto ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></div><div class="small text-muted"><?= $starters ?> event rule(s) start a workflow</div></div></div>
        </div>
        <p class="small text-muted mt-3 mb-0">A workflow starts from an event rule whose action is &ldquo;Start an employee workflow&rdquo; (for example on <code>employee.hired</code>), or by hand. The event <code>workflow.task_completed</code> lets other event rules react when a task is done.</p>
        <?php } ?>
    </div>
</div>

<?php } else {
    $f_rule = intval($_GET['rule_id'] ?? 0);
    $f_status = in_array($_GET['status'] ?? '', RuleEngine::STATUSES, true) ? $_GET['status'] : '';
    $rows = [];
    if ($has_runs) {
        $where = [];
        if ($f_rule > 0) {
            $where[] = "r.rule_id = $f_rule";
        }
        if ($f_status !== '') {
            $where[] = "r.status = '" . mysqli_real_escape_string($mysqli, $f_status) . "'";
        }
        $res = mysqli_query($mysqli, 'SELECT r.*, a.name AS rule_name FROM automation_rule_runs r LEFT JOIN automation_rules a ON a.rule_id = r.rule_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.run_id DESC LIMIT 200');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $rows[] = $r;
        }
    }
    $retried = [];
    if ($has_retry && $rows) {
        // failed runs that a later run already repeated successfully (so "Run again" is not offered twice)
        $res = mysqli_query($mysqli, "SELECT retry_of_run_id, MIN(run_id) FROM automation_rule_runs WHERE retry_of_run_id IS NOT NULL AND status = 'ok' GROUP BY retry_of_run_id");
        while ($res && ($r = mysqli_fetch_row($res))) {
            $retried[(int) $r[0]] = (int) $r[1];
        }
    } ?>
<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Run log <?= $f_rule ? '<span class="text-muted small">for rule #' . $f_rule . ' (<a href="automation.php?tab=log">all rules</a>)</span>' : '' ?></h4></div>
    <div class="card-body pb-0">
        <form method="get" class="row g-2 mb-2">
            <input type="hidden" name="tab" value="log">
            <?php if ($f_rule) { ?><input type="hidden" name="rule_id" value="<?= $f_rule ?>"><?php } ?>
            <div class="col-auto"><select class="form-select form-select-sm" name="status"><option value="">Any result</option><?php foreach (RuleEngine::STATUSES as $st) { ?><option <?= $f_status === $st ? 'selected' : '' ?>><?= $h($st) ?></option><?php } ?></select></div>
            <div class="col-auto"><button class="btn btn-sm btn-light">Filter</button></div>
            <div class="col text-muted small align-self-center">The newest 200 runs. Older rows are removed by the log retention setting (Administration &rarr; Compliance). <strong>throttled</strong> = the rule hit its runs-per-minute limit; <strong>loop_blocked</strong> = the loop guard stopped a rule from re-triggering itself.</div>
        </form>
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>When</th><th>Rule</th><th>Event</th><th>Result</th><th>What happened</th><th class="text-end">Time</th><th></th></tr></thead>
        <tbody>
        <?php if (!$has_runs) { ?><tr><td colspan="7" class="text-center text-muted py-3">Run the database update first.</td></tr>
        <?php } elseif (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-3">Nothing has run yet.</td></tr><?php } ?>
        <?php foreach ($rows as $r) { ?>
            <tr>
                <td class="text-nowrap text-secondary"><?= $h($r['created_at']) ?></td>
                <td><a href="event_rules.php?edit=<?= (int) $r['rule_id'] ?>"><?= $h($r['rule_name'] ?? ('rule #' . $r['rule_id'] . ' (deleted)')) ?></a></td>
                <td><code><?= $h($r['event_type']) ?></code><?= (int) $r['chain_depth'] > 0 ? ' <span class="badge text-bg-light" title="Triggered by another rule">chain ' . (int) $r['chain_depth'] . '</span>' : '' ?></td>
                <td><span class="badge text-bg-<?= $r['status'] === 'ok' ? 'success' : ($r['status'] === 'failed' ? 'danger' : 'warning') ?>"><?= $h($r['status']) ?></span>
                    <?php if ($has_retry && (int) $r['attempt'] > 1) { ?><div class="small text-muted">try <?= (int) $r['attempt'] ?> of <?= (int) RuleEngine::MAX_ATTEMPTS ?><?= $r['status'] === 'failed' && (int) $r['attempt'] >= RuleEngine::MAX_ATTEMPTS ? ' (gave up)' : '' ?></div><?php } ?>
                    <?php if ($has_retry && ($r['trigger_source'] ?? '') === 'manual') { ?><div class="small text-muted">run again by hand<?= $r['retry_of_run_id'] ? ', repeats #' . (int) $r['retry_of_run_id'] : '' ?></div><?php } ?></td>
                <td class="small"><?= $h($r['message']) ?></td>
                <td class="text-end small text-nowrap"><?= (int) $r['duration_ms'] ?> ms</td>
                <td class="text-end">
                    <?php if ($has_retry && $r['status'] === 'failed') {
                        $repeated = isset($retried[(int) $r['run_id']]);
                        if ($repeated) { ?><span class="small text-muted">repeated as #<?= (int) $retried[(int) $r['run_id']] ?></span>
                        <?php } elseif ($r['context_json'] !== null) { ?>
                        <form action="post.php" method="post" class="d-inline" data-confirm-submit="Run this rule again with the same event? It will do what the rule does, for real.">
                            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="run_id" value="<?= (int) $r['run_id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" name="rerun_automation_run" type="submit">Run again</button>
                        </form>
                        <?php } else { ?><span class="small text-muted">event not kept</span><?php } } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>
<?php } ?>

<?php require_once "../includes/footer.php";
