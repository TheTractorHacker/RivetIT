<?php
require_once '../../../includes/modal_header.php';

$sql_cats = mysqli_query($mysqli,
    "SELECT category_id, category_name FROM categories
     WHERE category_type = 'Ticket' AND category_archived_at IS NULL
     ORDER BY category_order ASC, category_name ASC"
);
$sql_wt = mysqli_query($mysqli,
    "SELECT worksheet_template_id, worksheet_template_name FROM worksheet_templates
     WHERE worksheet_template_archived_at IS NULL
     ORDER BY worksheet_template_name ASC"
);
$sql_scripts = mysqli_query($mysqli,
    "SELECT id, name FROM rmm_scripts
     WHERE enabled = 1 AND tactical_script_id IS NOT NULL
     ORDER BY name ASC"
);
ob_start();
?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-robot me-2"></i>New Automation Rule</h5>
    <button type="button" class="close" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="/admin/post.php" method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="add_rule" value="1">
    <div class="modal-body">

        <div class="form-group">
            <label>Rule Name <strong class="text-danger">*</strong></label>
            <input type="text" name="rule_name" class="form-control" placeholder="e.g. Add on-site worksheet" maxlength="100" required autofocus>
        </div>

        <div class="form-group">
            <label>Trigger — When to evaluate this rule</label>
            <select name="rule_trigger" class="form-control" id="ruleTrigger">
                <option value="schedule">Scheduled check (runs every cron pass against open tickets)</option>
                <option value="ticket_created">Ticket created (runs once as each new ticket is opened)</option>
                <option value="rmm_alert">New RMM alert received</option>
                <option value="asset_offline">Asset goes offline</option>
                <option value="asset_online">Asset comes back online</option>
            </select>
            <small class="text-muted" id="triggerHint"></small>
        </div>

        <div class="form-group">
            <label>Conditions — ALL must match</label>
            <div id="conditionsWrap">
                <div class="row cond-row mb-2">
                    <div class="col-5">
                        <select name="cond_field[]" class="form-control" id="ruleCondField0"></select>
                    </div>
                    <div class="col-3">
                        <select name="cond_op[]" class="form-control"></select>
                    </div>
                    <div class="col-4">
                        <input type="text" name="cond_value[]" id="condValueText0" class="form-control" placeholder="Value">
                        <select name="cond_value[]" id="condValueCat0" class="form-control" style="display:none;" disabled>
                            <?php while ($c = mysqli_fetch_assoc($sql_cats)): ?>
                                <option value="<?= intval($c['category_id']) ?>"><?= nullable_htmlentities($c['category_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="addCondition"><i class="fas fa-plus me-1"></i>Add condition</button>
        </div>

        <div class="form-group">
            <label>Actions — run in order</label>
            <div id="actionsWrap">
                <div class="row action-row mb-2">
                    <div class="col-6">
                        <select name="action_name[]" class="form-control" id="ruleAction0"></select>
                    </div>
                    <div class="col-6">
                        <input type="text" name="action_value[]" id="actionValueText0" class="form-control"
                               placeholder="e.g. critical | 3 | Ticket is stale — please follow up">
                        <select name="action_value[]" id="actionValueWorksheet0" class="form-control" style="display:none;" disabled>
                            <?php while ($wt = mysqli_fetch_assoc($sql_wt)): ?>
                                <option value="<?= intval($wt['worksheet_template_id']) ?>"><?= nullable_htmlentities($wt['worksheet_template_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                        <select name="action_value[]" id="actionValueScript0" class="form-control" style="display:none;" disabled>
                            <?php while ($s = mysqli_fetch_assoc($sql_scripts)): ?>
                                <option value="<?= intval($s['id']) ?>"><?= nullable_htmlentities($s['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="addAction"><i class="fas fa-plus me-1"></i>Add action</button>
        </div>

        <div class="form-group">
            <label>Order <small class="text-muted">(lower runs first)</small></label>
            <input type="number" name="rule_order" class="form-control" value="0" min="0">
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save Rule</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
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

    var trigger = document.getElementById('ruleTrigger');
    var hint    = document.getElementById('triggerHint');
    var condWrap = document.getElementById('conditionsWrap');
    var actWrap  = document.getElementById('actionsWrap');

    function fillSelect(sel, options) {
        sel.innerHTML = '';
        options.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o[0];
            opt.textContent = o[1];
            sel.appendChild(opt);
        });
    }

    function refreshFieldOptions() {
        var fields = FIELD_OPTIONS[trigger.value] || FIELD_OPTIONS.schedule;
        condWrap.querySelectorAll('select[name="cond_field[]"]').forEach(function (sel) {
            var current = sel.value;
            fillSelect(sel, fields);
            if (fields.some(function (f) { return f[0] === current; })) {
                sel.value = current;
            }
        });
        hint.textContent = TRIGGER_HINTS[trigger.value] || '';
    }

    function updateCondValue(row) {
        var field   = row.querySelector('select[name="cond_field[]"]');
        var text    = row.querySelector('input[name="cond_value[]"]');
        var catSel  = row.querySelector('select.cond-value-cat');
        if (!catSel) return;
        var isCat = field.value === 'category';
        text.style.display   = isCat ? 'none' : '';
        text.disabled        = isCat;
        catSel.style.display = isCat ? '' : 'none';
        catSel.disabled      = !isCat;
    }

    function updateActionValue(row) {
        var action  = row.querySelector('select[name="action_name[]"]');
        var text    = row.querySelector('input[name="action_value[]"]');
        var wsSel   = row.querySelector('select.action-value-ws');
        var scrSel  = row.querySelector('select.action-value-script');

        var isWS  = action.value === 'add_worksheet';
        var isScr = action.value === 'run_script';
        var isNone = action.value === 'notify_assignee' || action.value === 'close_ticket'
                  || action.value === 'create_ticket_from_alert' || action.value === 'acknowledge_alert'
                  || action.value === 'ai_triage';

        text.style.display = (isWS || isScr) ? 'none' : '';
        text.disabled      = (isWS || isScr);
        text.placeholder   = isNone ? 'Not used for this action' : 'Value';
        text.disabled      = text.disabled || isNone;

        if (wsSel) {
            wsSel.style.display = isWS ? '' : 'none';
            wsSel.disabled      = !isWS;
        }
        if (scrSel) {
            scrSel.style.display = isScr ? '' : 'none';
            scrSel.disabled      = !isScr;
        }
    }

    // ----- Condition rows -----
    var condIndex = 0;
    document.getElementById('addCondition').addEventListener('click', function () {
        condIndex++;
        var row = document.createElement('div');
        row.className = 'row cond-row mb-2';
        row.innerHTML =
            '<div class="col-5"><select name="cond_field[]" class="form-control"></select></div>' +
            '<div class="col-3"><select name="cond_op[]" class="form-control"></select></div>' +
            '<div class="col-4"><input type="text" name="cond_value[]" class="form-control" placeholder="Value (use category ID for Ticket category)"></div>';
        condWrap.appendChild(row);
        fillSelect(row.querySelector('select[name="cond_field[]"]'), FIELD_OPTIONS[trigger.value] || FIELD_OPTIONS.schedule);
        fillSelect(row.querySelector('select[name="cond_op[]"]'), OP_OPTIONS);
    });

    // ----- Action rows -----
    var actIndex = 0;
    document.getElementById('addAction').addEventListener('click', function () {
        actIndex++;
        var row = document.createElement('div');
        row.className = 'row action-row mb-2';
        row.innerHTML =
            '<div class="col-6"><select name="action_name[]" class="form-control"></select></div>' +
            '<div class="col-6"><input type="text" name="action_value[]" class="form-control" placeholder="Value (template/script ID where applicable)"></div>';
        actWrap.appendChild(row);
        fillSelect(row.querySelector('select[name="action_name[]"]'), ACTION_OPTIONS);
        row.querySelector('select[name="action_name[]"]').addEventListener('change', function () { updateActionValue(row); });
    });

    // ----- Init first rows -----
    var firstCond = condWrap.querySelector('.cond-row');
    fillSelect(firstCond.querySelector('select[name="cond_field[]"]'), FIELD_OPTIONS.schedule);
    fillSelect(firstCond.querySelector('select[name="cond_op[]"]'), OP_OPTIONS);
    firstCond.querySelector('#condValueCat0').className += ' cond-value-cat';
    firstCond.querySelector('select[name="cond_field[]"]').addEventListener('change', function () { updateCondValue(firstCond); });

    var firstAction = actWrap.querySelector('.action-row');
    fillSelect(firstAction.querySelector('select[name="action_name[]"]'), ACTION_OPTIONS);
    firstAction.querySelector('#actionValueWorksheet0').className += ' action-value-ws';
    firstAction.querySelector('#actionValueScript0').className += ' action-value-script';
    firstAction.querySelector('select[name="action_name[]"]').addEventListener('change', function () { updateActionValue(firstAction); });
    updateActionValue(firstAction);

    trigger.addEventListener('change', refreshFieldOptions);
    refreshFieldOptions();
})();
</script>
<?php require_once '../../../includes/modal_footer.php'; ?>
