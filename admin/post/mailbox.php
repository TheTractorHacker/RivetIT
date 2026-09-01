<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (!defined('MICROSOFT_OAUTH_BASE_URL')) {
    define('MICROSOFT_OAUTH_BASE_URL', 'https://login.microsoftonline.com/');
}

// Allowed enum values (mirrors the mailboxes table's column definitions)
$mailbox_allowed_types = ['standard_imap', 'google_oauth', 'microsoft_oauth'];
$mailbox_allowed_encryptions = ['', 'tls', 'ssl'];

// Returns a fresh Microsoft Graph access token for a connected mailbox row, refreshing via
// its stored refresh token if the cached access token is missing/expired. Used by the
// shared-mailbox access checker below (Graph has no API to *discover* delegate access, so
// this reuses an already-connected identity's token to test specific candidate addresses).
function mailboxGetFreshMicrosoftAccessToken(array $mailbox): ?string {
    global $mysqli, $config_mail_oauth_client_id, $config_mail_oauth_client_secret, $config_mail_oauth_tenant_id;

    $mailbox_id = intval($mailbox['mailbox_id']);
    $refresh_token = decryptSetting($mailbox['mailbox_oauth_refresh_token_enc'] ?? '');
    $access_token = decryptSetting($mailbox['mailbox_oauth_access_token_enc'] ?? '');
    $expires_at = $mailbox['mailbox_oauth_access_token_expires_at'] ?? null;

    $is_expired = empty($expires_at) || (strtotime((string) $expires_at) - 60) <= time();
    if (!empty($access_token) && !$is_expired) {
        return $access_token;
    }

    if (empty($config_mail_oauth_client_id) || empty($config_mail_oauth_client_secret) || empty($config_mail_oauth_tenant_id) || empty($refresh_token)) {
        return null;
    }

    $token_url = MICROSOFT_OAUTH_BASE_URL . rawurlencode($config_mail_oauth_tenant_id) . '/oauth2/v2.0/token';

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $config_mail_oauth_client_id,
        'client_secret' => $config_mail_oauth_client_secret,
        'refresh_token' => $refresh_token,
        'grant_type' => 'refresh_token',
    ], '', '&'));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['access_token'])) {
        return null;
    }

    $new_access_token = $json['access_token'];
    $new_expires_at = date('Y-m-d H:i:s', time() + (int) ($json['expires_in'] ?? 3600));

    $token_esc = mysqli_real_escape_string($mysqli, encryptSetting($new_access_token));
    $expires_esc = mysqli_real_escape_string($mysqli, $new_expires_at);
    mysqli_query($mysqli, "UPDATE mailboxes SET mailbox_oauth_access_token_enc = '$token_esc', mailbox_oauth_access_token_expires_at = '$expires_esc' WHERE mailbox_id = $mailbox_id");

    return $new_access_token;
}

