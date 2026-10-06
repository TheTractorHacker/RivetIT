<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/event_bus.php";

use ITFlow\Automation\EventPayloads;
use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleView;

/*
 * Test rule: runs a rule's conditions and shows what each action WOULD do against a sample event. Nothing is written, queued or
 * sent: the engine runs the action in dry-run mode (read-only). It still checks the CSRF token because it reads audit/ticket data.
 */
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $_SESSION['csrf_token'];
$ready = class_exists(RuleEngine::class) && rivetTableExists($mysqli, 'automation_rules');
$engine = $ready ? new RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli)) : null;
$rule = $ready ? $engine->find(intval($_GET['rule_id'] ?? $_POST['rule_id'] ?? 0)) : null;
?>
<div class="card mb-3">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-vial me-2"></i>Test rule</h3>
        <a class="btn btn-sm btn-outline-secondary" href="event_rules.php">Back to Event rules</a>
    </div>
    <div class="card-body">
<?php if (!$rule) { ?>
        <div class="alert alert-warning mb-0">That rule does not exist. Choose one from <a href="event_rules.php">Event rules</a>.</div>
<?php } else {
    $event = (string) $rule['trigger_event'];
    $recent_tickets = [];
    $res = mysqli_query($mysqli, 'SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject FROM tickets WHERE ticket_archived_at IS NULL ORDER BY ticket_id DESC LIMIT 25');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $recent_tickets[(int) $r['ticket_id']] = $r['ticket_prefix'] . $r['ticket_number'] . ' - ' . mb_substr((string) $r['ticket_subject'], 0, 60);
    }
    $recent_audit = [];
    $stmt = mysqli_prepare($mysqli, 'SELECT * FROM audit_events WHERE event_type = ? ORDER BY audit_id DESC LIMIT 25');
    mysqli_stmt_bind_param($stmt, 's', $event);
    mysqli_stmt_execute($stmt);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $r) {
        $recent_audit[(int) $r['audit_id']] = $r;
    }
    mysqli_stmt_close($stmt);
    $ticket_event = RuleEngine::eventHasTicket($event);
    $source = (string) ($_POST['source'] ?? ($ticket_event && $recent_tickets ? 'ticket' : ($recent_audit ? 'audit' : 'synthetic')));
    $result = null;
    $sample_json = $_POST['synthetic_json'] ?? json_encode(EventPayloads::sample($mysqli, $event), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateCSRFToken($_POST['csrf_token'] ?? '');
        $data = null;
        if ($source === 'ticket') {
            $tid = intval($_POST['sample_ticket'] ?? 0);
            if (isset($recent_tickets[$tid])) {
                $base = EventPayloads::ticket($mysqli, $tid);
                $data = $base + array_diff_key(EventPayloads::sample($mysqli, $event), $base); // event-specific extras (SLA clock, ...) from the sample
            }
        } elseif ($source === 'audit') {
            $aid = intval($_POST['sample_audit'] ?? 0);
            if (isset($recent_audit[$aid])) {
                $data = EventPayloads::fromAuditRow($recent_audit[$aid]);
            }
        } else {
            $decoded = json_decode((string) $sample_json, true);
            if (is_array($decoded) && mb_strlen((string) $sample_json) <= 20000) {
                $data = $decoded;
            } else {
                $error = 'The synthetic event must be a JSON object.';
            }
        }
        if ($data === null && $error === '') {
            $error = 'Choose a sample event.';
        }
        if ($data !== null) {
            unset($data['_chain']);
            $context = EventPayloads::context($event, $data);
            $result = $engine->test($rule, $event, $context);
            logAction('Automation', 'Test', "$session_name tested event rule " . (int) $rule['rule_id'] . ' (dry run)');
        }
    }
