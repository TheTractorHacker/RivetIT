<?php
require_once "includes/inc_all_admin.php";

$sql = mysqli_query($mysqli,
    "SELECT * FROM ticket_automation_rules ORDER BY rule_order ASC, rule_id ASC"
);

// Pre-load category names for display
$category_names = [];
$sql_cats = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL");
while ($c = mysqli_fetch_assoc($sql_cats)) {
    $category_names[intval($c['category_id'])] = $c['category_name'];
}

// Pre-load worksheet template names for display
$worksheet_template_names = [];
$sql_wt = mysqli_query($mysqli, "SELECT worksheet_template_id, worksheet_template_name FROM worksheet_templates WHERE worksheet_template_archived_at IS NULL");
while ($wt = mysqli_fetch_assoc($sql_wt)) {
    $worksheet_template_names[intval($wt['worksheet_template_id'])] = $wt['worksheet_template_name'];
}

// Pre-load RMM script names for display
$rmm_script_names = [];
$sql_scripts = mysqli_query($mysqli, "SELECT id, name FROM rmm_scripts");
while ($s = mysqli_fetch_assoc($sql_scripts)) {
    $rmm_script_names[intval($s['id'])] = $s['name'];
}

$trigger_labels = [
    'schedule'       => 'Scheduled check',
    'ticket_created' => 'Ticket created',
    'rmm_alert'      => 'New RMM alert',
    'asset_offline'  => 'Asset offline',
    'asset_online'   => 'Asset online',
];

$field_labels = [
    'age_hours'      => 'Ticket age (hours)',
    'priority'       => 'Priority',
    'status_id'      => 'Status ID',
    'assigned_to'    => 'Assigned to (user ID)',
    'idle_hours'     => 'Hours since last reply',
    'category'       => 'Ticket category',
    'subject'        => 'Ticket subject',
    'details'        => 'Ticket body',
    'sla_response_breached'   => 'SLA response breached (1/0)',
    'sla_resolution_breached' => 'SLA resolution breached (1/0)',
    'severity'       => 'Alert severity',
    'message'        => 'Alert message',
    'asset_id'       => 'Asset ID',
    'client_id'      => 'Client ID',
    'integration_id' => 'RMM integration ID',
    'hostname'       => 'Asset hostname',
];
$op_labels = [
    'equals'       => '=',
    'not_equals'   => '≠',
    'greater_than' => '>',
    'less_than'    => '<',
    'contains'     => 'contains',
];
$action_labels = [
    'set_priority'             => 'Set priority',
    'assign_to'                => 'Assign to user ID',
    'set_status'               => 'Set status ID',
    'add_note'                 => 'Add automation note',
    'ai_triage'                => 'AI triage (suggest)',
    'notify_assignee'          => 'Notify assigned tech',
    'close_ticket'             => 'Close ticket',
    'add_worksheet'            => 'Add worksheet from template',
    'run_script'               => 'Run RMM script',
    'create_ticket_from_alert' => 'Create ticket from alert',
    'acknowledge_alert'        => 'Acknowledge alert',
];

/*
 * Returns the list of {field,op,value} condition rows for a rule, falling
 * back to the legacy single-condition columns if rule_conditions_json is
 * empty (mirrors includes/automation_functions.php::automationGetConditions).
 */
function ticket_automation_conditions(array $rule): array {
    if (!empty($rule['rule_conditions_json'])) {
        $decoded = json_decode($rule['rule_conditions_json'], true);
        if (is_array($decoded) && !empty($decoded)) return $decoded;
    }
    if (!empty($rule['rule_cond_field'])) {
        return [['field' => $rule['rule_cond_field'], 'op' => $rule['rule_cond_op'], 'value' => $rule['rule_cond_value']]];
    }
    return [];
}

