<?php

/*
 * RivetIT - GET/POST request handler for roles
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../modals/role/role_lib.php';

/**
 * A role's access levels as module name => level, for the audit trail's before/after of a permission change.
 *
 * @return array<string,int>
 */
function itflow_role_levels_snapshot(mysqli $mysqli, int $role_id): array {
    $levels = [];
    $res = mysqli_query($mysqli, "SELECT m.module_name, p.user_role_permission_level AS lvl FROM user_role_permissions p JOIN modules m ON m.module_id = p.module_id WHERE p.user_role_id = $role_id");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $levels[$r['module_name']] = intval($r['lvl']);
    }
    ksort($levels);
    return $levels;
}

/** Writes the structured audit event for a role's permission or admin-flag change (nothing when nothing changed). */
function itflow_audit_role_change(string $event, int $role_id, string $role_name, array $before, array $after, ?bool $was_admin, bool $is_admin): void {
    global $session_user_id;
    $changes = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $module) {
        $from = intval($before[$module] ?? 0);
        $to = intval($after[$module] ?? 0);
        if ($from !== $to) {
            $changes[] = ['module' => $module, 'from' => $from, 'to' => $to];
        }
    }
    $admin_changed = $was_admin !== null && $was_admin !== $is_admin;
    if (!$changes && !$admin_changed) {
        return;
    }
    try {
        \ITFlow\Audit\AuditService::record($event, intval($session_user_id) ?: null, 'role', $role_id, $event === 'role.created' ? 'create' : 'update',
            "Role $role_name: " . count($changes) . ' permission change(s)' . ($admin_changed ? ($is_admin ? ', administrator access GRANTED' : ', administrator access REMOVED') : ''),
            ['role_id' => $role_id, 'role_name' => $role_name, 'admin_before' => $was_admin, 'admin_after' => $is_admin, 'changes' => $changes]);
    } catch (\Throwable $e) {
        // auditing never breaks the action
    }
}

if (isset($_POST['add_role'])) {

    validateCSRFToken($_POST['csrf_token'] ?? null);

    $name = sanitizeInput($_POST['role_name']);
    $description = sanitizeInput($_POST['role_description']);
    $admin = intval($_POST['role_is_admin'] ?? 0) === 1 ? 1 : 0;

    mysqli_query($mysqli, "INSERT INTO user_roles SET role_name = '$name', role_description = '$description', role_is_admin = $admin");

    $role_id = mysqli_insert_id($mysqli);

    // Insert role permissions (only if not admin)
    if ($admin == 0) {
        foreach (itflow_role_posted_levels($mysqli, $_POST) as $module_id => $access_level) {
            mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = $role_id, module_id = $module_id, user_role_permission_level = $access_level");
        }
    }

    logAction("User Role", "Create", "$session_name created user role $name", 0, $role_id);
    itflow_audit_role_change('role.created', intval($role_id), $name, [], itflow_role_levels_snapshot($mysqli, intval($role_id)), null, $admin === 1);

    flash_alert("User Role <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_role'])) {

    validateCSRFToken($_POST['csrf_token'] ?? null);

    $role_id = intval($_POST['role_id']);
    $name = sanitizeInput($_POST['role_name']);
    $description = sanitizeInput($_POST['role_description']);
    $admin = intval($_POST['role_is_admin'] ?? 0) === 1 ? 1 : 0;

    // The last administrator role that still has an active user keeps admin access, whatever
    // the form sent: otherwise nobody could reach Admin (or this page) again.
    $was_admin = intval(getFieldById('user_roles', $role_id, 'role_is_admin')) === 1;
    if ($was_admin && $admin === 0 && itflow_role_other_admin_roles($mysqli, $role_id) === 0) {
        flash_alert("Admin access was not removed: this is the only administrator role with an active user. Give another role admin access (and a user) first.", 'error');
        redirect();
    }

    $levels_before = itflow_role_levels_snapshot($mysqli, $role_id);

    mysqli_query($mysqli, "UPDATE user_roles SET role_name = '$name', role_description = '$description', role_is_admin = $admin WHERE role_id = $role_id");

    // Update role access levels
    mysqli_query($mysqli, "DELETE FROM user_role_permissions WHERE user_role_id = $role_id");
    foreach (itflow_role_posted_levels($mysqli, $_POST) as $module_id => $access_level) {
        mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = $role_id, module_id = $module_id, user_role_permission_level = $access_level");
    }

    logAction("User Role", "Edit", "$session_name edited user role $name", 0, $role_id);
    itflow_audit_role_change('role.permissions_changed', $role_id, $name, $levels_before, itflow_role_levels_snapshot($mysqli, $role_id), $was_admin, $admin === 1);

    flash_alert("User Role <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['archive_role'])) {

    validateCSRFToken($_GET['csrf_token'] ?? null);

    $role_id = intval($_GET['archive_role']);

    // Check role isn't in use
    $sql_role_user_count = mysqli_query($mysqli, "SELECT COUNT(user_id) FROM users WHERE user_role_id = $role_id AND user_archived_at IS NULL");
    $role_user_count = mysqli_fetch_row($sql_role_user_count)[0];
    if ($role_user_count != 0) {
        flash_alert("Role must not in use to archive it", 'error');

        redirect();
    }

    // Never archive the last administrator role (the one other admins would have to be moved to)
    if (intval(getFieldById('user_roles', $role_id, 'role_is_admin')) === 1 && itflow_role_other_admin_roles($mysqli, $role_id) === 0) {
        flash_alert("This is the last administrator role, so it can't be archived.", 'error');

        redirect();
    }

    mysqli_query($mysqli, "UPDATE user_roles SET role_archived_at = NOW() WHERE role_id = $role_id");

    $role_name = sanitizeInput(getFieldById('user_roles', $role_id, 'role_name'));

    logAction("User Role", "Archive", "$session_name archived user role $role_name", 0, $role_id);

    flash_alert("User Role <strong>$role_name</strong> archived", 'error');

    redirect();

}
