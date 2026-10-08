<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/event_bus.php";

use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleView;
use ITFlow\Automation\TicketRuleView;

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $_SESSION['csrf_token'];
$tabs = ['event' => 'Event rules', 'ticket' => 'Ticket rules', 'workflows' => 'Lifecycle workflows', 'log' => 'Run log'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'event';
$ready = class_exists(RuleEngine::class) && rivetTableExists($mysqli, 'automation_rules');
$has_runs = rivetTableExists($mysqli, 'automation_rule_runs');
$msp = class_exists('\RivetMSP\Core\CoreBridge');
$switch_ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM settings LIKE 'config_automation_enabled'")) > 0;
$switch_on = rivetCoreModuleOn('core.automation.enabled');
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
        <?php } elseif (!$rules) { ?><tr><td colspan="7" class="text-center text-muted py-3">No event rules yet.</td></tr><?php } ?>
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
        <thead><tr><th>When</th><th>Rule</th><th>Event</th><th>Result</th><th>What happened</th><th class="text-end">Time</th></tr></thead>
        <tbody>
        <?php if (!$has_runs) { ?><tr><td colspan="6" class="text-center text-muted py-3">Run the database update first.</td></tr>
        <?php } elseif (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-3">Nothing has run yet.</td></tr><?php } ?>
        <?php foreach ($rows as $r) { ?>
            <tr>
                <td class="text-nowrap text-secondary"><?= $h($r['created_at']) ?></td>
                <td><a href="event_rules.php?edit=<?= (int) $r['rule_id'] ?>"><?= $h($r['rule_name'] ?? ('rule #' . $r['rule_id'] . ' (deleted)')) ?></a></td>
                <td><code><?= $h($r['event_type']) ?></code><?= (int) $r['chain_depth'] > 0 ? ' <span class="badge text-bg-light" title="Triggered by another rule">chain ' . (int) $r['chain_depth'] . '</span>' : '' ?></td>
                <td><span class="badge text-bg-<?= $r['status'] === 'ok' ? 'success' : ($r['status'] === 'failed' ? 'danger' : 'warning') ?>"><?= $h($r['status']) ?></span></td>
                <td class="small"><?= $h($r['message']) ?></td>
                <td class="text-end small text-nowrap"><?= (int) $r['duration_ms'] ?> ms</td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>
<?php } ?>

<?php require_once "../includes/footer.php";
