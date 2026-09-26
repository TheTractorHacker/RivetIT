<?php

/*
 * ITFlow - GET/POST request handler for roles
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../modals/role/role_lib.php';

/**
 * The module levels posted by the role form ("<module_id>##<module_name>" => level), checked
 * against the modules table and clamped to 0-3. One level per module.
 *
 * @return array<int, int> module_id => level (levels above 0 only)
 */
function itflow_role_posted_levels(mysqli $db, array $post): array
{
    $valid = [];
    $sql = mysqli_query($db, "SELECT module_id FROM modules");
    while ($row = mysqli_fetch_assoc($sql)) {
        $valid[intval($row['module_id'])] = true;
    }
    $levels = [];
    foreach ($post as $key => $value) {
        if (!is_string($key) || !str_contains($key, '##module_') || is_array($value)) {
            continue;
        }
        $module_id = intval(explode('##', $key)[0]);
        $level = max(0, min(3, intval($value)));
        if (isset($valid[$module_id]) && $level > 0) {
            $levels[$module_id] = $level;
        }
    }
    return $levels;
}

if (isset($_POST['add_role'])) {

    validateCSRFToken($_POST['csrf_token']);

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

    flash_alert("User Role <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_role'])) {

    validateCSRFToken($_POST['csrf_token']);

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

    mysqli_query($mysqli, "UPDATE user_roles SET role_name = '$name', role_description = '$description', role_is_admin = $admin WHERE role_id = $role_id");

    // Update role access levels
    mysqli_query($mysqli, "DELETE FROM user_role_permissions WHERE user_role_id = $role_id");
    foreach (itflow_role_posted_levels($mysqli, $_POST) as $module_id => $access_level) {
        mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = $role_id, module_id = $module_id, user_role_permission_level = $access_level");
    }

    logAction("User Role", "Edit", "$session_name edited user role $name", 0, $role_id);

    flash_alert("User Role <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['archive_role'])) {

    validateCSRFToken($_GET['csrf_token']);

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