?>
        <p class="mb-1"><strong><?= $h($rule['name']) ?></strong> <span class="text-muted">when</span> <code><?= $h($event) ?></code></p>
        <p class="text-muted small mb-3">Only if: <?= $h(implode(' ', RuleView::conditions($rule['condition_json'])) ?: 'every time') ?> &middot; Then: <?= $h(RuleView::action($mysqli, $rule)) ?></p>
        <div class="alert alert-info small"><i class="fas fa-shield-alt me-1"></i>This is a dry run: nothing is written, queued or sent. Actions only report what they would do.</div>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="rule_id" value="<?= (int) $rule['rule_id'] ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Sample event</label>
                    <select class="form-select" name="source" id="src">
                        <option value="ticket" <?= $source === 'ticket' ? 'selected' : '' ?>>A recent ticket</option>
                        <option value="audit" <?= $source === 'audit' ? 'selected' : '' ?>>A recent <?= $h($event) ?> from the audit log (<?= count($recent_audit) ?>)</option>
                        <option value="synthetic" <?= $source === 'synthetic' ? 'selected' : '' ?>>A synthetic event (edit the JSON)</option>
                    </select>
                </div>
                <div class="col-md-8 src-box" data-for="ticket">
                    <label class="form-label">Ticket</label>
                    <select class="form-select" name="sample_ticket"><?php foreach ($recent_tickets as $tid => $label) { ?><option value="<?= (int) $tid ?>" <?= (int) ($_POST['sample_ticket'] ?? 0) === $tid ? 'selected' : '' ?>><?= $h($label) ?></option><?php } ?></select>
                </div>
                <div class="col-md-8 src-box" data-for="audit">
                    <label class="form-label">Audit event</label>
                    <select class="form-select" name="sample_audit"><?php foreach ($recent_audit as $aid => $r) { ?><option value="<?= (int) $aid ?>" <?= (int) ($_POST['sample_audit'] ?? 0) === $aid ? 'selected' : '' ?>><?= $h($r['created_at'] . ' - ' . mb_substr((string) $r['summary'], 0, 80)) ?></option><?php } ?></select>
                </div>
                <div class="col-12 src-box" data-for="synthetic">
                    <label class="form-label">Event data (JSON)</label>
                    <textarea class="form-control font-monospace small" name="synthetic_json" rows="8"><?= $h($sample_json) ?></textarea>
                </div>
            </div>
            <button class="btn btn-primary mt-3" type="submit"><i class="fas fa-vial me-2"></i>Run test</button>
        </form>
<?php if ($error) { ?><div class="alert alert-danger mt-3"><?= $h($error) ?></div><?php } ?>
<?php if ($result) { ?>
        <hr>
        <h5>Result</h5>
        <p><?= $result['matched'] ? '<span class="badge text-bg-success">Conditions match</span>' : '<span class="badge text-bg-secondary">Conditions do not match: the rule would not run</span>' ?></p>
        <?php if ($result['conditions']) { ?><pre class="small border rounded p-2 bg-body-tertiary"><?php foreach ($result['conditions'] as $c) { ?><?= $c['ok'] ? '[ok]  ' : '[no]  ' ?><?= $h($c['text']) ?>
<?php } ?></pre><?php } ?>
        <?php if ($result['guard']) { ?><div class="alert alert-warning"><?= $h($result['guard']) ?></div><?php } ?>
        <?php if ($result['action']) { ?><div class="alert alert-<?= $result['action']['ok'] ? 'success' : 'danger' ?>"><strong>Then:</strong> <?= $h($result['action']['message']) ?></div><?php } ?>
<?php } ?>
<script nonce="<?= $h($csp_nonce ?? '') ?>">
(function () {
    var s = document.getElementById('src');
    function go() { document.querySelectorAll('.src-box').forEach(function (b) { b.style.display = b.getAttribute('data-for') === s.value ? '' : 'none'; }); }
    s.addEventListener('change', go); go();
})();
</script>
<?php } ?>
    </div>
</div>
<?php require_once "../includes/footer.php";
