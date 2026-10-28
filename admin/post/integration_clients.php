<?php

/*
 * RivetIT - POST handler for Administration > Integration clients (machine credentials for the LMS API).
 *
 * Reached through admin/post.php, which loads it only for administrators and only when the Referer is admin/integration_clients.php.
 * Every action: CSRF check, admin check, audit event. A secret is shown once, via the session, on the page the action redirects to;
 * it is never written to a log, a flash message or the database (only its password_hash is).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Integration\IntegrationAuth;

$ic_admin_only = static function () use ($session_is_admin) {
    if (empty($session_is_admin)) {
        flash_alert('Administrators only.', 'danger');
        redirect('/admin/integration_clients.php');
    }
};
$ic_scopes_from_post = static function (): array {
    $posted = isset($_POST['scopes']) && is_array($_POST['scopes']) ? array_map('strval', $_POST['scopes']) : [];
    return array_values(array_intersect(IntegrationAuth::SCOPES, $posted));   // only known scopes; no wildcard
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
    $made = IntegrationAuth::createClient($mysqli, $name, $description === '' ? null : $description, $scopes, $ips, $expires, intval($session_user_id));
    logAction('Integration', 'Client Create', "$session_name created integration client $name (" . $made['client_uid'] . ") with scopes: " . implode(' ', $scopes), 0, $made['client_id']);
    $_SESSION['integration_secret_once'] = ['client_uid' => $made['client_uid'], 'name' => $name, 'secret' => $made['secret'], 'event' => 'created'];
    redirect('/admin/integration_clients.php');
}

if (isset($_POST['update_integration_client'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $ic_admin_only();

    $cid = intval($_POST['client_id'] ?? 0);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name, client_status FROM integration_clients WHERE client_id = $cid"));
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
    $scope = implode(' ', $scopes);
    $st = $mysqli->prepare('UPDATE integration_clients SET client_scopes = ?, client_allowed_ips = ?, client_expires_at = ? WHERE client_id = ?');
    $ips_db = $ips === '' ? null : $ips;
    $st->bind_param('sssi', $scope, $ips_db, $expires, $cid);
    $st->execute();
    $st->close();
    logAction('Integration', 'Client Update', "$session_name updated integration client {$row['client_name']}: scopes [$scope], IP allow-list " . ($ips === '' ? 'none' : 'set') . ', expiry ' . ($expires ?? 'none'), 0, $cid);
    flash_alert('Integration client <strong>' . nullable_htmlentities($row['client_name']) . '</strong> updated. Scope changes apply to existing tokens at their next request.');
    redirect('/admin/integration_clients.php');
}

if (isset($_POST['rotate_integration_secret'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $ic_admin_only();

    $cid = intval($_POST['client_id'] ?? 0);
    $overlap = intval($_POST['overlap_hours'] ?? 24);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_uid, client_name, client_status FROM integration_clients WHERE client_id = $cid"));
    if (!$row || $row['client_status'] !== 'active') {
        flash_alert('That client is not active.', 'danger');
        redirect('/admin/integration_clients.php');
    }
    $secret = IntegrationAuth::rotateSecret($mysqli, $cid, $overlap);
    if ($secret === null) {
        flash_alert('This client already has two active secrets. Revoke one (or wait for the older one to expire) before rotating again.', 'danger');
        redirect('/admin/integration_clients.php');
    }
    logAction('Integration', 'Secret Rotate', "$session_name rotated the secret of integration client {$row['client_name']} ({$row['client_uid']}); the previous secret keeps working for $overlap hours", 0, $cid);
    $_SESSION['integration_secret_once'] = ['client_uid' => $row['client_uid'], 'name' => $row['client_name'], 'secret' => $secret, 'event' => 'rotated'];
    redirect('/admin/integration_clients.php');
}

if (isset($_POST['revoke_integration_secret'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $ic_admin_only();

    $cid = intval($_POST['client_id'] ?? 0);
    $sid = intval($_POST['secret_id'] ?? 0);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name FROM integration_clients WHERE client_id = $cid"));
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
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_uid, client_name, client_status FROM integration_clients WHERE client_id = $cid"));
    if ($row && $row['client_status'] === 'active') {
        IntegrationAuth::revokeClient($mysqli, $cid);
        logAction('Integration', 'Client Revoke', "$session_name revoked integration client {$row['client_name']} ({$row['client_uid']})", 0, $cid);
        flash_alert('Integration client <strong>' . nullable_htmlentities($row['client_name']) . '</strong> revoked. Its tokens stop working immediately.', 'warning');
    } else {
        flash_alert('Client not found or already revoked.', 'danger');
    }
    redirect('/admin/integration_clients.php');
}
