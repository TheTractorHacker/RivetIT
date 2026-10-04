<?php

/*
 * RivetIT - GET/POST request handler for API settings
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_api_key'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('user_type', 1);
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/api_key_security.php';

    $name_raw = trim((string) ($_POST['name'] ?? ''));
    $expire_raw = (string) ($_POST['expire'] ?? '');
    $client_id = intval($_POST['client'] ?? 0);
    $permission = (string) ($_POST['permission'] ?? 'read');
    $allow_delete = $permission === 'write' && !empty($_POST['allow_delete']) ? 1 : 0;
    $secret_raw = trim((string) ($_POST['key'] ?? ''));
    $decrypt_password = trim((string) ($_POST['password'] ?? ''));
    $expire_date = DateTimeImmutable::createFromFormat('!Y-m-d', $expire_raw);
    $department = $client_id > 0 ? mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT client_id FROM clients WHERE client_id = $client_id AND client_archived_at IS NULL LIMIT 1")) : null;
    try {
        $networks = rivetitApiKeyNetworks((string) ($_POST['allowed_ips'] ?? ''));
    } catch (InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'danger');
        redirect('/admin/api_keys.php');
    }
    if ($name_raw === '' || strlen($name_raw) > 255 || !$department
        || !in_array($permission, ['read', 'write'], true)
        || !$expire_date || $expire_date->format('Y-m-d') !== $expire_raw || $expire_date <= new DateTimeImmutable('today')
        || !preg_match('/^[A-Za-z0-9_-]{32}$/D', $secret_raw)
        || !preg_match('/^[A-Za-z0-9_-]{32}$/D', $decrypt_password)
        || empty($_POST['ack'])) {
        flash_alert('Enter a name, select a department, choose a future expiration date, and confirm you copied the keys.', 'danger');
        redirect('/admin/api_keys.php');
    }
    $name = sanitizeInput($name_raw);
    $expire = sanitizeInput($expire_raw);
    $allowed_ips = mysqli_real_escape_string($mysqli, implode("\n", $networks));
    $secret = hash('sha256', $secret_raw); // Store only the hash

    // Credential decryption password
    $apikey_specific_encryption_ciphertext = encryptUserSpecificKey($decrypt_password);

    mysqli_query($mysqli,"INSERT INTO api_keys SET api_key_name = '$name', api_key_secret = '$secret', api_key_decrypt_hash = '$apikey_specific_encryption_ciphertext', api_key_expire = '$expire', api_key_client_id = $client_id, api_key_permission = '$permission', api_key_allow_delete = $allow_delete, api_key_allowed_ips = '$allowed_ips'");

    $api_key_id = mysqli_insert_id($mysqli);

    logAction("API Key", "Create", "$session_name created API key $name set to expire on $expire", $client_id, $api_key_id);

    flash_alert("API Key <strong>$name</strong> created");

    redirect();

}

if (isset($_GET['revoke_api_key'])) {

    validateCSRFToken($_GET['csrf_token']);

    $api_key_id = intval($_GET['revoke_api_key']);

    // Get API Key Name
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,"SELECT api_key_name, api_key_client_id FROM api_keys WHERE api_key_id = $api_key_id"));
    $api_key_name = sanitizeInput($row['api_key_name']);
    $client_id = intval($row['api_key_client_id']);

    mysqli_query($mysqli,"UPDATE api_keys SET api_key_expire = NOW() WHERE api_key_id = $api_key_id");

    logAction("API Key", "Revoke", "$session_name revoked API key $api_key_name", $client_id);

    flash_alert("API Key <strong>$api_key_name</strong> revoked", 'error');

    redirect();

}

if (isset($_GET['delete_api_key'])) {

    validateCSRFToken($_GET['csrf_token']);

    $api_key_id = intval($_GET['delete_api_key']);

    // Get API Key Name
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,"SELECT api_key_name, api_key_client_id FROM api_keys WHERE api_key_id = $api_key_id"));
    $api_key_name = sanitizeInput($row['api_key_name']);
    $client_id = intval($row['api_key_client_id']);

    mysqli_query($mysqli,"DELETE FROM api_keys WHERE api_key_id = $api_key_id");

    logAction("API Key", "Delete", "$session_name deleted API key $api_key_name", $client_id);

    flash_alert("API Key <strong>$api_key_name</strong> deleted", 'error');

    redirect();

}

if (isset($_POST['bulk_delete_api_keys'])) {

    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['api_key_ids'])) {

        $count = 0;
        $skipped = 0;

        // Cycle through array and delete each record
        foreach ($_POST['api_key_ids'] as $api_key_id) {

            $api_key_id = intval($api_key_id);

            // Only expired or revoked keys can be deleted, as with the single Delete action
            $still_active = mysqli_num_rows(mysqli_query($mysqli, "SELECT api_key_id FROM api_keys WHERE api_key_id = $api_key_id AND api_key_expire > NOW()"));
            if ($still_active) {
                $skipped++;
                continue;
            }
            $count++;

            // Get API Key Name
            $row = mysqli_fetch_assoc(mysqli_query($mysqli,"SELECT api_key_name, api_key_client_id FROM api_keys WHERE api_key_id = $api_key_id"));
            $api_key_name = sanitizeInput($row['api_key_name']);
            $client_id = intval($row['api_key_client_id']);

            mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_id = $api_key_id");

            logAction("API Key", "Delete", "$session_name deleted API key $api_key_name", $client_id);

        }

        logAction("API Key", "Bulk Delete", "$session_name deleted $count API key(s)");

        flash_alert("Deleted <strong>$count</strong> API keys(s)" . ($skipped ? ". $skipped active key(s) were skipped; revoke them first." : ""), 'error');

    }

    redirect();

}