if (isset($_POST['add_mailbox'])) {

    validateCSRFToken($_POST['csrf_token']);

    $name = sanitizeInput($_POST['mailbox_name']);
    $email = sanitizeInput(filter_var($_POST['mailbox_email'], FILTER_VALIDATE_EMAIL));
    $from_name = sanitizeInput($_POST['mailbox_from_name'] ?? '');

    $type = in_array($_POST['mailbox_type'] ?? '', $mailbox_allowed_types, true) ? $_POST['mailbox_type'] : 'standard_imap';
    $type = sanitizeInput($type);

    $imap_username = sanitizeInput($_POST['mailbox_imap_username'] ?? '');
    $imap_host = sanitizeInput($_POST['mailbox_imap_host'] ?? '');

    $imap_port_raw = trim($_POST['mailbox_imap_port'] ?? '');
    $imap_port_sql = ($imap_port_raw !== '') ? intval($imap_port_raw) : 'NULL';

    $imap_encryption = in_array($_POST['mailbox_imap_encryption'] ?? '', $mailbox_allowed_encryptions, true) ? $_POST['mailbox_imap_encryption'] : '';
    $imap_encryption = sanitizeInput($imap_encryption);

    $imap_password_raw = trim($_POST['mailbox_imap_password'] ?? '');
    $imap_password_sql = 'NULL';
    if ($type === 'standard_imap' && $imap_password_raw !== '') {
        $imap_password_sql = "'" . mysqli_real_escape_string($mysqli, encryptSetting($imap_password_raw)) . "'";
    }

    $parse_unknown_senders = isset($_POST['mailbox_parse_unknown_senders']) ? 1 : 0;

    $default_client_id_raw = intval($_POST['mailbox_default_client_id'] ?? 0);
    $default_client_sql = 'NULL';
    if ($default_client_id_raw > 0) {
        $valid_client = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_id = $default_client_id_raw AND client_archived_at IS NULL LIMIT 1"));
        if ($valid_client) {
            $default_client_sql = $default_client_id_raw;
        }
    }

    $active = isset($_POST['mailbox_active']) ? 1 : 0;

    if (empty($name) || empty($email)) {
        flash_alert("Mailbox name and a valid email address are required", 'error');
        redirect();
    }

    $order = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(mailbox_order),0)+1 FROM mailboxes"))[0]);

    $name_sql = trim($from_name) !== '' ? "'$from_name'" : 'NULL';
    $host_sql = trim($imap_host) !== '' ? "'$imap_host'" : 'NULL';
    $username_sql = trim($imap_username) !== '' ? "'$imap_username'" : 'NULL';

    mysqli_query($mysqli, "INSERT INTO mailboxes SET
        mailbox_name = '$name',
        mailbox_email = '$email',
        mailbox_from_name = $name_sql,
        mailbox_type = '$type',
        mailbox_imap_host = $host_sql,
        mailbox_imap_port = $imap_port_sql,
        mailbox_imap_encryption = '$imap_encryption',
        mailbox_imap_username = $username_sql,
        mailbox_imap_password_enc = $imap_password_sql,
        mailbox_parse_unknown_senders = $parse_unknown_senders,
        mailbox_default_client_id = $default_client_sql,
        mailbox_active = $active,
        mailbox_order = $order,
        mailbox_created_at = NOW()
    ");

    $new_mailbox_id = intval(mysqli_insert_id($mysqli));

    logAction("Mailbox", "Create", "$session_name created mailbox $name ($email)", 0, $new_mailbox_id);

    flash_alert("Mailbox <strong>$name</strong> created");
    redirect();
}

if (isset($_POST['edit_mailbox'])) {

    validateCSRFToken($_POST['csrf_token']);

    $mailbox_id = intval($_POST['mailbox_id']);

    $name = sanitizeInput($_POST['mailbox_name']);
    $email = sanitizeInput(filter_var($_POST['mailbox_email'], FILTER_VALIDATE_EMAIL));
    $from_name = sanitizeInput($_POST['mailbox_from_name'] ?? '');

    $type = in_array($_POST['mailbox_type'] ?? '', $mailbox_allowed_types, true) ? $_POST['mailbox_type'] : 'standard_imap';
    $type = sanitizeInput($type);

    $imap_username = sanitizeInput($_POST['mailbox_imap_username'] ?? '');
    $imap_host = sanitizeInput($_POST['mailbox_imap_host'] ?? '');

    $imap_port_raw = trim($_POST['mailbox_imap_port'] ?? '');
    $imap_port_sql = ($imap_port_raw !== '') ? intval($imap_port_raw) : 'NULL';

    $imap_encryption = in_array($_POST['mailbox_imap_encryption'] ?? '', $mailbox_allowed_encryptions, true) ? $_POST['mailbox_imap_encryption'] : '';
    $imap_encryption = sanitizeInput($imap_encryption);

    $parse_unknown_senders = isset($_POST['mailbox_parse_unknown_senders']) ? 1 : 0;

    $default_client_id_raw = intval($_POST['mailbox_default_client_id'] ?? 0);
    $default_client_sql = 'NULL';
    if ($default_client_id_raw > 0) {
        $valid_client = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_id = $default_client_id_raw AND client_archived_at IS NULL LIMIT 1"));
        if ($valid_client) {
            $default_client_sql = $default_client_id_raw;
        }
    }

    $active = isset($_POST['mailbox_active']) ? 1 : 0;

    if (empty($name) || empty($email) || $mailbox_id <= 0) {
        flash_alert("Mailbox name and a valid email address are required", 'error');
        redirect();
    }

    $name_sql = trim($from_name) !== '' ? "'$from_name'" : 'NULL';
    $host_sql = trim($imap_host) !== '' ? "'$imap_host'" : 'NULL';
    $username_sql = trim($imap_username) !== '' ? "'$imap_username'" : 'NULL';

    $set = "mailbox_name = '$name',
        mailbox_email = '$email',
        mailbox_from_name = $name_sql,
        mailbox_type = '$type',
        mailbox_imap_host = $host_sql,
        mailbox_imap_port = $imap_port_sql,
        mailbox_imap_encryption = '$imap_encryption',
        mailbox_imap_username = $username_sql,
        mailbox_parse_unknown_senders = $parse_unknown_senders,
        mailbox_default_client_id = $default_client_sql,
        mailbox_active = $active";

    // Blank-means-unchanged: only touch the encrypted password column if a new value was submitted.
    $imap_password_raw = trim($_POST['mailbox_imap_password'] ?? '');
    if ($imap_password_raw !== '') {
        $imap_password_enc = mysqli_real_escape_string($mysqli, encryptSetting($imap_password_raw));
        $set .= ", mailbox_imap_password_enc = '$imap_password_enc'";
    }

    mysqli_query($mysqli, "UPDATE mailboxes SET $set WHERE mailbox_id = $mailbox_id");

    logAction("Mailbox", "Edit", "$session_name edited mailbox $name ($email)", 0, $mailbox_id);

    flash_alert("Mailbox <strong>$name</strong> updated");
    redirect();
}

