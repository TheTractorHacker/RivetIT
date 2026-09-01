<?php
require_once '../../../includes/modal_header.php';

$rule_id = intval($_GET['rule_id'] ?? 0);

$rule = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM ticket_automation_rules WHERE rule_id = $rule_id LIMIT 1"));
if (!$rule) {
    echo "Rule not found.";
    require_once '../../../includes/modal_footer.php';
    exit;
}

// Decode conditions/actions with the same legacy-column fallback as the rule list page.
$conditions = [];
if (!empty($rule['rule_conditions_json'])) {
    $decoded = json_decode($rule['rule_conditions_json'], true);
    if (is_array($decoded) && !empty($decoded)) $conditions = $decoded;
}
if (empty($conditions) && !empty($rule['rule_cond_field'])) {
    $conditions = [['field' => $rule['rule_cond_field'], 'op' => $rule['rule_cond_op'], 'value' => $rule['rule_cond_value']]];
}

$actions = [];
if (!empty($rule['rule_actions_json'])) {
    $decoded = json_decode($rule['rule_actions_json'], true);
    if (is_array($decoded) && !empty($decoded)) $actions = $decoded;
}
if (empty($actions) && !empty($rule['rule_action'])) {
    $actions = [['action' => $rule['rule_action'], 'value' => $rule['rule_action_value']]];
}

$sql_cats = mysqli_query($mysqli,
    "SELECT category_id, category_name FROM categories
     WHERE category_type = 'Ticket' AND category_archived_at IS NULL
     ORDER BY category_order ASC, category_name ASC"
);
$categories = [];
while ($c = mysqli_fetch_assoc($sql_cats)) {
    $categories[] = ['id' => intval($c['category_id']), 'name' => $c['category_name']];
}

$sql_wt = mysqli_query($mysqli,
    "SELECT worksheet_template_id, worksheet_template_name FROM worksheet_templates
     WHERE worksheet_template_archived_at IS NULL
     ORDER BY worksheet_template_name ASC"
);
$worksheet_templates = [];
while ($wt = mysqli_fetch_assoc($sql_wt)) {
    $worksheet_templates[] = ['id' => intval($wt['worksheet_template_id']), 'name' => $wt['worksheet_template_name']];
}

$sql_scripts = mysqli_query($mysqli,
    "SELECT id, name FROM rmm_scripts
     WHERE enabled = 1 AND tactical_script_id IS NOT NULL
     ORDER BY name ASC"
);
$scripts = [];
while ($s = mysqli_fetch_assoc($sql_scripts)) {
    $scripts[] = ['id' => intval($s['id']), 'name' => $s['name']];
}

