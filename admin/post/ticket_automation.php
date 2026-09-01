<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Add rule
if (isset($_POST['add_rule'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('user_type', 1);

    $name    = mysqli_real_escape_string($mysqli, trim($_POST['rule_name'] ?? ''));
    $trigger = mysqli_real_escape_string($mysqli, $_POST['rule_trigger'] ?? 'schedule');
    $order   = intval($_POST['rule_order'] ?? 0);

    $valid_triggers = ['schedule', 'rmm_alert', 'asset_offline', 'asset_online', 'ticket_created'];
    if (!in_array($_POST['rule_trigger'] ?? '', $valid_triggers, true)) {
        $trigger = 'schedule';
    }

    // Build conditions array
    $conditions = [];
    $cond_fields = $_POST['cond_field'] ?? [];
    $cond_ops    = $_POST['cond_op'] ?? [];
    $cond_vals   = $_POST['cond_value'] ?? [];
    foreach ($cond_fields as $i => $cf) {
        $cf = trim((string) $cf);
        $cv = trim((string) ($cond_vals[$i] ?? ''));
        if ($cf === '' || $cv === '') continue;
        $conditions[] = [
            'field' => $cf,
            'op'    => trim((string) ($cond_ops[$i] ?? 'equals')),
            'value' => $cv,
        ];
    }

    // Build actions array
    $actions = [];
    $action_names = $_POST['action_name'] ?? [];
    $action_vals  = $_POST['action_value'] ?? [];
    $no_value_actions = ['notify_assignee', 'close_ticket', 'create_ticket_from_alert', 'acknowledge_alert', 'ai_triage'];
    foreach ($action_names as $i => $an) {
        $an = trim((string) $an);
        if ($an === '') continue;
        $av = trim((string) ($action_vals[$i] ?? ''));
        if ($av === '' && !in_array($an, $no_value_actions, true)) continue;
        $actions[] = [
            'action' => $an,
            'value'  => $av,
        ];
    }

    if ($name && !empty($actions) && (!empty($conditions) || $trigger === 'ticket_created')) {
        $conditions_json = mysqli_real_escape_string($mysqli, json_encode($conditions));
        $actions_json    = mysqli_real_escape_string($mysqli, json_encode($actions));

        // Keep legacy single condition/action columns populated for backward
        // compatibility. Conditions can legitimately be empty (e.g. a
        // ticket_created rule meant to match every new ticket), so fall back
        // to blank strings rather than indexing into an empty array.
        $legacy_field  = mysqli_real_escape_string($mysqli, $conditions[0]['field'] ?? '');
        $legacy_op     = mysqli_real_escape_string($mysqli, $conditions[0]['op'] ?? '');
        $legacy_val    = mysqli_real_escape_string($mysqli, $conditions[0]['value'] ?? '');
        $legacy_action = mysqli_real_escape_string($mysqli, $actions[0]['action']);
        $legacy_aval   = mysqli_real_escape_string($mysqli, $actions[0]['value']);

        mysqli_query($mysqli,
            "INSERT INTO ticket_automation_rules
             (rule_name, rule_trigger, rule_cond_field, rule_cond_op, rule_cond_value, rule_conditions_json,
              rule_action, rule_action_value, rule_actions_json, rule_order)
             VALUES ('$name', '$trigger', '$legacy_field', '$legacy_op', '$legacy_val', '$conditions_json',
                     '$legacy_action', '$legacy_aval', '$actions_json', $order)"
        );
        logAction("Automation", "Create", "Created ticket automation rule: $name");
        flash_alert("Automation rule <strong>$name</strong> created.");
    } else {
        flash_alert("Please provide a rule name, at least one condition, and at least one action.", "danger");
    }
    redirect("/admin/ticket_automation.php");
}

// Edit rule
if (isset($_POST['edit_rule'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('user_type', 1);

    $rule_id = intval($_POST['rule_id'] ?? 0);
    $name    = mysqli_real_escape_string($mysqli, trim($_POST['rule_name'] ?? ''));
    $trigger = mysqli_real_escape_string($mysqli, $_POST['rule_trigger'] ?? 'schedule');
    $order   = intval($_POST['rule_order'] ?? 0);

    $valid_triggers = ['schedule', 'rmm_alert', 'asset_offline', 'asset_online', 'ticket_created'];
    if (!in_array($_POST['rule_trigger'] ?? '', $valid_triggers, true)) {
        $trigger = 'schedule';
    }

    // Build conditions array
    $conditions = [];
    $cond_fields = $_POST['cond_field'] ?? [];
    $cond_ops    = $_POST['cond_op'] ?? [];
    $cond_vals   = $_POST['cond_value'] ?? [];
    foreach ($cond_fields as $i => $cf) {
        $cf = trim((string) $cf);
        $cv = trim((string) ($cond_vals[$i] ?? ''));
        if ($cf === '' || $cv === '') continue;
        $conditions[] = [
            'field' => $cf,
            'op'    => trim((string) ($cond_ops[$i] ?? 'equals')),
            'value' => $cv,
        ];
    }

    // Build actions array
    $actions = [];
    $action_names = $_POST['action_name'] ?? [];
    $action_vals  = $_POST['action_value'] ?? [];
    $no_value_actions = ['notify_assignee', 'close_ticket', 'create_ticket_from_alert', 'acknowledge_alert', 'ai_triage'];
    foreach ($action_names as $i => $an) {
        $an = trim((string) $an);
        if ($an === '') continue;
        $av = trim((string) ($action_vals[$i] ?? ''));
        if ($av === '' && !in_array($an, $no_value_actions, true)) continue;
        $actions[] = [
            'action' => $an,
            'value'  => $av,
        ];
    }

    if ($rule_id && $name && !empty($actions) && (!empty($conditions) || $trigger === 'ticket_created')) {
        $conditions_json = mysqli_real_escape_string($mysqli, json_encode($conditions));
        $actions_json    = mysqli_real_escape_string($mysqli, json_encode($actions));

        // Keep legacy single condition/action columns populated for backward
        // compatibility. Conditions can legitimately be empty (e.g. a
        // ticket_created rule meant to match every new ticket), so fall back
        // to blank strings rather than indexing into an empty array.
        $legacy_field  = mysqli_real_escape_string($mysqli, $conditions[0]['field'] ?? '');
        $legacy_op     = mysqli_real_escape_string($mysqli, $conditions[0]['op'] ?? '');
        $legacy_val    = mysqli_real_escape_string($mysqli, $conditions[0]['value'] ?? '');
        $legacy_action = mysqli_real_escape_string($mysqli, $actions[0]['action']);
        $legacy_aval   = mysqli_real_escape_string($mysqli, $actions[0]['value']);

        mysqli_query($mysqli,
            "UPDATE ticket_automation_rules SET
                rule_name = '$name',
                rule_trigger = '$trigger',
                rule_cond_field = '$legacy_field',
                rule_cond_op = '$legacy_op',
                rule_cond_value = '$legacy_val',
                rule_conditions_json = '$conditions_json',
                rule_action = '$legacy_action',
                rule_action_value = '$legacy_aval',
                rule_actions_json = '$actions_json',
                rule_order = $order
             WHERE rule_id = $rule_id"
        );
        logAction("Automation", "Edit", "Edited ticket automation rule: $name");
        flash_alert("Automation rule <strong>$name</strong> updated.");
    } else {
        flash_alert("Please provide a rule name, at least one condition, and at least one action.", "danger");
    }
    redirect("/admin/ticket_automation.php");
}

// Toggle enable/disable
if (isset($_GET['toggle'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('user_type', 1);
    $id = intval($_GET['toggle']);
    mysqli_query($mysqli,
        "UPDATE ticket_automation_rules SET rule_enabled = NOT rule_enabled WHERE rule_id = $id"
    );
    redirect("/admin/ticket_automation.php");
}

// Delete
if (isset($_GET['delete'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('user_type', 1);
    $id = intval($_GET['delete']);
    mysqli_query($mysqli, "DELETE FROM ticket_automation_rules WHERE rule_id = $id");
    logAction("Automation", "Delete", "Deleted ticket automation rule #$id");
    flash_alert("Rule deleted.");
    redirect("/admin/ticket_automation.php");
}

redirect("/admin/ticket_automation.php");
