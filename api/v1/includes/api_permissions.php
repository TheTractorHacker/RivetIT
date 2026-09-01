<?php
defined('FROM_API') || die();

/**
 * Mirrors the web app's enforceUserPermission() for the token-based API context, where
 * $_SESSION isn't populated. Ends the request with a 403 if the signed-in API user's role
 * lacks at least $min_level access to $module_name. Admin roles always pass.
 */
function api_require_module_permission(mysqli $mysqli, int $api_user_id, string $module_name, int $min_level = 1): void {
    $role = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT u.user_role_id, r.role_is_admin
         FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id
         WHERE u.user_id = $api_user_id LIMIT 1"
    ));
    if ($role && $role['role_is_admin']) return;

    $module_esc = mysqli_real_escape_string($mysqli, $module_name);
    $has_perm = $role ? mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT urp.user_role_permission_level
         FROM user_role_permissions urp
         JOIN modules m ON m.module_id = urp.module_id
         WHERE urp.user_role_id = {$role['user_role_id']}
           AND m.module_name = '$module_esc'
           AND urp.user_role_permission_level >= $min_level
         LIMIT 1"
    )) : null;

    if (!$has_perm) {
        api_error(403, 'Insufficient permissions');
    }
}

/**
 * Builds the client-scope SQL boolean expression for the current API caller,
 * for use in a list query's WHERE clause. Combines two independent
 * restrictions:
 *  - the resolved user's own user_client_permissions rows (fail-open to "all
 *    clients" when the user has none, matching enforceClientAccess()'s
 *    behavior for the classic web app)
 *  - if authenticated via a legacy API key scoped to one client
 *    ($api_key_client_id, set in index.php), that key's own restriction -
 *    previously never enforced by any endpoint regardless of what an admin
 *    picked in the key's "Client Access" setting.
 *
 * $client_id_column must be a trusted SQL column reference (e.g.
 * 't.ticket_client_id'), never raw user input.
 */
function api_client_scope_sql(string $client_id_column): string {
    global $mysqli, $api_user_id, $api_key_client_id;
    $uid = intval($api_user_id);
    $sql = "(
        NOT EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $uid LIMIT 1)
        OR EXISTS (SELECT 1 FROM user_client_permissions ucp WHERE ucp.user_id = $uid AND ucp.client_id = $client_id_column LIMIT 1)
    )";
    if (!empty($api_key_client_id)) {
        $restrict = intval($api_key_client_id);
        $sql = "($sql AND $client_id_column = $restrict)";
    }
    return $sql;
}

/**
 * Same restriction as api_client_scope_sql(), but for a single already-known
 * client_id value rather than a SQL column reference - used by per-record
 * ownership checks (e.g. a ticket/worksheet/outtake resolved to one client_id)
 * rather than list-query WHERE clauses.
 */
function api_client_scope_ok(int $client_id): bool {
    global $mysqli, $api_user_id, $api_key_client_id;
    $uid = intval($api_user_id);
    if (!empty($api_key_client_id) && intval($api_key_client_id) !== $client_id) {
        return false;
    }
    return (bool) mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT 1 AS ok WHERE
            NOT EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $uid LIMIT 1)
            OR EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $uid AND client_id = $client_id LIMIT 1)"
    ));
}