ob_start();
?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-robot me-2"></i>Editing: <strong><?= nullable_htmlentities($rule['rule_name']) ?></strong></h5>
    <button type="button" class="close" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="/admin/post.php" method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="edit_rule" value="1">
    <input type="hidden" name="rule_id" value="<?= intval($rule['rule_id']) ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Rule Name <strong class="text-danger">*</strong></label>
            <input type="text" name="rule_name" class="form-control" value="<?= nullable_htmlentities($rule['rule_name']) ?>" maxlength="100" required autofocus>
        </div>

        <div class="form-group">
            <label>Trigger — When to evaluate this rule</label>
            <select name="rule_trigger" class="form-control" id="ruleTrigger">
                <?php $cur_trigger = $rule['rule_trigger'] ?: 'schedule'; ?>
                <option value="schedule" <?= $cur_trigger === 'schedule' ? 'selected' : '' ?>>Scheduled check (runs every cron pass against open tickets)</option>
                <option value="ticket_created" <?= $cur_trigger === 'ticket_created' ? 'selected' : '' ?>>Ticket created (runs once as each new ticket is opened)</option>
                <option value="rmm_alert" <?= $cur_trigger === 'rmm_alert' ? 'selected' : '' ?>>New RMM alert received</option>
                <option value="asset_offline" <?= $cur_trigger === 'asset_offline' ? 'selected' : '' ?>>Asset goes offline</option>
                <option value="asset_online" <?= $cur_trigger === 'asset_online' ? 'selected' : '' ?>>Asset comes back online</option>
            </select>
            <small class="text-muted" id="triggerHint"></small>
        </div>

        <div class="form-group">
            <label>Conditions — ALL must match</label>
            <div id="conditionsWrap"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="addCondition"><i class="fas fa-plus me-1"></i>Add condition</button>
        </div>

        <div class="form-group">
            <label>Actions — run in order</label>
            <div id="actionsWrap"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="addAction"><i class="fas fa-plus me-1"></i>Add action</button>
        </div>

        <div class="form-group">
            <label>Order <small class="text-muted">(lower runs first)</small></label>
            <input type="number" name="rule_order" class="form-control" value="<?= intval($rule['rule_order']) ?>" min="0">
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save Rule</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var EXISTING_CONDITIONS = <?= json_encode($conditions) ?>;
    var EXISTING_ACTIONS    = <?= json_encode($actions) ?>;
    var CATEGORIES = <?= json_encode($categories) ?>;
    var WORKSHEET_TEMPLATES = <?= json_encode($worksheet_templates) ?>;
    var SCRIPTS = <?= json_encode($scripts) ?>;

    var FIELD_OPTIONS = {
        schedule: [
            ['age_hours',   'Ticket age (hours)'],
            ['idle_hours',  'Hours since last reply'],
            ['priority',    'Priority'],
            ['status_id',   'Status ID'],
            ['assigned_to', 'Assigned to (user ID)'],
            ['category',    'Ticket category'],
            ['sla_response_breached',   'SLA response breached (1/0)'],
            ['sla_resolution_breached', 'SLA resolution breached (1/0)'],
            ['sla_response_pct',        'SLA response % consumed (0-100)'],
            ['sla_resolution_pct',      'SLA resolution % consumed (0-100)'],
        ],
        ticket_created: [
            ['priority',  'Priority'],
            ['category',  'Ticket category'],
            ['client_id', 'Client ID'],
            ['subject',   'Ticket subject (contains)'],
            ['details',   'Ticket body (contains)'],
        ],
        rmm_alert: [
            ['severity',       'Alert severity'],
            ['message',        'Alert message (contains)'],
            ['asset_id',       'Asset ID'],
            ['client_id',      'Client ID'],
            ['integration_id', 'RMM integration ID'],
            ['hostname',       'Asset hostname'],
        ],
        asset_offline: [
            ['asset_id',       'Asset ID'],
            ['client_id',      'Client ID'],
            ['integration_id', 'RMM integration ID'],
            ['hostname',       'Asset hostname'],
        ],
        asset_online: [
            ['asset_id',       'Asset ID'],
            ['client_id',      'Client ID'],
            ['integration_id', 'RMM integration ID'],
            ['hostname',       'Asset hostname'],
        ],
    };

    var TRIGGER_HINTS = {
        schedule:      'Evaluated against every open ticket on each cron run.',
        ticket_created: 'Evaluated once the moment each new ticket is created (agent, email, API or RMM alert). Leave conditions empty to match every new ticket.',
        rmm_alert:     'Evaluated once for each new RMM alert. Use "Create ticket from alert" to open a ticket before running ticket-based actions.',
        asset_offline: 'Evaluated once when an asset\'s RMM status changes to offline.',
        asset_online:  'Evaluated once when an asset\'s RMM status changes to online.',
    };

    var OP_OPTIONS = [
        ['equals',       '= equals'],
        ['not_equals',   '≠ not equals'],
        ['greater_than', '> greater than'],
        ['less_than',    '< less than'],
        ['contains',     'contains'],
    ];

    var ACTION_OPTIONS = [
        ['set_priority',           'Set ticket priority (low / medium / high / critical)'],
        ['assign_to',               'Assign ticket to user ID'],
        ['escalate',                'Escalate ticket (reassign + bump priority: userID:priority)'],
        ['set_status',               'Set ticket status ID'],
        ['add_note',                 'Add automation note to ticket'],
        ['ai_triage',                'AI triage — suggest category/priority/assignee (posts a note)'],
        ['notify_assignee',          'Notify assigned technician'],
        ['close_ticket',              'Close ticket'],
        ['add_worksheet',             'Add worksheet from template to ticket'],
        ['run_script',                'Run RMM script on asset'],
        ['create_ticket_from_alert',  'Create ticket from RMM alert'],
        ['acknowledge_alert',         'Acknowledge RMM alert'],
    ];

    var NO_VALUE_ACTIONS = ['notify_assignee', 'close_ticket', 'create_ticket_from_alert', 'acknowledge_alert', 'ai_triage'];

    var trigger  = document.getElementById('ruleTrigger');
    var hint     = document.getElementById('triggerHint');
    var condWrap = document.getElementById('conditionsWrap');
    var actWrap  = document.getElementById('actionsWrap');

    function fillSelect(sel, options, selected) {
        sel.innerHTML = '';
        options.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o[0];
            opt.textContent = o[1];
            if (selected !== undefined && String(o[0]) === String(selected)) opt.selected = true;
            sel.appendChild(opt);
        });
    }

    function refreshFieldOptions() {
        var fields = FIELD_OPTIONS[trigger.value] || FIELD_OPTIONS.schedule;
        condWrap.querySelectorAll('.cond-row').forEach(function (row) {
            var sel = row.querySelector('select[name="cond_field[]"]');
            var current = sel.value;
            fillSelect(sel, fields, current);
            updateCondValue(row);
        });
        hint.textContent = TRIGGER_HINTS[trigger.value] || '';
    }

    function updateCondValue(row) {
        var field  = row.querySelector('select[name="cond_field[]"]');
        var text   = row.querySelector('input[name="cond_value[]"]');
        var catSel = row.querySelector('select.cond-value-cat');
        if (!catSel) return;
        var isCat = field.value === 'category';
        text.style.display   = isCat ? 'none' : '';
        text.disabled        = isCat;
        catSel.style.display = isCat ? '' : 'none';
        catSel.disabled      = !isCat;
    }

    function updateActionValue(row) {
        var action = row.querySelector('select[name="action_name[]"]');
        var text   = row.querySelector('input[name="action_value[]"]');
        var wsSel  = row.querySelector('select.action-value-ws');
        var scrSel = row.querySelector('select.action-value-script');

        var isWS   = action.value === 'add_worksheet';
        var isScr  = action.value === 'run_script';
        var isNone = NO_VALUE_ACTIONS.indexOf(action.value) !== -1;

        text.style.display = (isWS || isScr) ? 'none' : '';
        text.placeholder   = isNone ? 'Not used for this action' : 'Value';
        text.disabled      = isWS || isScr || isNone;

        if (wsSel) {
            wsSel.style.display = isWS ? '' : 'none';
            wsSel.disabled      = !isWS;
        }
        if (scrSel) {
            scrSel.style.display = isScr ? '' : 'none';
            scrSel.disabled      = !isScr;
        }
    }

    function addConditionRow(prefill) {
        var row = document.createElement('div');
        row.className = 'row cond-row mb-2';
        row.innerHTML =
            '<div class="col-5"><select name="cond_field[]" class="form-control"></select></div>' +
            '<div class="col-3"><select name="cond_op[]" class="form-control"></select></div>' +
            '<div class="col-4">' +
                '<input type="text" name="cond_value[]" class="form-control" placeholder="Value (use category ID for Ticket category)">' +
                '<select name="cond_value[]" class="form-control cond-value-cat" style="display:none;" disabled></select>' +
            '</div>';
        condWrap.appendChild(row);

        var fieldSel = row.querySelector('select[name="cond_field[]"]');
        var opSel    = row.querySelector('select[name="cond_op[]"]');
        var catSel   = row.querySelector('select.cond-value-cat');
        var textInp  = row.querySelector('input[name="cond_value[]"]');

        fillSelect(fieldSel, FIELD_OPTIONS[trigger.value] || FIELD_OPTIONS.schedule, prefill && prefill.field);
        fillSelect(opSel, OP_OPTIONS, prefill && prefill.op);
        fillSelect(catSel, CATEGORIES.map(function (c) { return [c.id, c.name]; }), prefill && prefill.value);

        if (prefill) {
            textInp.value = prefill.value !== undefined ? prefill.value : '';
        }

        fieldSel.addEventListener('change', function () { updateCondValue(row); });
        updateCondValue(row);
    }

    function addActionRow(prefill) {
        var row = document.createElement('div');
        row.className = 'row action-row mb-2';
        row.innerHTML =
            '<div class="col-6"><select name="action_name[]" class="form-control"></select></div>' +
            '<div class="col-6">' +
                '<input type="text" name="action_value[]" class="form-control" placeholder="Value (template/script ID where applicable)">' +
                '<select name="action_value[]" class="form-control action-value-ws" style="display:none;" disabled></select>' +
                '<select name="action_value[]" class="form-control action-value-script" style="display:none;" disabled></select>' +
            '</div>';
        actWrap.appendChild(row);

        var actionSel = row.querySelector('select[name="action_name[]"]');
        var wsSel     = row.querySelector('select.action-value-ws');
        var scrSel    = row.querySelector('select.action-value-script');
        var textInp   = row.querySelector('input[name="action_value[]"]');

        fillSelect(actionSel, ACTION_OPTIONS, prefill && prefill.action);
        fillSelect(wsSel, WORKSHEET_TEMPLATES.map(function (w) { return [w.id, w.name]; }), prefill && prefill.value);
        fillSelect(scrSel, SCRIPTS.map(function (s) { return [s.id, s.name]; }), prefill && prefill.value);

        if (prefill) {
            textInp.value = prefill.value !== undefined ? prefill.value : '';
        }

        actionSel.addEventListener('change', function () { updateActionValue(row); });
        updateActionValue(row);
    }

    document.getElementById('addCondition').addEventListener('click', function () { addConditionRow(); });
    document.getElementById('addAction').addEventListener('click', function () { addActionRow(); });

    // ----- Seed rows from the rule being edited (falls back to one blank row each) -----
    if (EXISTING_CONDITIONS.length) {
        EXISTING_CONDITIONS.forEach(function (c) { addConditionRow(c); });
    } else {
        addConditionRow();
    }
    if (EXISTING_ACTIONS.length) {
        EXISTING_ACTIONS.forEach(function (a) { addActionRow(a); });
    } else {
        addActionRow();
    }

    trigger.addEventListener('change', refreshFieldOptions);
    hint.textContent = TRIGGER_HINTS[trigger.value] || '';
})();
</script>
<?php require_once '../../../includes/modal_footer.php'; ?>
