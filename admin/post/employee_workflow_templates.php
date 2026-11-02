<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../includes/event_bus.php';

if (isset($_POST['add_employee_workflow_template'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $name = sanitizeInput($_POST['name']);
    $type = in_array($_POST['type'] ?? '', ['onboarding', 'offboarding', 'role_change', 'transfer'], true) ? $_POST['type'] : 'onboarding';
    $description = sanitizeInput($_POST['description'] ?? '');

    mysqli_query($mysqli, "INSERT INTO workflow_templates SET name = '$name', type = '$type', description = '$description', created_by = $session_user_id");
    $workflow_template_id = mysqli_insert_id($mysqli);

    logAction("Settings", "Create", "$session_name created employee workflow template $name");

    flash_alert("Template <strong>$name</strong> created");
    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_POST['edit_employee_workflow_template'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    $name = sanitizeInput($_POST['name']);
    $type = in_array($_POST['type'] ?? '', ['onboarding', 'offboarding', 'role_change', 'transfer'], true) ? $_POST['type'] : 'onboarding';
    $description = sanitizeInput($_POST['description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    try {
        $defs = \ITFlow\Workflow\TemplateVariables::normalizeDefinitions((array) ($_POST['vars'] ?? []));
    } catch (\InvalidArgumentException $e) {
        flash_alert(nullable_htmlentities($e->getMessage()), 'error');
        redirect("employee_workflow_template_details.php?id=$workflow_template_id");
    }
    $vars_json = $defs ? "'" . mysqli_real_escape_string($mysqli, json_encode($defs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "'" : 'NULL';

    mysqli_query($mysqli, "UPDATE workflow_templates SET name = '$name', type = '$type', description = '$description', is_active = $is_active, variables_json = $vars_json WHERE workflow_template_id = $workflow_template_id");

    logAction("Settings", "Edit", "$session_name edited employee workflow template $name");

    flash_alert("Template <strong>$name</strong> updated");
    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_GET['archive_employee_workflow_template'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_GET['archive_employee_workflow_template']);
    $name = sanitizeInput(getFieldById('workflow_templates', $workflow_template_id, 'name'));

    mysqli_query($mysqli, "UPDATE workflow_templates SET archived_at = NOW() WHERE workflow_template_id = $workflow_template_id");

    logAction("Settings", "Archive", "$session_name archived employee workflow template $name");

    flash_alert("Template <strong>$name</strong> archived", 'error');
    redirect("employee_workflow_templates.php");
}

if (isset($_POST['save_lifecycle_settings'])) {

    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole(); // Old function

    $on = isset($_POST['lifecycle_auto_start']) ? 1 : 0;
    mysqli_query($mysqli, "UPDATE settings SET config_lifecycle_auto_start = $on WHERE company_id = 1");
    // The Odoo provision / disable actions are a separate opt-in (Odoo is a live system); the column exists from DB 2.6.217.
    if (mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `settings` LIKE 'config_lifecycle_odoo_actions'")) > 0) {
        $odoo = isset($_POST['lifecycle_odoo_actions']) ? 1 : 0;
        $before_odoo = (int) mysqli_fetch_row(mysqli_query($mysqli, 'SELECT config_lifecycle_odoo_actions FROM settings WHERE company_id = 1'))[0];
        mysqli_query($mysqli, "UPDATE settings SET config_lifecycle_odoo_actions = $odoo WHERE company_id = 1");
        if ($before_odoo !== $odoo) {
            rivetAudit('settings.lifecycle_odoo_actions_changed', (int) $session_user_id, 'settings', 'config_lifecycle_odoo_actions', 'update', 'Lifecycle workflows may ' . ($odoo ? '' : 'no longer ') . 'change Odoo');
        }
    }

    logAction("Settings", "Edit", "$session_name turned employee lifecycle auto-start " . ($on ? 'on' : 'off'));
    rivetAudit('settings.lifecycle_auto_start_changed', (int) $session_user_id, 'settings', 'config_lifecycle_auto_start', 'update', 'Employee lifecycle auto-start turned ' . ($on ? 'on' : 'off'));

    flash_alert('Saved');
    redirect('employee_workflow_templates.php');
}

if (isset($_POST['add_workflow_preset'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    try {
        $r = \ITFlow\Workflow\LifecyclePresets::addToTemplate($mysqli, $workflow_template_id, (string) ($_POST['preset'] ?? ''));
        logAction("Settings", "Edit", "$session_name added workflow preset " . sanitizeInput($_POST['preset'] ?? '') . " to template $workflow_template_id");
        flash_alert("Preset added: {$r['added']} task(s) added" . ($r['skipped'] ? ", {$r['skipped']} already there" : ''));
    } catch (\InvalidArgumentException $e) {
        flash_alert(nullable_htmlentities($e->getMessage()), 'error');
    }
    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_POST['install_workflow_starter'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    try {
        $r = \ITFlow\Workflow\LifecyclePresets::installStarter($mysqli, (string) ($_POST['starter'] ?? ''), (int) $session_user_id);
        logAction("Settings", "Create", "$session_name installed starter workflow template " . sanitizeInput($_POST['starter'] ?? ''));
        flash_alert($r['created'] ? 'Starter template created (inactive: review it, then switch it on in Edit).' : 'That starter template is already installed.');
        redirect("employee_workflow_template_details.php?id={$r['template_id']}");
    } catch (\InvalidArgumentException $e) {
        flash_alert(nullable_htmlentities($e->getMessage()), 'error');
        redirect('employee_workflow_templates.php');
    }
}