function ticket_automation_actions(array $rule): array {
    if (!empty($rule['rule_actions_json'])) {
        $decoded = json_decode($rule['rule_actions_json'], true);
        if (is_array($decoded) && !empty($decoded)) return $decoded;
    }
    if (!empty($rule['rule_action'])) {
        return [['action' => $rule['rule_action'], 'value' => $rule['rule_action_value']]];
    }
    return [];
}
?>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-robot me-2"></i>Ticket Automation Rules</h3>
        <div class="card-tools">
            <a href="ticket_automation_log.php" class="btn btn-secondary me-2">
                <i class="fas fa-history me-2"></i>Run Log
            </a>
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/ticket_automation/add_rule.php">
                <i class="fas fa-plus me-2"></i>New Rule
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="alert alert-info mx-3 mt-3">
            <i class="fas fa-info-circle me-2"></i>
            Rules run automatically each time cron executes. Schedule cron at <code>cron/cron.php</code> every 15–60 minutes.
        </div>
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>Rule Name</th>
                    <th>Trigger</th>
                    <th>Conditions</th>
                    <th>Actions</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (mysqli_num_rows($sql) === 0): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        <i class="fas fa-robot fa-2x mb-2 d-block"></i>
                        No automation rules yet. Click <strong>New Rule</strong> to add one.
                    </td>
                </tr>
            <?php endif; ?>
            <?php while ($rule = mysqli_fetch_assoc($sql)):
                $rule_id = intval($rule['rule_id']);
                $name    = nullable_htmlentities($rule['rule_name']);
                $enabled = intval($rule['rule_enabled']);
                $trigger = $rule['rule_trigger'] ?: 'schedule';
            ?>
                <tr class="<?php echo $enabled ? '' : 'text-muted'; ?>">
                    <td><?php echo $rule_id; ?></td>
                    <td>
                        <strong><?php echo $name; ?></strong>
                    </td>
                    <td>
                        <span class="badge text-bg-info"><?php echo $trigger_labels[$trigger] ?? nullable_htmlentities($trigger); ?></span>
                    </td>
                    <td>
                        <?php foreach (ticket_automation_conditions($rule) as $cond):
                            $field = $cond['field'] ?? '';
                            $op    = $cond['op'] ?? '';
                            $val   = nullable_htmlentities((string) ($cond['value'] ?? ''));
                            $display_val = $val;
                            if ($field === 'category' && isset($category_names[intval($cond['value'] ?? 0)])) {
                                $display_val = nullable_htmlentities($category_names[intval($cond['value'])]);
                            }
                        ?>
                            <div class="mb-1">
                                <span class="badge text-bg-secondary"><?php echo $field_labels[$field] ?? nullable_htmlentities($field); ?></span>
                                <span><?php echo $op_labels[$op] ?? nullable_htmlentities($op); ?></span>
                                <code><?php echo $display_val; ?></code>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php foreach (ticket_automation_actions($rule) as $act):
                            $action = $act['action'] ?? '';
                            $aval   = nullable_htmlentities((string) ($act['value'] ?? ''));
                            $display_aval = $aval;
                            if ($action === 'add_worksheet' && isset($worksheet_template_names[intval($act['value'] ?? 0)])) {
                                $display_aval = nullable_htmlentities($worksheet_template_names[intval($act['value'])]);
                            }
                            if ($action === 'run_script' && isset($rmm_script_names[intval($act['value'] ?? 0)])) {
                                $display_aval = nullable_htmlentities($rmm_script_names[intval($act['value'])]);
                            }
                        ?>
                            <div class="mb-1">
                                <span class="badge text-bg-primary"><?php echo $action_labels[$action] ?? nullable_htmlentities($action); ?></span>
                                <?php if ($display_aval !== ''): ?>
                                    <code><?php echo $display_aval; ?></code>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if ($enabled): ?>
                            <span class="badge text-bg-success">Enabled</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-secondary ajax-modal" data-modal-url="modals/ticket_automation/edit_rule.php?rule_id=<?php echo $rule_id; ?>">
                            <i class="fas fa-edit"></i>
                        </button>
                        <a href="post.php?toggle=<?php echo $rule_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>"
                           class="btn btn-sm btn-<?php echo $enabled ? 'warning' : 'success'; ?>">
                            <?php echo $enabled ? 'Disable' : 'Enable'; ?>
                        </a>
                        <a href="post.php?delete=<?php echo $rule_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>"
                           class="btn btn-sm btn-danger confirm-link">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
