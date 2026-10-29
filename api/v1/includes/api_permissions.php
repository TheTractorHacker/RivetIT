<?php
defined('FROM_API') || die();

/**
 * Non-fatal version of api_require_module_permission() below - returns a bool instead of
 * ending the request. For endpoints that blend several entity types in one response (e.g.
 * search.php), where one section lacking permission should just be omitted, not fail the
 * whole request the way a hard 403 would.
 */
function api_has_module_permission(mysqli $mysqli, int $api_user_id, string $module_name, int $min_level = 1): bool {
    $role = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT u.user_role_id, r.role_is_admin
         FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id
         WHERE u.user_id = $api_user_id LIMIT 1"
    ));
    if ($role && $role['role_is_admin']) return true;

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

    return (bool) $has_perm;
}

/**
 * Mirrors the web app's enforceUserPermission() for the token-based API context, where
 * $_SESSION isn't populated. Ends the request with a 403 if the signed-in API user's role
 * lacks at least $min_level access to $module_name. Admin roles always pass.
 */
function api_require_module_permission(mysqli $mysqli, int $api_user_id, string $module_name, int $min_level = 1): void {
    if (!api_has_module_permission($mysqli, $api_user_id, $module_name, $min_level)) {
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
    global $mysqli, $api_user_id;
    $uid = intval($api_user_id);
    $sql = "(
        NOT EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $uid LIMIT 1)
        OR EXISTS (SELECT 1 FROM user_client_permissions ucp WHERE ucp.user_id = $uid AND ucp.client_id = $client_id_column LIMIT 1)
    )";
    $key_ids = api_key_allowed_client_ids();
    if ($key_ids !== null) {
        $sql = "($sql AND " . api_key_client_in_sql($client_id_column, $key_ids) . ")";
    }
    return $sql;
}

/**
 * The departments the current legacy API key is limited to: null = unrestricted (or not a legacy key),
 * otherwise a list of client ids (possibly empty = nothing). Uses the $api_key_client_ids set by index.php;
 * if only $api_key_client_id is present it is honoured, and any value below zero (the "several departments"
 * sentinel) without a resolved list denies everything - it is never read as "all".
 *
 * @return list<int>|null
 */
function api_key_allowed_client_ids(): ?array {
    global $api_key_client_id, $api_key_client_ids;
    if (isset($api_key_client_ids) && is_array($api_key_client_ids)) {
        return array_values(array_map('intval', $api_key_client_ids));
    }
    $single = intval($api_key_client_id ?? 0);
    if ($single > 0) {
        return [$single];
    }
    if ($single < 0) {
        return [];
    }
    return null;
}

/** SQL boolean "column is one of these departments" ((1 = 0) for an empty list). The column must be a trusted reference. */
function api_key_client_in_sql(string $column, array $ids): string {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return '(1 = 0)';
    }
    return count($ids) === 1 ? "$column = " . $ids[0] : "$column IN (" . implode(',', $ids) . ")";
}

/** " AND <column restriction>" for the current legacy key, or '' when the key is unrestricted. */
function api_key_client_filter(string $column): string {
    $ids = api_key_allowed_client_ids();
    return $ids === null ? '' : ' AND ' . api_key_client_in_sql($column, $ids);
}

/** Value for the shared report functions' $client_id parameter: null = company-wide, int = one department, array = several (or none). */
function api_key_report_client() {
    $ids = api_key_allowed_client_ids();
    if ($ids === null) {
        return null;
    }
    return count($ids) === 1 ? $ids[0] : $ids;
}

/**
 * Same restriction as api_client_scope_sql(), but for a single already-known
 * client_id value rather than a SQL column reference - used by per-record
 * ownership checks (e.g. a ticket/worksheet/outtake resolved to one client_id)
 * rather than list-query WHERE clauses.
 */
function api_client_scope_ok(int $client_id): bool {
    global $mysqli, $api_user_id;
    $uid = intval($api_user_id);
    $key_ids = api_key_allowed_client_ids();
    if ($key_ids !== null && !in_array($client_id, $key_ids, true)) {
        return false;
    }
    return (bool) mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT 1 AS ok WHERE
            NOT EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $uid LIMIT 1)
            OR EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $uid AND client_id = $client_id LIMIT 1)"
    ));
}
