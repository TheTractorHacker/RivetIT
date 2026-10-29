<?php

/*
 * RivetIT - POST handler for Administration > Integration clients (machine credentials for the LMS API).
 *
 * Reached through admin/post.php, which loads it only for administrators and only when the Referer is admin/integration_clients.php.
 * Every action: CSRF check, admin check, audit event. A secret is shown once, via the session, on the page the action redirects to;
 * it is never written to a log, a flash message or the database (only its password_hash is).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/** One integration client by id, selected columns from a fixed list, bound parameter. */
function ic_client_row(mysqli $mysqli, int $cid, string $columns): ?array
{
    $st = $mysqli->prepare("SELECT $columns FROM integration_clients WHERE client_id = ?");
    $st->bind_param('i', $cid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Integration\IntegrationScope;
use ITFlow\Integration\LegacyKeys;

$ic_admin_only = static function () use ($session_is_admin) {
    if (empty($session_is_admin)) {
        flash_alert('Administrators only.', 'danger');
        redirect('/admin/integration_clients.php');
    }
};
$ic_scopes_from_post = static function (): array {
    $posted = isset($_POST['scopes']) && is_array($_POST['scopes']) ? array_map('strval', $_POST['scopes']) : [];
    return array_values(array_intersect(IntegrationAuth::knownScopes(), $posted));   // only known scopes (lms:*, directory:read, quality:events, sso, api:<resource>:<action>); no wildcard
};
$ic_expiry_from_post = static function (): ?string {
    $raw = trim((string) ($_POST['expires'] ?? ''));
    if ($raw === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$d || $d->format('Y-m-d') !== $raw || $d <= new DateTimeImmutable('today')) {
        return '';   // invalid
    }
    return $raw . ' 23:59:59';
};
/**
 * The department scope from the form: ['all' => bool, 'ids' => list<int>] or an error string. An explicit choice is required (no default):
 * dept_mode = all (and no department ticked), or dept_mode = selected with at least one non-archived department. Unknown, archived or
 * malformed ids are refused, never dropped silently.
 */
$ic_departments_from_post = static function () use ($mysqli) {
    $mode = (string) ($_POST['dept_mode'] ?? '');
    $posted = $_POST['departments'] ?? [];
    if ($mode !== 'all' && $mode !== 'selected') {
        return 'Choose which departments the client may see: "All departments" or specific departments.';
    }
    if (!is_array($posted)) {
        return 'The department list is not valid.';
    }
    if ($mode === 'all') {
        return $posted === [] ? ['all' => true, 'ids' => []] : 'Choose either "All departments" or specific departments, not both.';
    }
    $ids = IntegrationScope::validIds($mysqli, $posted);
    if ($ids === null) {
        return 'One or more selected departments do not exist or are archived.';
    }
    if ($ids === []) {
        return 'Select at least one department, or choose "All departments".';
    }
    return ['all' => false, 'ids' => $ids];
};
$ic_departments_label = static fn(array $d): string => $d['all'] ? 'all departments' : 'departments [' . implode(',', $d['ids']) . ']';

$ic_ips_from_post = static function (): string {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/api_key_security.php';
    return implode("\n", rivetitApiKeyNetworks((string) ($_POST['allowed_ips'] ?? '')));   // throws InvalidArgumentException when malformed
};

if (isset($_POST['add_integration_client'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $ic_admin_only();

    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $scopes = $ic_scopes_from_post();
    $expires = $ic_expiry_from_post();
    try {
        $ips = $ic_ips_from_post();
    } catch (InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'danger');
        redirect('/admin/integration_clients.php');
    }
    if ($name === '' || mb_strlen($name) > 150 || mb_strlen($description) > 500 || $scopes === [] || $expires === '') {
        flash_alert('Enter a name, choose at least one scope, and use a future expiry date (or leave it blank).', 'danger');
        redirect('/admin/integration_clients.php');
    }
    $dept = $ic_departments_from_post();
    if (is_string($dept)) {
        flash_alert($dept, 'danger');
        redirect('/admin/integration_clients.php');
    }
    $made = IntegrationAuth::createClient($mysqli, $name, $description === '' ? null : $description, $scopes, $ips, $expires, intval($session_user_id), $dept['all'], $dept['ids']);
    logAction('Integration', 'Client Create', "$session_name created integration client $name (" . $made['client_uid'] . ") with scopes: " . implode(' ', $scopes) . '; ' . $ic_departments_label($dept), 0, $made['client_id']);
    $_SESSION['integration_secret_once'] = ['client_uid' => $made['client_uid'], 'name' => $name, 'secret' => $made['secret'], 'event' => 'created'];
    redirect('/admin/integration_clients.php');
}

if (isset($_POST['update_integration_client'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $ic_admin_only();

    $cid = intval($_POST['client_id'] ?? 0);
    $row = ic_client_row($mysqli, $cid, 'client_name, client_status, client_all_departments');
    $scopes = $ic_scopes_from_post();
    $expires = $ic_expiry_from_post();
    try {
        $ips = $ic_ips_from_post();
    } catch (InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'danger');
        redirect('/admin/integration_clients.php');
    }
    if (!$row || $row['client_status'] !== 'active' || $scopes === [] || $expires === '') {
        flash_alert('Could not update the client: choose at least one scope and use a future expiry date (or leave it blank).', 'danger');
        redirect('/admin/integration_clients.php');
    }
    $dept = $ic_departments_from_post();
    if (is_string($dept)) {
        flash_alert($dept, 'danger');
        redirect('/admin/integration_clients.php');
    }
    $dept_before = IntegrationScope::allowedDepartmentIds($mysqli, $row + ['client_id' => $cid]);
    $scope = implode(' ', $scopes);
    $st = $mysqli->prepare('UPDATE integration_clients SET client_scopes = ?, client_allowed_ips = ?, client_expires_at = ? WHERE client_id = ?');
    $ips_db = $ips === '' ? null : $ips;
    $st->bind_param('sssi', $scope, $ips_db, $expires, $cid);
    $st->execute();
    $st->close();
    IntegrationScope::save($mysqli, $cid, $dept['all'], $dept['ids']);
    $dept_was = $dept_before === null ? 'all departments' : 'departments [' . implode(',', $dept_before) . ']';
    logAction('Integration', 'Client Update', "$session_name updated integration client {$row['client_name']}: scopes [$scope], IP allow-list " . ($ips === '' ? 'none' : 'set') . ', expiry ' . ($expires ?? 'none')
        . '; ' . ($dept_was === $ic_departments_label($dept) ? 'departments unchanged (' . $dept_was . ')' : "departments changed from $dept_was to " . $ic_departments_label($dept)), 0, $cid);
    flash_alert('Integration client <strong>' . nullable_htmlentities($row['client_name']) . '</strong> updated. Scope and department changes apply to existing tokens at their next request.');
    redirect('/admin/integration_clients.php');
}

if (isset($_POST['rotate_integration_secret'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $ic_admin_only();

    $cid = intval($_POST['client_id'] ?? 0);
    $overlap = intval($_POST['overlap_hours'] ?? 24);
    $row = ic_client_row($mysqli, $cid, 'client_uid, client_name, client_status');
    if (!$row || $row['client_status'] !== 'active') {
        flash_alert('That client is not active.', 'danger');
        redirect('/admin/integration_clients.php');
    }
    $secret = IntegrationAuth::rotateSecret($mysqli, $cid, $overlap);
    if ($secret === null) {
        flash_alert('This client already has two active secrets. Revoke one (or wait for the older one to expire) before rotating again.', 'danger');
        redirect('/admin/integration_clients.php');
    }
    logAction('Integration', 'Secret Rotate', "$session_name created or rotated the secret of integration client {$row['client_name']} ({$row['client_uid']}); any previous secret keeps working for $overlap hours", 0, $cid);
    $_SESSION['integration_secret_once'] = ['client_uid' => $row['client_uid'], 'name' => $row['client_name'], 'secret' => $secret, 'event' => 'rotated'];
    redirect('/admin/integration_clients.php');
}

if (isset($_POST['revoke_integration_secret'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $ic_admin_only();

    $cid = intval($_POST['client_id'] ?? 0);
    $sid = intval($_POST['secret_id'] ?? 0);
    $row = ic_client_row($mysqli, $cid, 'client_name');
    if ($row && IntegrationAuth::revokeSecret($mysqli, $cid, $sid)) {
        logAction('Integration', 'Secret Revoke', "$session_name revoked secret #$sid of integration client {$row['client_name']}", 0, $cid);
        flash_alert('Secret revoked. Tokens issued with it stop working at their next request.', 'warning');
    } else {
        flash_alert('Secret not found or already revoked.', 'danger');
    }
    redirect('/admin/integration_clients.php');
}

if (isset($_POST['revoke_integration_client'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $ic_admin_only();

    $cid = intval($_POST['client_id'] ?? 0);
    $row = ic_client_row($mysqli, $cid, 'client_uid, client_name, client_status');
    if ($row && $row['client_status'] === 'active') {
        IntegrationAuth::revokeClient($mysqli, $cid);
        logAction('Integration', 'Client Revoke', "$session_name revoked integration client {$row['client_name']} ({$row['client_uid']})", 0, $cid);
        flash_alert('Integration client <strong>' . nullable_htmlentities($row['client_name']) . '</strong> revoked. Its tokens stop working immediately.', 'warning');
    } else {
        flash_alert('Client not found or already revoked.', 'danger');
    }
    redirect('/admin/integration_clients.php');
}

if (isset($_POST['save_legacy_header_policy'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $ic_admin_only();

    // The deprecation switch for the old X-Api-Key header: accept it, or switch it off, optionally with a removal date.
    $enabled = (string) ($_POST['legacy_header_enabled'] ?? '1') !== '0';
    $sunsetRaw = trim((string) ($_POST['legacy_header_sunset'] ?? ''));
    $sunset = null;
    if ($sunsetRaw !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $sunsetRaw);
        if (!$d || $d->format('Y-m-d') !== $sunsetRaw) {
            flash_alert('Use a valid removal date (YYYY-MM-DD) or leave it blank. Nothing was changed.', 'danger');
            redirect('/admin/integration_clients.php');
        }
        $sunset = $sunsetRaw;
    }
    $before = LegacyKeys::policy($mysqli);
    try {
        LegacyKeys::setPolicy($mysqli, $enabled, $sunset);
    } catch (Throwable $e) {
        flash_alert('Could not save: the database has not been updated for the unified API credentials yet (DB 2.6.204).', 'danger');
        redirect('/admin/integration_clients.php');
    }
    logAction('Integration', 'Legacy Header Policy', "$session_name set the deprecated X-Api-Key header to " . ($enabled ? 'accepted' : 'switched off') . ', removal date ' . ($sunset ?? 'none')
        . ' (was ' . ($before['enabled'] ? 'accepted' : 'switched off') . ', ' . ($before['sunset'] ?? 'no date') . ')');
    flash_alert($enabled ? 'The deprecated X-Api-Key header is accepted' . ($sunset ? ' until ' . nullable_htmlentities($sunset) : '') . '.' : 'The deprecated X-Api-Key header is switched off: it now answers 401.', $enabled ? 'success' : 'warning');
    redirect('/admin/integration_clients.php');
}