if (isset($_GET['delete_mailbox'])) {

    validateCSRFToken($_GET['csrf_token']);

    $mailbox_id = intval($_GET['delete_mailbox']);
    $name = sanitizeInput(getFieldById('mailboxes', $mailbox_id, 'mailbox_name'));

    mysqli_query($mysqli, "UPDATE mailboxes SET mailbox_archived_at = NOW() WHERE mailbox_id = $mailbox_id");

    logAction("Mailbox", "Delete", "$session_name deleted mailbox $name", 0, $mailbox_id);

    flash_alert("Mailbox <strong>$name</strong> deleted", 'error');
    redirect();
}

// Begin Microsoft OAuth connect flow for a specific mailbox (GET link from the edit modal, or POST).
// Mirrors settings_mail.php's oauth_connect_microsoft_mail flow exactly, but scoped to a mailbox_id
// and using the shared global config_mail_oauth_* app registration.
if (isset($_GET['oauth_connect_mailbox_microsoft']) || isset($_POST['oauth_connect_mailbox_microsoft'])) {

    $csrf_token = $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    validateCSRFToken($csrf_token);

    $mailbox_id = intval($_GET['oauth_connect_mailbox_microsoft'] ?? $_POST['oauth_connect_mailbox_microsoft'] ?? 0);

    $mailbox_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT mailbox_id, mailbox_name FROM mailboxes WHERE mailbox_id = $mailbox_id AND mailbox_archived_at IS NULL LIMIT 1"));

    if (!$mailbox_row) {
        flash_alert("Mailbox not found", 'error');
        redirect("mailbox.php");
    }

    if (empty($config_mail_oauth_client_id) || empty($config_mail_oauth_client_secret) || empty($config_mail_oauth_tenant_id)) {
        flash_alert("Microsoft OAuth settings are incomplete. Please fill Client ID, Client Secret, and Tenant ID under Settings &gt; Mail first.", 'error');
        redirect("mailbox.php");
    }

    if (defined('BASE_URL') && !empty(BASE_URL)) {
        $base_url = rtrim((string) BASE_URL, '/');
    } else {
        $base_url = 'https://' . rtrim((string) $config_base_url, '/');
    }

    $redirect_uri = $base_url . '/admin/oauth_microsoft_mail_callback.php';

    try {
        $state = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $state = sha1(uniqid((string) mt_rand(), true));
    }

    // Reuse the SAME session key names as the legacy settings_mail.php flow so the
    // shared callback file's existing state-validation code keeps working unchanged.
    $_SESSION['mail_oauth_state'] = $state;
    $_SESSION['mail_oauth_state_expires_at'] = time() + 600;
    $_SESSION['mail_oauth_mailbox_id'] = (int) $mailbox_id;

    // Microsoft Graph (Mail.ReadWrite), not the legacy Office 365 Exchange Online IMAP/SMTP
    // API - see admin/oauth_microsoft_mail_callback.php and cron/ticket_email_parser.php.
    $scope = 'offline_access openid profile https://graph.microsoft.com/Mail.ReadWrite';
    $_SESSION['mail_oauth_scope'] = $scope;

    $authorize_url = MICROSOFT_OAUTH_BASE_URL . rawurlencode($config_mail_oauth_tenant_id) . '/oauth2/v2.0/authorize?'
        . http_build_query([
            'client_id' => $config_mail_oauth_client_id,
            'response_type' => 'code',
            'redirect_uri' => $redirect_uri,
            'response_mode' => 'query',
            'scope' => $scope,
            'state' => $state,
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);

    logAction("Mailbox", "Edit", "$session_name started Microsoft OAuth connect flow for mailbox {$mailbox_row['mailbox_name']}", 0, $mailbox_id);

    redirect($authorize_url);
}

// AJAX: test a mailbox's IMAP connection (given a saved mailbox_id, or raw unsaved form fields).
// Best-effort helper — mirrors the Webklex\PHPIMAP\ClientManager connection shape used by
// cron/ticket_email_parser.php for real polling.
if (isset($_POST['test_mailbox_connection'])) {

    validateCSRFToken($_POST['csrf_token']);
    header('Content-Type: application/json');

    $mailbox_id = intval($_POST['mailbox_id'] ?? 0);

    $refresh_token = '';
    $access_token = '';
    $access_token_expires_at = null;

    if ($mailbox_id > 0) {
        $mailbox = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mailboxes WHERE mailbox_id = $mailbox_id LIMIT 1"));
        if (!$mailbox) {
            echo json_encode(['success' => false, 'error' => 'Mailbox not found']);
            exit;
        }
        $provider = $mailbox['mailbox_type'];
        $host = $mailbox['mailbox_imap_host'];
        $port = (int) $mailbox['mailbox_imap_port'];
        $encryption = !empty($mailbox['mailbox_imap_encryption']) ? $mailbox['mailbox_imap_encryption'] : 'notls';
        $username = $mailbox['mailbox_imap_username'];
        $password = decryptSetting($mailbox['mailbox_imap_password_enc'] ?? '');
        $refresh_token = decryptSetting($mailbox['mailbox_oauth_refresh_token_enc'] ?? '');
        $access_token = decryptSetting($mailbox['mailbox_oauth_access_token_enc'] ?? '');
        $access_token_expires_at = $mailbox['mailbox_oauth_access_token_expires_at'];
    } else {
        $provider = in_array($_POST['mailbox_type'] ?? '', $mailbox_allowed_types, true) ? $_POST['mailbox_type'] : 'standard_imap';
        $host = sanitizeInput($_POST['mailbox_imap_host'] ?? '');
        $port = intval($_POST['mailbox_imap_port'] ?? 0);
        $encryption = sanitizeInput($_POST['mailbox_imap_encryption'] ?? '') ?: 'notls';
        $username = sanitizeInput($_POST['mailbox_imap_username'] ?? '');
        $password = trim($_POST['mailbox_imap_password'] ?? '');
    }

    $auth = null;

    $http_form_post = function (string $url, array $fields): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields, '', '&'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => ($raw !== false && $code >= 200 && $code < 300), 'body' => $raw];
    };

    if ($provider === 'google_oauth' || $provider === 'microsoft_oauth') {
        $auth = 'oauth';
        $host = 'imap.gmail.com'; // unused for microsoft_oauth, which tests via Graph below instead of IMAP
        $port = 993;
        $encryption = 'ssl';

        $token_is_expired = empty($access_token_expires_at) || (strtotime((string) $access_token_expires_at) - 60) <= time();

        if (!empty($access_token) && !$token_is_expired) {
            $password = $access_token;
        } else {
            if (empty($config_mail_oauth_client_id) || empty($config_mail_oauth_client_secret) || empty($refresh_token)) {
                echo json_encode(['success' => false, 'error' => 'No usable OAuth token. Click Connect first (or check global OAuth client credentials under Settings > Mail).']);
                exit;
            }

            if ($provider === 'google_oauth') {
                $resp = $http_form_post('https://oauth2.googleapis.com/token', [
                    'client_id' => $config_mail_oauth_client_id,
                    'client_secret' => $config_mail_oauth_client_secret,
                    'refresh_token' => $refresh_token,
                    'grant_type' => 'refresh_token',
                ]);
            } else {
                if (empty($config_mail_oauth_tenant_id)) {
                    echo json_encode(['success' => false, 'error' => 'Global Microsoft tenant ID is not configured (Settings > Mail).']);
                    exit;
                }
                $token_url = MICROSOFT_OAUTH_BASE_URL . rawurlencode($config_mail_oauth_tenant_id) . '/oauth2/v2.0/token';
                $resp = $http_form_post($token_url, [
                    'client_id' => $config_mail_oauth_client_id,
                    'client_secret' => $config_mail_oauth_client_secret,
                    'refresh_token' => $refresh_token,
                    'grant_type' => 'refresh_token',
                ]);
            }

            if (!$resp['ok']) {
                echo json_encode(['success' => false, 'error' => 'Could not refresh OAuth access token.']);
                exit;
            }

            $json = json_decode($resp['body'], true);
            if (!is_array($json) || empty($json['access_token'])) {
                echo json_encode(['success' => false, 'error' => 'Token response did not include an access token.']);
                exit;
            }

            $password = $json['access_token'];

            if ($mailbox_id > 0) {
                $new_expires_at = date('Y-m-d H:i:s', time() + (int) ($json['expires_in'] ?? 3600));
                $token_esc = mysqli_real_escape_string($mysqli, encryptSetting($password));
                $expires_esc = mysqli_real_escape_string($mysqli, $new_expires_at);
                mysqli_query($mysqli, "UPDATE mailboxes SET mailbox_oauth_access_token_enc = '$token_esc', mailbox_oauth_access_token_expires_at = '$expires_esc' WHERE mailbox_id = $mailbox_id");
            }
        }

        if ($provider === 'microsoft_oauth') {
            // Microsoft mailboxes are read via Graph, not raw IMAP - test with a lightweight Graph call instead.
            $graph_url = "https://graph.microsoft.com/v1.0/users/" . rawurlencode($username) . "/mailFolders/inbox?" . http_build_query(['$select' => 'id']);
            $resp = microsoftGraphRequest('GET', $graph_url, $password);

            if ($resp['ok']) {
                echo json_encode(['success' => true]);
            } else {
                $error = $resp['json']['error']['message'] ?? $resp['error'] ?? "HTTP {$resp['code']}";
                echo json_encode(['success' => false, 'error' => $error]);
            }
            exit;
        }
    } else {
        // standard_imap
        if (empty($host) || empty($port) || empty($username)) {
            echo json_encode(['success' => false, 'error' => 'Missing host, port, or username.']);
            exit;
        }
    }

    try {
        require_once __DIR__ . '/../../plugins/vendor/autoload.php';

        $cm = new \Webklex\PHPIMAP\ClientManager();

        $client = $cm->make(array_filter([
            'host'           => $host,
            'port'           => $port,
            'encryption'     => $encryption,
            'validate_cert'  => true,
            'username'       => $username,
            'password'       => $password,
            'authentication' => $auth,
            'protocol'       => 'imap',
        ]));

        $client->connect();
        $client->disconnect();

        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: given a list of candidate mailbox addresses, test which ones the currently-connected
// Microsoft 365 identity can actually open via Graph. There is no Graph API to *discover*
// shared-mailbox delegate access (see the Setup Guide on Settings > Mail), so this is a
// bounded, explicit "try these specific addresses" check using an already-connected mailbox's
// token - not tenant-wide enumeration.
if (isset($_POST['check_mailbox_access'])) {

    validateCSRFToken($_POST['csrf_token']);
    header('Content-Type: application/json');

    $source_mailbox_id = intval($_POST['source_mailbox_id'] ?? 0);

    $source_where = "mailbox_type = 'microsoft_oauth' AND mailbox_oauth_refresh_token_enc IS NOT NULL AND mailbox_archived_at IS NULL";
    if ($source_mailbox_id > 0) {
        $source_where .= " AND mailbox_id = $source_mailbox_id";
    }
    $source_mailbox = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mailboxes WHERE $source_where ORDER BY mailbox_id LIMIT 1"));

    if (!$source_mailbox) {
        echo json_encode(['success' => false, 'error' => 'Connect at least one Microsoft 365 mailbox first, then use its access to check others.']);
        exit;
    }

    $access_token = mailboxGetFreshMicrosoftAccessToken($source_mailbox);
    if (empty($access_token)) {
        echo json_encode(['success' => false, 'error' => 'Could not get an access token for ' . $source_mailbox['mailbox_imap_username'] . '. Reconnect that mailbox first.']);
        exit;
    }

    $raw_candidates = (string) ($_POST['candidates'] ?? '');
    $candidates = preg_split('/[\r\n,]+/', $raw_candidates);
    $candidates = array_values(array_unique(array_filter(array_map('trim', $candidates))));

    $max_candidates = 25;
    $truncated = count($candidates) > $max_candidates;
    $candidates = array_slice($candidates, 0, $max_candidates);

    $results = [];
    foreach ($candidates as $candidate) {
        $valid_email = filter_var($candidate, FILTER_VALIDATE_EMAIL);
        if (!$valid_email) {
            $results[] = ['email' => sanitizeInput($candidate), 'accessible' => false, 'error' => 'Not a valid email address'];
            continue;
        }

        $graph_url = "https://graph.microsoft.com/v1.0/users/" . rawurlencode($valid_email) . "/mailFolders/inbox?" . http_build_query(['$select' => 'id']);
        $resp = microsoftGraphRequest('GET', $graph_url, $access_token);

        if ($resp['ok']) {
            $results[] = ['email' => $valid_email, 'accessible' => true];
        } else {
            $error = $resp['json']['error']['message'] ?? $resp['error'] ?? "HTTP {$resp['code']}";
            $results[] = ['email' => $valid_email, 'accessible' => false, 'error' => $error];
        }
    }

    echo json_encode([
        'success' => true,
        'checked_as' => $source_mailbox['mailbox_imap_username'],
        'source_mailbox_id' => intval($source_mailbox['mailbox_id']),
        'results' => $results,
        'truncated' => $truncated,
    ]);
    exit;
}

// Adds a new mailbox row for an address already confirmed accessible via check_mailbox_access,
// copying the OAuth tokens from the mailbox that verified it so it's immediately Connected -
// no second Microsoft sign-in needed, since the same token works for any mailbox that
// identity has delegate access to.
if (isset($_POST['add_verified_mailbox'])) {

    validateCSRFToken($_POST['csrf_token']);

    $email = sanitizeInput(filter_var($_POST['mailbox_email'] ?? '', FILTER_VALIDATE_EMAIL));
    $source_mailbox_id = intval($_POST['source_mailbox_id'] ?? 0);

    if (empty($email) || $source_mailbox_id <= 0) {
        flash_alert("A valid mailbox address and source connection are required", 'error');
        redirect("mailbox.php");
    }

    $source_mailbox = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mailboxes WHERE mailbox_id = $source_mailbox_id AND mailbox_type = 'microsoft_oauth' AND mailbox_oauth_refresh_token_enc IS NOT NULL AND mailbox_archived_at IS NULL LIMIT 1"));
    if (!$source_mailbox) {
        flash_alert("Source mailbox connection not found", 'error');
        redirect("mailbox.php");
    }

    $name = sanitizeInput(explode('@', $email)[0]);
    $email_esc = mysqli_real_escape_string($mysqli, $email);
    $name_esc = mysqli_real_escape_string($mysqli, $name);

    $order = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(mailbox_order),0)+1 FROM mailboxes"))[0]);

    // Copy the already-encrypted tokens straight across - same identity, same Graph access.
    $refresh_token_enc_esc = mysqli_real_escape_string($mysqli, $source_mailbox['mailbox_oauth_refresh_token_enc']);
    $access_token_enc_sql = $source_mailbox['mailbox_oauth_access_token_enc'] !== null
        ? "'" . mysqli_real_escape_string($mysqli, $source_mailbox['mailbox_oauth_access_token_enc']) . "'"
        : 'NULL';
    $expires_at_sql = !empty($source_mailbox['mailbox_oauth_access_token_expires_at'])
        ? "'" . mysqli_real_escape_string($mysqli, $source_mailbox['mailbox_oauth_access_token_expires_at']) . "'"
        : 'NULL';

    mysqli_query($mysqli, "INSERT INTO mailboxes SET
        mailbox_name = '$name_esc',
        mailbox_email = '$email_esc',
        mailbox_type = 'microsoft_oauth',
        mailbox_imap_username = '$email_esc',
        mailbox_oauth_refresh_token_enc = '$refresh_token_enc_esc',
        mailbox_oauth_access_token_enc = $access_token_enc_sql,
        mailbox_oauth_access_token_expires_at = $expires_at_sql,
        mailbox_parse_unknown_senders = 0,
        mailbox_active = 1,
        mailbox_order = $order,
        mailbox_created_at = NOW()
    ");

    $new_mailbox_id = intval(mysqli_insert_id($mysqli));

    logAction("Mailbox", "Create", "$session_name added mailbox $name ($email) via shared-mailbox access check, reusing the {$source_mailbox['mailbox_imap_username']} connection", 0, $new_mailbox_id);

    flash_alert("Mailbox <strong>$name</strong> ($email) added and connected. Edit it to rename it or set a default client.");
    redirect("mailbox.php");
}
