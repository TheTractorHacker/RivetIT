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

    flash_alert("Template <strong>" . htmlspecialchars(stripslashes($name), ENT_QUOTES) . "</strong> created");
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

    flash_alert("Template <strong>" . htmlspecialchars(stripslashes($name), ENT_QUOTES) . "</strong> updated");
    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_GET['archive_employee_workflow_template'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_GET['archive_employee_workflow_template']);
    $name = getFieldById('workflow_templates', $workflow_template_id, 'name'); // already SQL-escaped

    mysqli_query($mysqli, "UPDATE workflow_templates SET archived_at = NOW() WHERE workflow_template_id = $workflow_template_id");

    logAction("Settings", "Archive", "$session_name archived employee workflow template $name");

    flash_alert("Template <strong>" . htmlspecialchars(stripslashes($name), ENT_QUOTES) . "</strong> archived", 'error');
    redirect("employee_workflow_templates.php");
}

if (isset($_POST['save_hire_leave_workflows'])) {

    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole(); // Old function

    $on = isset($_POST['workflows_hire_leave']) ? 1 : 0;
    mysqli_query($mysqli, "UPDATE settings SET config_workflows_hire_leave = $on WHERE company_id = 1");
    logAction("Settings", "Edit", "$session_name turned onboarding/offboarding workflows " . ($on ? 'on' : 'off'));
    rivetAudit('settings.workflows_hire_leave_changed', (int) $session_user_id, 'settings', 'config_workflows_hire_leave', 'update', 'Onboarding/offboarding workflows turned ' . ($on ? 'on' : 'off'));

    flash_alert('Saved');
    redirect('employee_workflow_templates.php');
}

if (isset($_POST['save_lifecycle_settings'])) {

    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole(); // Old function

    $on = isset($_POST['lifecycle_auto_start']) ? 1 : 0;
    mysqli_query($mysqli, "UPDATE settings SET config_lifecycle_auto_start = $on WHERE company_id = 1");
    // The Odoo provision / disable actions are a separate opt-in (Odoo is a live system); the column exists from DB 2.6.214.
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

if (isset($_POST['save_okta_settings'])) {

    validateCSRFToken($_POST['csrf_token']);
    if (empty($session_is_admin)) {
        flash_alert(WORDING_ROLECHECK_FAILED, 'error');
        redirect('employee_workflow_templates.php');
    }

    if (mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `settings` LIKE 'config_lifecycle_okta_actions'")) === 0) {
        flash_alert('Run the database update first.', 'error');
        redirect('employee_workflow_templates.php');
    }
    $domain = strtolower(trim((string) ($_POST['okta_domain'] ?? '')));
    if ($domain !== '' && !\ITFlow\Workflow\Connectors\OktaConnector::validDomain($domain)) {
        flash_alert('Enter the Okta domain as a bare host name such as example.okta.com (no https://, port or path).', 'error');
        redirect('employee_workflow_templates.php');
    }
    $before = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_lifecycle_okta_actions AS a, config_lifecycle_okta_domain AS d, (config_lifecycle_okta_token IS NOT NULL AND config_lifecycle_okta_token <> \'\') AS t FROM settings WHERE company_id = 1')) ?: [];
    $on = isset($_POST['lifecycle_okta_actions']) ? 1 : 0;
    $token = trim((string) ($_POST['okta_token'] ?? ''));
    $token_sql = '';
    $token_note = 'unchanged';
    if (isset($_POST['okta_clear_token'])) {
        $token_sql = ", config_lifecycle_okta_token = NULL";
        $token_note = 'removed';
    } elseif ($token !== '') {
        $token_sql = ", config_lifecycle_okta_token = '" . mysqli_real_escape_string($mysqli, encryptSetting($token)) . "'";
        $token_note = 'replaced';
    }
    $domain_sql = mysqli_real_escape_string($mysqli, $domain);
    mysqli_query($mysqli, "UPDATE settings SET config_lifecycle_okta_actions = $on, config_lifecycle_okta_domain = " . ($domain === '' ? 'NULL' : "'$domain_sql'") . "$token_sql WHERE company_id = 1");

    // Never the token, not even masked.
    rivetAudit('settings.lifecycle_okta_changed', (int) $session_user_id, 'settings', 'config_lifecycle_okta_actions', 'update', 'Okta workflow actions ' . ($on ? 'allowed' : 'not allowed') . ', API token ' . $token_note, ['allowed_before' => (int) ($before['a'] ?? 0), 'allowed_after' => $on, 'domain_changed' => (string) ($before['d'] ?? '') !== $domain, 'token' => $token_note]);
    logAction("Settings", "Edit", "$session_name saved the Okta workflow settings (actions " . ($on ? 'on' : 'off') . ", token $token_note)");
    flash_alert('Okta settings saved');
    redirect('employee_workflow_templates.php');
}

