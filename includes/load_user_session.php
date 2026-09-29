<?php

$session_ip = sanitizeInput(getIP());
$session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT']);
$session_user_id = intval($_SESSION['user_id']);

$sql = mysqli_query(
    $mysqli,
    "SELECT * FROM users
     LEFT JOIN user_settings ON users.user_id = user_settings.user_id
     LEFT JOIN user_roles ON user_role_id = role_id
     WHERE users.user_id = $session_user_id"
);

$row = mysqli_fetch_assoc($sql);

$session_name = sanitizeInput($row['user_name']);
$session_email = $row['user_email'];
$session_avatar = $row['user_avatar'];
$session_token = $row['user_token'];
$session_user_type = intval($row['user_type']);
$session_user_archived_at = $row['user_archived_at'];
$session_user_status = intval($row['user_status']);
$session_user_role = intval($row['user_role_id']);
$session_user_role_display = sanitizeInput($row['role_name']);
$session_is_admin = isset($row['role_is_admin']) && $row['role_is_admin'] == 1;
$session_user_config_force_mfa = intval($row['user_config_force_mfa']);
$user_config_records_per_page = intval($row['user_config_records_per_page']);
$user_config_theme_dark = intval($row['user_config_theme_dark']);

// Check user type is agent aka 1. The one exception: a department (portal) login (type 2) that an admin gave a
// module-only role (Admin > Users > Department logins) may open the agent modules that role holds (the LMS).
// It must be a real portal login, on a non-admin role with none of the full-agent modules, and a login that
// still owes its required 2FA enrollment goes back to the portal profile first. Type 1 is unchanged.
if ($session_user_type === 2 && !empty($_SESSION['client_logged_in']) && $session_user_role > 0
    && empty($session_is_admin) && itflow_role_is_portal_assignable($session_user_role)) {
    if ($session_user_config_force_mfa === 1 && empty($session_token)) {
        redirect("/client/profile.php");
    }
    // The agent Account pages are for agents; a department login manages its account in the portal.
    if (strpos(itflow_request_script(), '/agent/user/') === 0) {
        redirect("/client/profile.php");
    }
} elseif ($session_user_type !== 1) {
    session_unset();
    session_destroy();
    redirect("/login.php");
}

// Check User is active
if ($session_user_status !== 1) {
    session_unset();
    session_destroy();
    redirect("/login.php");
}

// Check User is archived
if ($session_user_archived_at !== null) {
    session_unset();
    session_destroy();
    redirect("/login.php");
}

// Load user client permissions
$user_client_access_sql = "SELECT client_id FROM user_client_permissions WHERE user_id = $session_user_id";
$user_client_access_result = mysqli_query($mysqli, $user_client_access_sql);

$client_access_array = [];
while ($row = mysqli_fetch_assoc($user_client_access_result)) {
    $client_access_array[] = $row['client_id'];
}

$client_access_string = implode(',', $client_access_array);
$access_permission_query = "";
if ($client_access_string && !$session_is_admin) {
    $access_permission_query = "AND clients.client_id IN ($client_access_string)";
}
