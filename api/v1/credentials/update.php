<?php

/*
 * Defence in depth: the legacy X-Api-Key mechanism must never reach credentials.
 * api/v1/index.php already refuses /api/v1/credentials for a legacy key
 * ("Credentials endpoint requires a user API token"), but nginx serves
 * api/v1/<resource>/<action>.php directly via try_files, so this file is
 * reachable without the router ever running. validate_api_key.php resolves any
 * valid key to full instance-wide admin rights with no user identity, no 2FA and
 * no per-user permission behind it - handing that a decryptable vault would
 * undo the whole policy. Credentials over the API are Bearer-token only
 * (api/v1/credentials.php). Denied before auth so the key is not even consulted.
 */
header('Content-Type: application/json');
header('HTTP/1.1 403 Forbidden');
echo json_encode([
    'success' => 'False',
    'message' => 'Credentials are not available via API key. Use a user API token (POST /api/v1/auth/login) and /api/v1/credentials.'
]);
exit();

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Parse ID
$credential_id = intval($_POST['credential_id']);

// Default
$update_count = false;

if (!empty($_POST['api_key_decrypt_password']) && !empty($credential_id)) {

    $credential_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM credentials WHERE credential_id = '$credential_id' AND credential_client_id = $client_id LIMIT 1"));

    // Variable assignment from POST - assigning the current database value if a value is not provided
    require_once 'credential_model.php';

    $update_sql = mysqli_query($mysqli,"UPDATE credentials SET credential_name = '$name', credential_description = '$description', credential_uri = '$uri', credential_uri_2 = '$uri_2', credential_username = '$username', credential_password = '$password', credential_otp_secret = '$otp_secret', credential_note = '$note', credential_favorite = $favorite, credential_contact_id = $contact_id, credential_asset_id = $asset_id, credential_client_id = $client_id WHERE credential_id = '$credential_id' AND credential_client_id = $client_id LIMIT 1");

    // Check insert & get insert ID
    if ($update_sql) {
        $update_count = mysqli_affected_rows($mysqli);

        if ($password_changed) {
            mysqli_query($mysqli, "UPDATE credentials SET credential_password_changed_at = NOW() WHERE credential_id = $credential_id LIMIT 1");
        }

        // Logging
        logAction("Credential", "Edit", "$name via API ($api_key_name)", $client_id, $credential_id);
        logAction("API", "Success", "Updated credential $name via API ($api_key_name)", $client_id);
    }

}

// Output
require_once '../update_output.php';