if (isset($_GET['clone_employee_workflow_template'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_client', 2);

    $src_id = intval($_GET['clone_employee_workflow_template']);
    $src = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM workflow_templates WHERE workflow_template_id = $src_id AND archived_at IS NULL"));
    if (!$src) {
        flash_alert('Workflow template not found', 'error');
        redirect('employee_workflow_templates.php');
    }

    // The copy starts inactive (review it, then switch it on in Edit) and is not a built-in starter, so it never collides with preset_key.
    $copy_name = mb_substr($src['name'], 0, 190) . ' (copy)';
    $stmt = mysqli_prepare($mysqli, "INSERT INTO workflow_templates (name, type, description, is_active, created_by, variables_json) VALUES (?, ?, ?, 0, ?, ?)");
    $by = intval($session_user_id);
    mysqli_stmt_bind_param($stmt, 'sssis', $copy_name, $src['type'], $src['description'], $by, $src['variables_json']);
    mysqli_stmt_execute($stmt);
    $new_id = intval(mysqli_insert_id($mysqli));
    mysqli_stmt_close($stmt);

    if ($new_id > 0) {
        // Copy the tasks in order, then point each copy's "waits for" list at the copies of the tasks it waited for.
        $map = [];
        $res = mysqli_query($mysqli, "SELECT * FROM workflow_template_tasks WHERE workflow_template_id = $src_id ORDER BY sort_order, template_task_id");
        $old_rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
        foreach ($old_rows as $t) {
            $cols = $t;
            unset($cols['template_task_id']);
            $cols['workflow_template_id'] = $new_id;
            $cols['depends_on'] = null;
            $names = [];
            $vals = [];
            foreach ($cols as $c => $v) {
                $names[] = '`' . $c . '`';
                $vals[] = $v === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, (string) $v) . "'";
            }
            mysqli_query($mysqli, 'INSERT INTO workflow_template_tasks (' . implode(',', $names) . ') VALUES (' . implode(',', $vals) . ')');
            $map[(int) $t['template_task_id']] = intval(mysqli_insert_id($mysqli));
        }
        foreach ($old_rows as $t) {
            $deps = \ITFlow\Workflow\DependencyGraph::parse($t['depends_on']);
            $new_deps = array_values(array_filter(array_map(static fn ($d) => $map[$d] ?? 0, $deps)));
            $list = \ITFlow\Workflow\DependencyGraph::format($new_deps);
            if ($list !== null) {
                mysqli_query($mysqli, "UPDATE workflow_template_tasks SET depends_on = '" . mysqli_real_escape_string($mysqli, $list) . "' WHERE template_task_id = " . intval($map[(int) $t['template_task_id']]));
            }
        }
    }

    logAction("Settings", "Create", "$session_name cloned workflow template {$src['name']}");
    flash_alert('Template cloned (inactive). Rename it, adjust the tasks, then switch it on in Edit.');
    redirect("employee_workflow_template_details.php?id=$new_id");
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
