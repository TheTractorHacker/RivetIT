<?php

/*
 * RivetIT - POST/GET handler for department (portal) logins of supervisors and managers.
 * Every statement is scoped to user_type = 2 accounts that are linked to a contact; agent (user_type 1)
 * accounts are never touched from here.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

function portalUserRole($value): string
{
    $value = (string) $value;
    return in_array($value, ['none', 'supervisor', 'manager'], true) ? $value : 'none';
}

// The agent role a department login may hold (0 = none): validated against the module-only rule every time.
function portalUserRoleId($value): int
{
    $id = intval($value);
    return $id > 0 && itflow_role_is_portal_assignable($id) ? $id : 0;
}

// A portal login is unique by e-mail among portal accounts (agents may share the address; login.php offers a choice).
function portalEmailTaken(mysqli $mysqli, string $email_esc, int $except_user_id = 0): bool
{
    $r = mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = '$email_esc' AND user_type = 2 AND user_id <> $except_user_id LIMIT 1");
    return $r && mysqli_num_rows($r) > 0;
}

function portalSetForceMfa(mysqli $mysqli, int $user_id, int $force): void
{
    mysqli_query($mysqli, "INSERT INTO user_settings SET user_id = $user_id, user_config_force_mfa = $force ON DUPLICATE KEY UPDATE user_config_force_mfa = $force");
}

if (isset($_POST['add_portal_user'])) {

    validateCSRFToken($_POST['csrf_token']);

    $client_id = intval($_POST['client_id'] ?? 0);
    $contact_id = intval($_POST['contact_id'] ?? 0);
    $name = sanitizeInput($_POST['name'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $title = sanitizeInput($_POST['title'] ?? '');
    $role = portalUserRole($_POST['portal_role'] ?? 'none');
    $agent_role_id = portalUserRoleId($_POST['user_role_id'] ?? 0);
    $password = trim((string) ($_POST['password'] ?? ''));

    $client_ok = $client_id > 0 && mysqli_num_rows(mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_id = $client_id AND client_archived_at IS NULL")) === 1;

    if (!$client_ok || $role === 'none' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        flash_alert("Department, name, a valid email, a role and a password of at least 8 characters are required.", 'error');
        redirect();
    }
    if (portalEmailTaken($mysqli, $email)) {
        flash_alert("A department login with the email <strong>$email</strong> already exists.", 'error');
        redirect();
    }

    if ($contact_id > 0) {
        // Existing person: must belong to the chosen department and must not already have a login.
        $c = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id, contact_user_id, contact_email FROM contacts WHERE contact_id = $contact_id AND contact_client_id = $client_id AND contact_archived_at IS NULL"));
        if (!$c) {
            flash_alert("That person is not in the selected department.", 'error');
            redirect();
        }
        $existing = intval($c['contact_user_id']);
        if ($existing > 0 && mysqli_num_rows(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_id = $existing AND user_type = 2")) === 1) {
            flash_alert("That person already has a login. Use Edit on the list to give them a role.", 'error');
            redirect();
        }
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    mysqli_query($mysqli, "INSERT INTO users SET user_name = '$name', user_email = '$email', user_password = '$password_hash', user_auth_method = 'local', user_type = 2, user_role_id = $agent_role_id");
    $user_id = mysqli_insert_id($mysqli);
    portalSetForceMfa($mysqli, $user_id, isset($_POST['force_mfa']) ? 1 : 0);

    if ($contact_id > 0) {
        $email_sql = $c['contact_email'] === null || $c['contact_email'] === '' ? ", contact_email = '$email'" : '';
        mysqli_query($mysqli, "UPDATE contacts SET contact_user_id = $user_id, contact_portal_role = '$role' $email_sql WHERE contact_id = $contact_id");
    } else {
        mysqli_query($mysqli, "INSERT INTO contacts SET contact_name = '$name', contact_title = '$title', contact_email = '$email', contact_client_id = $client_id, contact_user_id = $user_id, contact_portal_role = '$role'");
        $contact_id = mysqli_insert_id($mysqli);
    }

    logAction("Department Login", "Create", "$session_name created a $role department login for $name", $client_id, $user_id);

    flash_alert("Department login for <strong>$name</strong> created as $role");

    redirect();
}

if (isset($_POST['edit_portal_user'])) {

    validateCSRFToken($_POST['csrf_token']);

    $user_id = intval($_POST['user_id'] ?? 0);
    $name = sanitizeInput($_POST['name'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $title = sanitizeInput($_POST['title'] ?? '');
    $role = portalUserRole($_POST['portal_role'] ?? 'none');
    $agent_role_id = portalUserRoleId($_POST['user_role_id'] ?? 0);
    $new_password = trim((string) ($_POST['new_password'] ?? ''));
    $auth_method = (string) ($_POST['auth_method'] ?? '');
    $oidc_subject = trim((string) ($_POST['oidc_subject'] ?? ''));

    $t = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT users.user_auth_method, users.user_oidc_issuer, users.user_oidc_subject, contacts.contact_id, contacts.contact_client_id
        FROM users INNER JOIN contacts ON contacts.contact_user_id = users.user_id
        WHERE users.user_id = $user_id AND users.user_type = 2 LIMIT 1"));
    if (!$t) {
        flash_alert("Department login not found.", 'error');
        redirect();
    }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_alert("A name and a valid email are required.", 'error');
        redirect();
    }
    if ($new_password !== '' && strlen($new_password) < 8) {
        flash_alert("A new password must be at least 8 characters.", 'error');
        redirect();
    }
    if (!in_array($auth_method, ['local', 'azure', 'oidc'], true)
        || ($auth_method === 'local' && $t['user_auth_method'] !== 'local' && strlen($new_password) < 8)
        || ($auth_method === 'azure' && $t['user_auth_method'] !== 'azure' && empty($config_azure_client_id))
        || ($auth_method === 'oidc' && ($oidc_subject === '' || strlen($oidc_subject) > 255
            || ($t['user_auth_method'] !== 'oidc' && !$config_oidc_enabled)))) {
        flash_alert('Choose an available sign-in method. OpenID Connect needs an immutable subject; switching to local needs a new password.', 'error');
        redirect();
    }
    $oidc_issuer = $auth_method === 'oidc'
        ? ($config_oidc_enabled ? (string) $config_oidc_issuer : (string) ($t['user_oidc_issuer'] ?? ''))
        : null;
    if ($auth_method === 'oidc') {
        $check = $mysqli->prepare("SELECT user_id FROM users WHERE user_oidc_issuer = ? AND user_oidc_subject = ? AND user_id <> ? LIMIT 1");
        $check->bind_param('ssi', $oidc_issuer, $oidc_subject, $user_id);
        $check->execute();
        if ($check->get_result()->num_rows !== 0) {
            flash_alert('That OpenID Connect subject is already linked to a different login.', 'error');
            redirect();
        }
    }
    if (portalEmailTaken($mysqli, $email, $user_id)) {
        flash_alert("A department login with the email <strong>$email</strong> already exists.", 'error');
        redirect();
    }

    $contact_id = intval($t['contact_id']);
    $subject_value = $auth_method === 'oidc' ? $oidc_subject : null;
    $update_user = $mysqli->prepare("UPDATE users SET user_name = ?, user_email = ?, user_role_id = ?,
        user_auth_method = ?, user_oidc_issuer = ?, user_oidc_subject = ? WHERE user_id = ? AND user_type = 2");
    $update_user->bind_param('ssisssi', $name, $email, $agent_role_id, $auth_method, $oidc_issuer, $subject_value, $user_id);
    $update_user->execute();
    mysqli_query($mysqli, "UPDATE contacts SET contact_name = '$name', contact_email = '$email', contact_title = '$title', contact_portal_role = '$role' WHERE contact_id = $contact_id");

    // 2FA requirement only applies to local logins (the form only offers it for those).
    if ($auth_method === 'local') {
        portalSetForceMfa($mysqli, $user_id, isset($_POST['force_mfa']) ? 1 : 0);
    }

    // Local password only; an SSO login has no password to set (the form does not offer the field either).
    if ($new_password !== '' && $auth_method === 'local') {
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        mysqli_query($mysqli, "UPDATE users SET user_password = '$password_hash' WHERE user_id = $user_id AND user_type = 2");
        mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $user_id");
    }
    if ($t['user_auth_method'] !== $auth_method) {
        mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $user_id");
    }

    logAction("Department Login", "Edit", "$session_name edited department login $name (role $role, agent role id $agent_role_id)", intval($t['contact_client_id']), $user_id);

    flash_alert("Department login for <strong>$name</strong> updated");

    redirect();
}

if (isset($_GET['disable_portal_user']) || isset($_GET['activate_portal_user'])) {

    validateCSRFToken($_GET['csrf_token']);

    $enable = isset($_GET['activate_portal_user']);
    $user_id = intval($enable ? $_GET['activate_portal_user'] : $_GET['disable_portal_user']);

    $t = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT users.user_name, contacts.contact_client_id
        FROM users INNER JOIN contacts ON contacts.contact_user_id = users.user_id
        WHERE users.user_id = $user_id AND users.user_type = 2 LIMIT 1"));
    if (!$t) {
        flash_alert("Department login not found.", 'error');
        redirect();
    }
    $name = sanitizeInput($t['user_name']);

    mysqli_query($mysqli, "UPDATE users SET user_status = " . ($enable ? 1 : 0) . " WHERE user_id = $user_id AND user_type = 2");
    if (!$enable) {
        mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $user_id");
    }

    logAction("Department Login", $enable ? "Activate" : "Disable", "$session_name " . ($enable ? "activated" : "disabled") . " department login $name", intval($t['contact_client_id']), $user_id);

    flash_alert("Department login for <strong>$name</strong> " . ($enable ? "activated" : "disabled"), $enable ? 'success' : 'error');

    redirect();
}

if (isset($_GET['disable_portal_2fa'])) {

    validateCSRFToken($_GET['csrf_token']);

    $user_id = intval($_GET['disable_portal_2fa']);

    $t = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT users.user_name, contacts.contact_client_id
        FROM users INNER JOIN contacts ON contacts.contact_user_id = users.user_id
        WHERE users.user_id = $user_id AND users.user_type = 2 LIMIT 1"));
    if (!$t) {
        flash_alert("Department login not found.", 'error');
        redirect();
    }
    $name = sanitizeInput($t['user_name']);

    mysqli_query($mysqli, "UPDATE users SET user_token = NULL WHERE user_id = $user_id AND user_type = 2");
    mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $user_id");

    logAction("Department Login", "Edit", "$session_name disabled 2FA for department login $name", intval($t['contact_client_id']), $user_id);

    flash_alert("2FA disabled for <strong>$name</strong>.", 'warning');

    redirect();
}
