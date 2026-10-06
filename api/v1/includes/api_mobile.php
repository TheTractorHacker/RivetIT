<?php
defined('FROM_API') || die();

/**
 * Helpers shared by the mobile endpoints (approvals, service_catalog, workflow_tasks, ticket_attachments).
 */

/** JSON request body as an array; a non-object/invalid body is a 400. */
function api_mobile_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        api_error(400, 'Request body must be a JSON object');
    }
    return $body;
}

/** Decisions are attributable to a person, so these endpoints refuse the legacy shared X-Api-Key (it acts as "the first admin"). */
function api_mobile_require_user_token(): void {
    global $legacy_api_key_auth;
    if (!empty($legacy_api_key_auth)) {
        api_error(403, 'This endpoint requires a user API token');
    }
}

/** Is the caller an administrator (role_is_admin)? Same flag the web session exposes as $session_is_admin. */
function api_mobile_is_admin(): bool {
    global $api_access_profile, $api_user_id;
    if (!isset($api_access_profile)) {
        $api_access_profile = itflow_user_access_profile(intval($api_user_id));
    }
    return !empty($api_access_profile['admin']);
}

/**
 * Department access for one client id with the web's enforceClientAccess() semantics: administrators and client 0 (no
 * department) always pass; anyone else needs no user_client_permissions rows at all, or a row for this client.
 */
function api_mobile_client_ok($client_id): bool {
    $client_id = intval($client_id);
    if (api_mobile_is_admin() || $client_id === 0) {
        return true;
    }
    return api_client_scope_ok($client_id);
}

/** SQL boolean for the same rule over a column (e.g. c.contact_client_id, which may be NULL). */
function api_mobile_client_sql(string $column): string {
    if (api_mobile_is_admin()) {
        return '1 = 1';
    }
    return "($column IS NULL OR $column = 0 OR " . api_client_scope_sql($column) . ')';
}

/** web logAction() reads these two globals, which only the web session sets. */
function api_mobile_audit_context(): void {
    global $session_ip, $session_user_agent;
    $session_ip = getIP();
    $session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
}
