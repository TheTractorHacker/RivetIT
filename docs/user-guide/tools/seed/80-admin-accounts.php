<?php
/*
 * Demo data for the "Administration: users, roles and security" guide page: three API keys
 * (Admin > API Keys). Companion to 80-admin-accounts.sql; apply both, in either order.
 *
 * Why PHP: an API key row carries api_key_decrypt_hash, the vault master key wrapped with the key's own
 * password. The app builds that with encryptUserSpecificKey(), which reads the signed-in user's vault
 * session. This script recovers the master key the same way a sign-in does, puts it into a throw-away
 * session shim and then calls the app's own function, exactly as the "New API Key" form handler does.
 * No cryptography is written here.
 *
 * The raw keys and their decrypt passwords are random, are never printed or stored, and are discarded when
 * the script ends: the demo keys can never be used to call the API. Only the sha256 of each key is stored.
 *
 * Idempotent: a key whose name already exists is left alone. Departments are looked up by name.
 * Locate the app with RIVETIT_APP_DIR (default: the demo copy under the session scratchpad).
 * Optional: RIVETIT_DEMO_ADMIN_PASSWORD (only needed if no canonical vault key has been established).
 */

if (php_sapi_name() !== 'cli') {
    exit("Run this script from the command line.\n");
}

$app = getenv('RIVETIT_APP_DIR') ?: '/tmp/claude-0/-home-user-RivetIT/8339db22-55d4-5b6a-82d2-15f5d4fccf58/scratchpad/demo-app';
if (!is_file($app . '/config.php') || !is_dir($app . '/scripts')) {
    fwrite(STDERR, "80-admin-accounts.php: no RivetIT app at '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');   // like scripts/setup_cli.php: the app's relative includes resolve from here

require_once '../config.php';      // opens $mysqli
require_once '../functions.php';

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    fwrite(STDERR, "80-admin-accounts.php: config.php did not open a database connection.\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

// ---- The vault master key ------------------------------------------------------------------
// Preferred: the canonical copy Settings > Security stores. Fallback: unwrap the first administrator's own
// copy with the demo admin password (documented in the guide brief, never printed).
$master_key = getCanonicalVaultKey($mysqli);
if ($master_key === null) {
    $admin_pw = getenv('RIVETIT_DEMO_ADMIN_PASSWORD') ?: 'DemoAdmin#2026';
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT user_specific_encryption_ciphertext AS ct FROM users
          WHERE user_type = 1 AND user_specific_encryption_ciphertext IS NOT NULL AND user_specific_encryption_ciphertext <> ''
          ORDER BY user_id LIMIT 1"));
    $master_key = $row ? decryptUserSpecificKey($row['ct'], $admin_pw) : false;
}
if (empty($master_key)) {
    fwrite(STDERR, "80-admin-accounts.php: could not recover the vault master key; no API keys were created.\n");
    exit(1);
}

// ---- Throw-away vault session (what generateUserSessionKey() stores for a signed-in user) ------------------
$_SESSION = [];
$session_key = randomString();
$session_iv  = randomString();
$_SESSION['user_encryption_session_ciphertext'] = openssl_encrypt($master_key, 'aes-128-cbc', $session_key, 0, $session_iv);
$_SESSION['user_encryption_session_iv']         = $session_iv;
$_COOKIE['user_encryption_session_key']         = $session_key;
unset($master_key, $session_key, $session_iv);

// ---- The keys ---------------------------------------------------------------------------------------
// name, department (null = all departments), permission, created N days ago, expires in N days (negative = already expired)
$keys = [
    ['Asset inventory sync',     null,                     'write', 60,  300],
    ['Plant floor status board', 'Production',             'read',  25,  340],
    ['Old scanner integration',  'Warehouse & Logistics',  'read',  400, -9],
];

$created = 0;
foreach ($keys as [$name, $department, $permission, $created_days_ago, $expires_in_days]) {
    $stmt = $mysqli->prepare('SELECT 1 FROM api_keys WHERE api_key_name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($exists) {
        continue;
    }

    $client_id = 0;
    if ($department !== null) {
        $stmt = $mysqli->prepare('SELECT client_id FROM clients WHERE client_name = ? LIMIT 1');
        $stmt->bind_param('s', $department);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$found) {
            fwrite(STDERR, "80-admin-accounts.php: department '$department' not found (apply 00-core.sql first); skipped '$name'.\n");
            continue;
        }
        $client_id = intval($found['client_id']);
    }

    $raw_key      = bin2hex(random_bytes(16));           // never shown, never stored, never reused
    $raw_password = bin2hex(random_bytes(16));
    $secret       = hash('sha256', $raw_key);            // the app stores only this hash
    $decrypt_hash = encryptUserSpecificKey($raw_password);   // the app's own function
    $created_at   = date('Y-m-d H:i:s', strtotime("-$created_days_ago days"));
    $expire       = date('Y-m-d', strtotime(($expires_in_days >= 0 ? '+' : '-') . abs($expires_in_days) . ' days'));

    $stmt = $mysqli->prepare('INSERT INTO api_keys (api_key_name, api_key_secret, api_key_decrypt_hash, api_key_created_at, api_key_expire, api_key_client_id, api_key_permission)
                              VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssis', $name, $secret, $decrypt_hash, $created_at, $expire, $client_id, $permission);
    $stmt->execute();
    $stmt->close();
    unset($raw_key, $raw_password, $secret, $decrypt_hash);
    $created++;
}

echo "80-admin-accounts.php: $created API key(s) created, " . (count($keys) - $created) . " already present or skipped.\n";
