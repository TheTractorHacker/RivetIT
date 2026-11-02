<?php
defined('FROM_API') || die();

/**
 * Employee (portal) API: sign-in and token check shared by api/v1/portal_*.php.
 *
 * An employee is a department-portal login (users.user_type = 2, tied to a contact by contacts.contact_user_id). Their token lives in
 * portal_api_tokens (SHA-256 hash only) and is NEVER an api_tokens row, so it cannot reach any staff endpoint, and a staff token cannot
 * reach these. Every request re-proves the login against the database (active, not archived, portal switched on, contact and department
 * not archived), and every query is scoped to the contact and department of the token, never to anything in the request.
 */

/** The signed-in employee for this request, or a 401 / 403. @return array{token_hash:string,user_id:int,contact_id:int,client_id:int,contact_name:string} */
function portal_api_require_employee(): array {
    global $mysqli, $config_client_portal_enable;

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        $h = getallheaders();
        $auth = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+([0-9a-f]{64})$/i', $auth, $m)) {
        header('WWW-Authenticate: Bearer');
        api_error(401, 'Unauthorized');
    }
    $hash = hash('sha256', strtolower($m[1]));
    $esc = mysqli_real_escape_string($mysqli, $hash);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT t.token_id, t.token_user_id, t.token_contact_id, t.token_created_at, t.token_last_used_at,
                c.contact_id, c.contact_name, c.contact_client_id,
                TIMESTAMPDIFF(DAY, COALESCE(t.token_last_used_at, t.token_created_at), NOW()) AS idle_days
         FROM portal_api_tokens t
         JOIN users u ON u.user_id = t.token_user_id AND u.user_type = 2 AND u.user_status = 1 AND u.user_archived_at IS NULL
         JOIN contacts c ON c.contact_id = t.token_contact_id AND c.contact_user_id = u.user_id AND c.contact_archived_at IS NULL
         JOIN clients cl ON cl.client_id = c.contact_client_id AND cl.client_archived_at IS NULL
         WHERE t.token_hash = '$esc' LIMIT 1"));
    if (!$row || intval($config_client_portal_enable ?? 0) !== 1) {
        header('WWW-Authenticate: Bearer');
        api_error(401, 'Unauthorized');
    }
    if (intval($row['idle_days']) > 90) {
        mysqli_query($mysqli, "DELETE FROM portal_api_tokens WHERE token_hash = '$esc'");
        api_error(401, 'Session expired. Please sign in again.');
    }
    mysqli_query($mysqli, "UPDATE portal_api_tokens SET token_last_used_at = NOW() WHERE token_id = " . intval($row['token_id']));

    if (!api_rate_limit('ptok:' . substr($hash, 0, 40), 120, 60)) {
        header('Retry-After: ' . api_rate_limit_retry_after());
        api_error(429, 'Rate limit exceeded');
    }

    return [
        'token_hash' => $hash,
        'user_id' => intval($row['token_user_id']),
        'contact_id' => intval($row['contact_id']),
        'client_id' => intval($row['contact_client_id']),
        'contact_name' => (string) $row['contact_name'],
    ];
}

/** GET only. */
function portal_api_get_only(): void {
    global $method;
    if ($method !== 'GET') {
        header('Allow: GET');
        api_error(405, 'Method not allowed');
    }
}

/** Announcement row to API shape. @param array<string,mixed> $a */
function portal_api_announcement(array $a): array {
    return [
        'id' => intval($a['announcement_id']),
        'title' => (string) $a['announcement_title'],
        'body' => (string) $a['announcement_body'],
        'severity' => (string) $a['announcement_severity'],
        'pinned' => intval($a['announcement_pinned']) === 1,
        'all_departments' => intval($a['announcement_client_id']) === 0,
        'published_at' => (string) $a['announcement_publish_at'],
        'expires_at' => $a['announcement_expires_at'] ?: null,
    ];
}

/** Checklist to API shape (null when the employee has no run). */
function portal_api_checklist(?array $check): ?array {
    if (!$check) {
        return null;
    }
    $tasks = [];
    foreach ($check['tasks'] as $t) {
        $done = in_array($t['status'], ['completed', 'skipped'], true);
        $tasks[] = [
            'id' => intval($t['run_task_id']),
            'title' => (string) $t['title'],
            'owner' => (string) ($t['default_owner'] ?? ''),
            'state' => $done ? 'done' : ($t['task_type'] === 'approval' && $t['approval_status'] === 'pending' ? 'awaiting_approval' : ($t['status'] === 'blocked' ? 'not_started' : 'todo')),
            'required' => intval($t['required'] ?? 0) === 1,
            'due_at' => $t['due_at'] ?: null,
        ];
    }

    return [
        'type' => (string) $check['run']['type'],
        'status' => (string) $check['run']['status'],
        'template' => (string) ($check['run']['template_name'] ?? ''),
        'done' => intval($check['done']),
        'total' => intval($check['total']),
        'tasks' => $tasks,
    ];
}
