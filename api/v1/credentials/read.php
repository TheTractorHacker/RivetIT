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

require_once '../require_get_method.php';

// Defaults
$sql = false;

$api_key_decrypt_password = '';
if (isset($_GET['api_key_decrypt_password'])) {
    $api_key_decrypt_password = $_GET['api_key_decrypt_password']; // No sanitization
}

// Specific credential/login via ID (single)
if (isset($_GET['credential_id']) && !empty($api_key_decrypt_password)) {

    $id = intval($_GET['credential_id']);

    $sql = mysqli_query($mysqli, "SELECT * FROM credentials WHERE credential_id = '$id' AND credential_client_id LIKE '$client_id' LIMIT 1");


} elseif (!empty($api_key_decrypt_password)) {
    // All credentials ("credentials")

    $sql = mysqli_query($mysqli, "SELECT * FROM credentials WHERE credential_client_id LIKE '$client_id' ORDER BY credential_id LIMIT $limit OFFSET $offset");

}

// Output - Not using the standard API read_output.php
// Usually we just output what is in the database, but credentials need to be decrypted first.

if ($sql && mysqli_num_rows($sql) > 0) {

    $return_arr['success'] = "True";
    $return_arr['count'] = mysqli_num_rows($sql);

    $row = array();
    while ($row = mysqli_fetch_assoc($sql)) {
        $row['credential_username'] = apiDecryptCredentialEntry($row['credential_username'], $api_key_decrypt_hash, $api_key_decrypt_password);
        $row['credential_password'] = apiDecryptCredentialEntry($row['credential_password'], $api_key_decrypt_hash, $api_key_decrypt_password);
        $return_arr['data'][] = $row;
    }

    echo json_encode($return_arr);
    exit();
}
else {
    $return_arr['success'] = "False";
    $return_arr['message'] = "No resource (for this client and company) with the specified parameter(s).";

    // Log any database/schema related errors to the PHP Error log
    if (mysqli_error($mysqli)) {
        error_log("API Database Error: " . mysqli_error($mysqli));
    }

    echo json_encode($return_arr);
    exit();
}
