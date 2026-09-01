<?php

require_once __DIR__ . '/includes/redis_functions.php';
require_once __DIR__ . '/includes/firebase.php';
require_once __DIR__ . '/includes/notification_categories.php';

// Role check failed wording
DEFINE("WORDING_ROLECHECK_FAILED", "You are not permitted to do that!");

// Function to generate both crypto & URL safe random strings
function randomString(int $length = 16): string {
    $bytes = random_bytes((int) ceil($length * 3 / 4));
    return substr(
        rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='),
        0,
        $length
    );
}

// Older keygen function - only used for TOTP currently
function key32gen(): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes = random_bytes(32);
    $key = '';
    for ($i = 0; $i < 32; $i++) {
        $key .= $chars[ord($bytes[$i]) & 31];
    }
    return $key;
}

function nullable_htmlentities($unsanitizedInput) {
    //return htmlentities($unsanitizedInput ?? '');
    return htmlspecialchars($unsanitizedInput ?? '', ENT_QUOTES, 'UTF-8');
}

// Returns 'text-dark' or 'text-light' depending on which gives better contrast against the given hex color
function tagTextClass($hex_color) {
    $hex = ltrim((string) $hex_color, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return 'text-light'; // unparseable color - fall back to the old default
    }

    // WCAG relative luminance (https://www.w3.org/TR/WCAG21/#dfn-relative-luminance)
    $to_linear = function ($channel) {
        $c = $channel / 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    $r = $to_linear(hexdec(substr($hex, 0, 2)));
    $g = $to_linear(hexdec(substr($hex, 2, 2)));
    $b = $to_linear(hexdec(substr($hex, 4, 2)));
    $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

    // Contrast ratio against white (#fff, luminance 1.0) vs. near-black
    // Bootstrap "text-dark" (#212529, the same shade text-dark already renders,
    // so switching classes doesn't introduce a color inconsistent with the rest
    // of the theme).
    $contrast_white = (1.0 + 0.05) / ($luminance + 0.05);
    $dark_luminance = 0.01806564208421097; // relative luminance of #212529
    $contrast_dark  = ($luminance + 0.05) / ($dark_luminance + 0.05);

    return $contrast_white >= $contrast_dark ? 'text-light' : 'text-dark';
}

// Curated set of preset tag colors offered in the tag color picker
function tagColorPalette() {
    return [
        '#007bff', '#6610f2', '#6f42c1', '#e83e8c',
        '#dc3545', '#fd7e14', '#ffc107', '#28a745',
        '#20c997', '#17a2b8', '#343a40', '#6c757d',
        '#44bcf8', '#d98bfe', '#4ef02d', '#fea82f',
    ];
}

function initials($string) {
    if (!empty($string)) {
        $return = '';
        foreach (explode(' ', $string) as $word) {
            $return .= mb_strtoupper($word[0], 'UTF-8'); // Use mb_strtoupper for UTF-8 support
        }
        $return = substr($return, 0, 2);
        return $return;
    }
}

function removeDirectory($path) {
    if (!file_exists($path)) {
        return;
    }

    $files = glob($path . '/*');
    foreach ($files as $file) {
        is_dir($file) ? removeDirectory($file) : unlink($file);
    }
    rmdir($path);
}

function copyDirectory($src, $dst) {
    if (!is_dir($src)) {
        return;
    }

    if (!is_dir($dst)) {
        mkdir($dst, 0775, true);
    }

    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;

        if (is_dir($srcPath)) {
            copyDirectory($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
}

function getUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'];
}

function getIP() {

    // Default way to get IP
    $ip = $_SERVER['REMOTE_ADDR'];

    // Allow overrides via config.php in-case we use a proxy - https://docs.itflow.org/config_php
    if (defined("CONST_GET_IP_METHOD") && CONST_GET_IP_METHOD == "HTTP_X_FORWARDED_FOR") {
        $ip = explode(',', getenv('HTTP_X_FORWARDED_FOR'))[0] ?? $_SERVER['REMOTE_ADDR'];
    } elseif (defined("CONST_GET_IP_METHOD") && CONST_GET_IP_METHOD == "HTTP_CF_CONNECTING_IP") {
        $ip = $_SERVER["HTTP_CF_CONNECTING_IP"] ?? $_SERVER['REMOTE_ADDR'];
    }

    // Abort if something isn't right
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        error_log("ITFlow - Could not validate remote IP address");
        error_log("ITFlow - IP was [$ip] using method " . CONST_GET_IP_METHOD);
        exit("Potential Security Violation");
    }

    return $ip;
}

function getWebBrowser($user_browser) {
    $browser        =   "-";
    $browser_array  =   array(
        '/msie/i'       =>  "<i class='fab fa-fw fa-internet-explorer text-secondary'></i> Internet Explorer",
        '/firefox/i'    =>  "<i class='fab fa-fw fa-firefox text-secondary'></i> Firefox",
        '/safari/i'     =>  "<i class='fab fa-fw fa-safari text-secondary'></i> Safari",
        '/chrome/i'     =>  "<i class='fab fa-fw fa-chrome text-secondary'></i> Chrome",
        '/edg/i'        =>  "<i class='fab fa-fw fa-edge text-secondary'></i> Edge",
        '/opr/i'        =>  "<i class='fab fa-fw fa-opera text-secondary'></i> Opera",
        '/ddg/i'        =>  "<i class='fas fa-fw fa-globe text-secondary'></i> DuckDuckGo"
    );
    foreach ($browser_array as $regex => $value) {
        if (preg_match($regex, $user_browser)) {
            $browser    =   $value;
        }
    }
    return $browser;
}

function getOS($user_os) {
    $os_platform    =   "-";
    $os_array       =   array(
        '/windows/i'            =>  "<i class='fab fa-fw fa-windows text-secondary'></i> Windows",
        '/macintosh|mac os x/i' =>  "<i class='fab fa-fw fa-apple text-secondary'></i> MacOS",
        '/linux/i'              =>  "<i class='fab fa-fw fa-linux text-secondary'></i> Linux",
        '/ubuntu/i'             =>  "<i class='fab fa-fw fa-ubuntu text-secondary'></i> Ubuntu",
        '/fedora/i'             =>  "<i class='fab fa-fw fa-fedora text-secondary'></i> Fedora",
        '/iphone/i'             =>  "<i class='fab fa-fw fa-apple text-secondary'></i> iPhone",
        '/ipad/i'               =>  "<i class='fab fa-fw fa-apple text-secondary'></i> iPad",
        '/android/i'            =>  "<i class='fab fa-fw fa-android text-secondary'></i> Android"
    );
    foreach ($os_array as $regex => $value) {
        if (preg_match($regex, $user_os)) {
            $os_platform    =   $value;
        }
    }
    return $os_platform;
}

function getDevice() {
    $tablet_browser = 0;
    $mobile_browser = 0;
    if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', strtolower($_SERVER['HTTP_USER_AGENT']))) {
        $tablet_browser++;
    }
    if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile)/i', strtolower($_SERVER['HTTP_USER_AGENT']))) {
        $mobile_browser++;
    }
    if ((strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'application/vnd.wap.xhtml+xml') > 0) || ((isset($_SERVER['HTTP_X_WAP_PROFILE']) || isset($_SERVER['HTTP_PROFILE'])))) {
        $mobile_browser++;
    }
    $mobile_ua = strtolower(substr(getUserAgent(), 0, 4));
    $mobile_agents = array(
        'w3c ', 'acs-', 'alav', 'alca', 'amoi', 'audi', 'avan', 'benq', 'bird', 'blac',
        'blaz', 'brew', 'cell', 'cldc', 'cmd-', 'dang', 'doco', 'eric', 'hipt', 'inno',
        'ipaq', 'java', 'jigs', 'kddi', 'keji', 'leno', 'lg-c', 'lg-d', 'lg-g', 'lge-',
        'maui', 'maxo', 'midp', 'mits', 'mmef', 'mobi', 'mot-', 'moto', 'mwbp', 'nec-',
        'newt', 'noki', 'palm', 'pana', 'pant', 'phil', 'play', 'port', 'prox',
        'qwap', 'sage', 'sams', 'sany', 'sch-', 'sec-', 'send', 'seri', 'sgh-', 'shar',
        'sie-', 'siem', 'smal', 'smar', 'sony', 'sph-', 'symb', 't-mo', 'teli', 'tim-',
        'tosh', 'tsm-', 'upg1', 'upsi', 'vk-v', 'voda', 'wap-', 'wapa', 'wapi', 'wapp',
        'wapr', 'webc', 'winw', 'winw', 'xda ', 'xda-'
    );
    if (in_array($mobile_ua, $mobile_agents)) {
        $mobile_browser++;
    }
    if (strpos(strtolower(getUserAgent()), 'opera mini') > 0) {
        $mobile_browser++;
        //Check for tablets on Opera Mini alternative headers
        $stock_ua = strtolower(isset($_SERVER['HTTP_X_OPERAMINI_PHONE_UA']) ? $_SERVER['HTTP_X_OPERAMINI_PHONE_UA'] : (isset($_SERVER['HTTP_DEVICE_STOCK_UA']) ? $_SERVER['HTTP_DEVICE_STOCK_UA'] : ''));
        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*mobile))/i', $stock_ua)) {
            $tablet_browser++;
        }
    }
    if ($tablet_browser > 0) {
        //do something for tablet devices
        return 'Tablet';
    } else if ($mobile_browser > 0) {
        //do something for mobile devices
        return 'Mobile';
    } else {
        //do something for everything else
        return 'Computer';
    }
}

function truncate($text, $chars) {
    if (strlen($text) <= $chars) {
        return $text;
    }
    $text = $text . " ";
    $text = substr($text, 0, $chars);
    $lastSpacePos = strrpos($text, ' ');
    if ($lastSpacePos !== false) {
        $text = substr($text, 0, $lastSpacePos);
    }
    return $text . "...";
}

function formatPhoneNumber($phoneNumber, $country_code = '', $show_country_code = false) {
    // Remove all non-digit characters
    $digits = preg_replace('/\D/', '', $phoneNumber ?? '');
    $formatted = '';

    // If no digits at all, fallback early
    if (strlen($digits) === 0) {
        return $phoneNumber;
    }

    // Helper function to safely check the first digit
    $startsWith = function($str, $char) {
        return isset($str[0]) && $str[0] === $char;
    };

    switch ($country_code) {
        case '1': // USA/Canada
            if (strlen($digits) === 10) {
                $formatted = '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
            }
            break;

        case '44': // UK
            if ($startsWith($digits, '0')) {
                $digits = substr($digits, 1);
            }
            if (strlen($digits) === 10) {
                $formatted = '0' . substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7);
            }
            break;

        case '61': // Australia
            if ($startsWith($digits, '0')) {
                $digits = substr($digits, 1);
            }
            if (strlen($digits) === 9) {
                $formatted = '0' . substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7);
            }
            break;

        case '91': // India
            if (strlen($digits) === 10) {
                $formatted = substr($digits, 0, 5) . ' ' . substr($digits, 5);
            }
            break;

        case '81': // Japan
            if ($startsWith($digits, '0')) {
                $digits = substr($digits, 1);
            }
            if (strlen($digits) >= 9 && strlen($digits) <= 10) {
                $formatted = '0' . substr($digits, 0, 2) . '-' . substr($digits, 2, 4) . '-' . substr($digits, 6);
            }
            break;

        case '49': // Germany
            if ($startsWith($digits, '0')) {
                $digits = substr($digits, 1);
            }
            if (strlen($digits) >= 10) {
                $formatted = '0' . substr($digits, 0, 3) . ' ' . substr($digits, 3);
            }
            break;

        case '33': // France
            if ($startsWith($digits, '0')) {
                $digits = substr($digits, 1);
            }
            if (strlen($digits) === 9) {
                $formatted = '0' . implode(' ', str_split($digits, 2));
            }
            break;

        case '34': // Spain
            if (strlen($digits) === 9) {
                $formatted = substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6);
            }
            break;

        case '39': // Italy
            if ($startsWith($digits, '0')) {
                $digits = substr($digits, 1);
            }
            $formatted = '0' . implode(' ', str_split($digits, 3));
            break;

        case '55': // Brazil
            if (strlen($digits) === 11) {
                $formatted = '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7);
            }
            break;

        case '7': // Russia
            if ($startsWith($digits, '8')) {
                $digits = substr($digits, 1);
            }
            if (strlen($digits) === 10) {
                $formatted = '8 (' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6, 2) . '-' . substr($digits, 8);
            }
            break;

        case '86': // China
            if (strlen($digits) === 11) {
                $formatted = substr($digits, 0, 3) . ' ' . substr($digits, 3, 4) . ' ' . substr($digits, 7);
            }
            break;

        case '82': // South Korea
            if (strlen($digits) === 11) {
                $formatted = substr($digits, 0, 3) . '-' . substr($digits, 3, 4) . '-' . substr($digits, 7);
            }
            break;

        case '62': // Indonesia
            if (!$startsWith($digits, '0')) {
                $digits = '0' . $digits;
            }
            if (strlen($digits) === 12) {
                $formatted = substr($digits, 0, 4) . ' ' . substr($digits, 4, 4) . ' ' . substr($digits, 8);
            }
            break;

        case '63': // Philippines
            if (strlen($digits) === 11) {
                $formatted = substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7);
            }
            break;

        case '234': // Nigeria
            if (!$startsWith($digits, '0')) {
                $digits = '0' . $digits;
            }
            if (strlen($digits) === 11) {
                $formatted = substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7);
            }
            break;

        case '27': // South Africa
            if (strlen($digits) >= 9 && strlen($digits) <= 10) {
                $formatted = substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6);
            }
            break;

        case '971': // UAE
            if (strlen($digits) === 9) {
                $formatted = substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6);
            }
            break;

        default:
            // fallback — do nothing, use raw digits later
            break;
    }

    if (!$formatted) {
        $formatted = $digits ?: $phoneNumber;
    }

    return $show_country_code && $country_code ? "+$country_code $formatted" : $formatted;
}

function mkdirMissing($dir) {
    if (!is_dir($dir)) {
        mkdir($dir);
    }
}

// Called during initial setup
// Encrypts the master key with the user's password
function setupFirstUserSpecificKey($user_password, $site_encryption_master_key) {
    $iv = randomString();
    $salt = randomString();

    // Derive a full 16 raw bytes (128 bits) of AES key material from the password.
    // The "V2:" prefix marks this ciphertext so decryptUserSpecificKey() knows to
    // use raw-byte derivation instead of the old hex-string derivation.
    $user_password_kdhash = hash_pbkdf2('sha256', $user_password, $salt, 100000, 16, true);

    $ciphertext = openssl_encrypt($site_encryption_master_key, 'aes-128-cbc', $user_password_kdhash, 0, $iv);

    return 'V2:' . $salt . $iv . $ciphertext;
}

// Returns the canonical site encryption master key (decrypted), or null if one
// has not yet been established via Admin Settings > Security.
function getCanonicalVaultKey($mysqli): ?string {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_vault_canonical_key FROM settings WHERE company_id = 1"));
    $stored = $row['config_vault_canonical_key'] ?? '';
    if (empty($stored)) return null;
    $key = decryptSetting($stored);
    return $key !== '' ? $key : null;
}

// Persists the canonical site encryption master key (admin-only action).
function setCanonicalVaultKey($mysqli, string $master_key): void {
    $esc = mysqli_real_escape_string($mysqli, encryptSetting($master_key));
    mysqli_query($mysqli, "UPDATE settings SET config_vault_canonical_key = '$esc', config_vault_canonical_key_set_at = NOW() WHERE company_id = 1");
}

// Self-heals a missing/unusable user_specific_encryption_ciphertext (e.g. NULL on
// legacy accounts, an archived/reactivated user, or stale after a password change
// that didn't update it) by re-wrapping the canonical site encryption master key
// under this user's password. Returns the canonical master key, or null if no
// canonical key has been established yet - in that case the vault is left locked
// rather than minting a new, divergent key for this user.
function repairUserSpecificKey($mysqli, int $user_id, string $user_password): ?string {
    $canonical_key = getCanonicalVaultKey($mysqli);
    if ($canonical_key === null) {
        return null;
    }
    repairUserSpecificKeyWithKnownKey($mysqli, $user_id, $user_password, $canonical_key);
    return $canonical_key;
}

/*
 * For additional users / password changes (and now the API)
 * New Users: Requires the admin setting up their account have a Specific/Session key configured
 * Password Changes: Will use the current info in the session.
*/
function encryptUserSpecificKey($user_password) {
    $iv = randomString();
    $salt = randomString();

    // Get the session info.
    $user_encryption_session_ciphertext = $_SESSION['user_encryption_session_ciphertext'];
    $user_encryption_session_iv = $_SESSION['user_encryption_session_iv'];
    $user_encryption_session_key = $_COOKIE['user_encryption_session_key'];

    // Decrypt the session key to get the master key
    $site_encryption_master_key = openssl_decrypt($user_encryption_session_ciphertext, 'aes-128-cbc', $user_encryption_session_key, 0, $user_encryption_session_iv);

    // Derive 16 raw bytes of AES key material (V2 format — full 128-bit entropy)
    $user_password_kdhash = hash_pbkdf2('sha256', $user_password, $salt, 100000, 16, true);

    $ciphertext = openssl_encrypt($site_encryption_master_key, 'aes-128-cbc', $user_password_kdhash, 0, $iv);

    return 'V2:' . $salt . $iv . $ciphertext;
}

// Given a ciphertext (incl. IV) and the user's (or API key) password, returns the site master key
// Ran at login, to facilitate generateUserSessionKey
function decryptUserSpecificKey($user_encryption_ciphertext, $user_password)
{
    // V2 ciphertexts (produced by setupFirstUserSpecificKey / encryptUserSpecificKey after the
    // PBKDF2 fix) are prefixed with "V2:" and use raw-byte key derivation (128-bit entropy).
    // V1 (legacy) ciphertexts have no prefix and used a hex-string key (64-bit effective entropy).
    if (substr($user_encryption_ciphertext, 0, 3) === 'V2:') {
        $payload = substr($user_encryption_ciphertext, 3);
        $salt     = substr($payload, 0, 16);
        $iv       = substr($payload, 16, 16);
        $ciphertext = substr($payload, 32);
        $key = hash_pbkdf2('sha256', $user_password, $salt, 100000, 16, true);
    } else {
        $salt     = substr($user_encryption_ciphertext, 0, 16);
        $iv       = substr($user_encryption_ciphertext, 16, 16);
        $ciphertext = substr($user_encryption_ciphertext, 32);
        $key = hash_pbkdf2('sha256', $user_password, $salt, 100000, 16);
    }

    return openssl_decrypt($ciphertext, 'aes-128-cbc', $key, 0, $iv);
}

/*
Generates what is probably best described as a session key (ephemeral-ish)
- Allows us to store the master key on the server whilst the user is using the application, without prompting to type their password everytime they want to decrypt a credential
- Ciphertext/IV is stored on the server in the users' session, encryption key is controlled/provided by the user as a cookie
- Only the user can decrypt their session ciphertext to get the master key
- Encryption key never hits the disk in cleartext
*/
function generateUserSessionKey($site_encryption_master_key)
{
    $user_encryption_session_key = randomString();
    $user_encryption_session_iv = randomString();
    $user_encryption_session_ciphertext = openssl_encrypt($site_encryption_master_key, 'aes-128-cbc', $user_encryption_session_key, 0, $user_encryption_session_iv);

    // Store ciphertext in the user's session
    $_SESSION['user_encryption_session_ciphertext'] = $user_encryption_session_ciphertext;
    $_SESSION['user_encryption_session_iv'] = $user_encryption_session_iv;

    // Give the user "their" key as a cookie
    include 'config.php';

    // Match cookie lifetime to the session lifetime so credentials stay decryptable
    $cookie_expires = time() + ($_SESSION['session_lifetime_seconds'] ?? 28800);

    if ($config_https_only) {
        setcookie("user_encryption_session_key", "$user_encryption_session_key", ['expires' => $cookie_expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'None']);
    } else {
        setcookie("user_encryption_session_key", $user_encryption_session_key, ['expires' => $cookie_expires, 'path' => '/', 'httponly' => true]);
        $_SESSION['alert_message'] = "Unencrypted connection flag set: Using non-secure cookies.";
    }
}

// How long the user_passkey_enc_key cookie should last. Intentionally much longer
// than the PHP session lifetime so passkey-only logins keep decrypting the
// credential vault correctly between sessions instead of falling back to self-heal.
define('PASSKEY_ENC_KEY_LIFETIME', 60 * 60 * 24 * 90); // 90 days

// Stores the master key encrypted in the DB + a persistent cookie so passkey logins can decrypt credentials.
// Call this after any successful password login, passing the same $master_key used for generateUserSessionKey.
function storePasskeyEncKey($mysqli, int $user_id, string $master_key, bool $https_only, int $lifetime): void {
    $enc_key = randomString(32);
    $iv      = randomString(16);
    $ct      = openssl_encrypt($master_key, 'aes-128-cbc', $enc_key, 0, $iv);
    if ($ct === false) return;
    $esc_ct = mysqli_real_escape_string($mysqli, $ct);
    $esc_iv = mysqli_real_escape_string($mysqli, base64_encode($iv));
    mysqli_query($mysqli, "UPDATE users SET user_passkey_enc_ciphertext='$esc_ct', user_passkey_enc_iv='$esc_iv', user_passkey_bootstrap_key=NULL WHERE user_id=$user_id");
    $expires = time() + $lifetime;
    if ($https_only) {
        setcookie('user_passkey_enc_key', $enc_key, ['expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'None']);
    } else {
        setcookie('user_passkey_enc_key', $enc_key, $expires, '/');
    }
}

// Attempts to recover the real site master key from the passkey-wrapped copy
// (user_passkey_enc_ciphertext), using the user_passkey_enc_key cookie or,
// failing that, the server-side bootstrap key. Mirrors the recovery logic in
// passkey_auth_complete.php. Returns null if no key material is available or
// decryption fails/yields nothing.
function recoverMasterKeyFromPasskeyEnc(array $userRow): ?string {
    $enc_key = $_COOKIE['user_passkey_enc_key'] ?? ($userRow['user_passkey_bootstrap_key'] ?? null);

    if (empty($enc_key) || empty($userRow['user_passkey_enc_ciphertext'])) {
        return null;
    }

    $iv  = base64_decode($userRow['user_passkey_enc_iv'] ?? '');
    $key = openssl_decrypt($userRow['user_passkey_enc_ciphertext'], 'aes-128-cbc', $enc_key, 0, $iv);

    return ($key === false || $key === '') ? null : $key;
}

// Repairs user_specific_encryption_ciphertext using a master key recovered from
// elsewhere (e.g. recoverMasterKeyFromPasskeyEnc()), re-wrapping it under the
// user's current (verified) password. Unlike repairUserSpecificKey(), this does
// not mint a new key, so existing credentials encrypted under $master_key remain
// readable after the repair.
function repairUserSpecificKeyWithKnownKey($mysqli, int $user_id, string $user_password, string $master_key): void {
    $ciphertext = setupFirstUserSpecificKey($user_password, $master_key);
    $esc = mysqli_real_escape_string($mysqli, $ciphertext);
    mysqli_query($mysqli, "UPDATE users SET user_specific_encryption_ciphertext = '$esc' WHERE user_id = $user_id");
}

// Decrypts an encrypted password (website/asset credentials), returns it as a string
function decryptCredentialEntry($credential_password_ciphertext)
{

    // Split the credential into IV and Ciphertext
    $credential_iv =  substr($credential_password_ciphertext, 0, 16);
    $credential_ciphertext = $salt = substr($credential_password_ciphertext, 16);

    // Get the user session info.
    $user_encryption_session_ciphertext = $_SESSION['user_encryption_session_ciphertext'] ?? '';
    $user_encryption_session_iv =  $_SESSION['user_encryption_session_iv'] ?? '';
    $user_encryption_session_key = $_COOKIE['user_encryption_session_key'] ?? '';

    // Vault is locked for this session (e.g. a remember-me-restored session
    // that never derived a vault key, or a vault-key cookie past its expiry
    // while the auth session itself is still alive) - fail loudly with a
    // distinct null sentinel instead of silently decrypting to bool(false).
    if ($user_encryption_session_ciphertext === '' || $user_encryption_session_iv === '' || $user_encryption_session_key === '') {
        return null;
    }

    // Decrypt the session key to get the master key
    $site_encryption_master_key = openssl_decrypt($user_encryption_session_ciphertext, 'aes-128-cbc', $user_encryption_session_key, 0, $user_encryption_session_iv);

    // Decrypt the credential password using the master key
    return openssl_decrypt($credential_ciphertext, 'aes-128-cbc', $site_encryption_master_key, 0, $credential_iv);
}

// Encrypts a website/asset credential password
function encryptCredentialEntry($credential_password_cleartext)
{
    $iv = randomString();

    // Get the user session info.
    $user_encryption_session_ciphertext = $_SESSION['user_encryption_session_ciphertext'];
    $user_encryption_session_iv =  $_SESSION['user_encryption_session_iv'];
    $user_encryption_session_key = $_COOKIE['user_encryption_session_key'];

    //Decrypt the session key to get the master key
    $site_encryption_master_key = openssl_decrypt($user_encryption_session_ciphertext, 'aes-128-cbc', $user_encryption_session_key, 0, $user_encryption_session_iv);

    //Encrypt the website/asset credential using the master key
    $ciphertext = openssl_encrypt($credential_password_cleartext, 'aes-128-cbc', $site_encryption_master_key, 0, $iv);

    return $iv . $ciphertext;
}

// Encrypts a credential password using an explicitly provided master key
// (used by integrations/CLI scripts that don't have a logged-in session,
// e.g. the canonical vault key from getCanonicalVaultKey()).
function encryptCredentialEntryWithKey(#[\SensitiveParameter]$credential_password_cleartext, #[\SensitiveParameter]$master_key)
{
    $iv = randomString();
    $ciphertext = openssl_encrypt($credential_password_cleartext, 'aes-128-cbc', $master_key, 0, $iv);
    return $iv . $ciphertext;
}

// Decrypts a credential password using an explicitly provided master key
function decryptCredentialEntryWithKey($credential_ciphertext, #[\SensitiveParameter]$master_key)
{
    $iv = substr($credential_ciphertext, 0, 16);
    $ciphertext = substr($credential_ciphertext, 16);
    return openssl_decrypt($ciphertext, 'aes-128-cbc', $master_key, 0, $iv);
}

function apiDecryptCredentialEntry($credential_ciphertext, $api_key_decrypt_hash, #[\SensitiveParameter]$api_key_decrypt_password)
{
    // Split the Credential entry (username/password) into IV and Ciphertext
    $credential_iv =  substr($credential_ciphertext, 0, 16);
    $credential_ciphertext = $salt = substr($credential_ciphertext, 16);

    // Decrypt the api hash to get the master key
    $site_encryption_master_key = decryptUserSpecificKey($api_key_decrypt_hash, $api_key_decrypt_password);

    // Decrypt the credential password using the master key
    return openssl_decrypt($credential_ciphertext, 'aes-128-cbc', $site_encryption_master_key, 0, $credential_iv);
}

function apiEncryptCredentialEntry(#[\SensitiveParameter]$credential_cleartext, $api_key_decrypt_hash, #[\SensitiveParameter]$api_key_decrypt_password)
{
    $iv = randomString();

    // Decrypt the api hash to get the master key
    $site_encryption_master_key = decryptUserSpecificKey($api_key_decrypt_hash, $api_key_decrypt_password);

    // Encrypt the credential using the master key
    $ciphertext = openssl_encrypt($credential_cleartext, 'aes-128-cbc', $site_encryption_master_key, 0, $iv);

    return $iv . $ciphertext;
}


// Encrypts an OTP/TOTP secret using the site master key (same as credential fields).
// Stores an 'enc:' prefix so plaintext legacy values can be distinguished.
function encryptOtpSecret(string $otp_plain): string {
    if (empty($otp_plain)) return '';
    $iv = randomString();
    $user_encryption_session_ciphertext = $_SESSION['user_encryption_session_ciphertext'];
    $user_encryption_session_iv = $_SESSION['user_encryption_session_iv'];
    $user_encryption_session_key = $_COOKIE['user_encryption_session_key'];
    $master_key = openssl_decrypt($user_encryption_session_ciphertext, 'aes-128-cbc', $user_encryption_session_key, 0, $user_encryption_session_iv);
    $ct = openssl_encrypt($otp_plain, 'aes-128-cbc', $master_key, 0, $iv);
    return 'enc:' . $iv . $ct;
}

// Decrypts an OTP secret. If the value does not have the 'enc:' prefix it is returned as-is
// (backward-compatible with existing plaintext secrets).
function decryptOtpSecret(string $otp_stored): string {
    if (empty($otp_stored) || !str_starts_with($otp_stored, 'enc:')) return $otp_stored;
    $payload = substr($otp_stored, 4);
    $iv = substr($payload, 0, 16);
    $ct = substr($payload, 16);
    $user_encryption_session_ciphertext = $_SESSION['user_encryption_session_ciphertext'];
    $user_encryption_session_iv = $_SESSION['user_encryption_session_iv'];
    $user_encryption_session_key = $_COOKIE['user_encryption_session_key'];
    $master_key = openssl_decrypt($user_encryption_session_ciphertext, 'aes-128-cbc', $user_encryption_session_key, 0, $user_encryption_session_iv);
    return openssl_decrypt($ct, 'aes-128-cbc', $master_key, 0, $iv) ?: '';
}
// Get domain general info (whois + NS/A/MX records)
function getDomainRecords($name)
{
    $records = array();

    // Only run if we think the domain is valid
    if (!filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !checkdnsrr($name, 'SOA')) {
        $records['a'] = '';
        $records['ns'] = '';
        $records['mx'] = '';
        $records['whois'] = '';
        return $records;
    }

    $domain = escapeshellarg(str_replace('www.', '', $name));

    // Get A, NS, MX, TXT, and WHOIS records
    $records['a'] = trim(strip_tags(shell_exec("dig +short $domain")));
    $records['ns'] = trim(strip_tags(shell_exec("dig +short NS $domain")));
    $records['mx'] = trim(strip_tags(shell_exec("dig +short MX $domain")));
    $records['txt'] = trim(strip_tags(shell_exec("dig +short TXT $domain")));
    $records['whois'] = substr(trim(strip_tags(shell_exec("whois -H $domain | head -30 | sed 's/   //g'"))), 0, 254);

    // Sort A records (if multiple records exist)
    if (!empty($records['a'])) {
        $a_records = explode("\n", $records['a']);
        array_walk($a_records, function(&$record) {
            $record = trim($record);
        });
        sort($a_records);
        $records['a'] = implode("\n", $a_records);
    }

    // Sort NS records (if multiple records exist)
    if (!empty($records['ns'])) {
        $ns_records = explode("\n", $records['ns']);
        array_walk($ns_records, function(&$record) {
            $record = trim($record);
        });
        sort($ns_records);
        $records['ns'] = implode("\n", $ns_records);
    }

    // Sort MX records (if multiple records exist)
    if (!empty($records['mx'])) {
        $mx_records = explode("\n", $records['mx']);
        array_walk($mx_records, function(&$record) {
            $record = trim($record);
        });
        sort($mx_records);
        $records['mx'] = implode("\n", $mx_records);
    }

    // Sort TXT records (if multiple records exist)
    if (!empty($records['txt'])) {
        $txt_records = explode("\n", $records['txt']);
        array_walk($txt_records, function(&$record) {
            $record = trim($record);
        });
        sort($txt_records);
        $records['txt'] = implode("\n", $txt_records);
    }

    return $records;
}

// Used to automatically attempt to get SSL certificates as part of adding domains
// The logic for the fetch (sync) button on the client_certificates page is in ajax.php, and allows ports other than 443
function getSSL($full_name)
{

    // Parse host and port
    $name = parse_url("//$full_name", PHP_URL_HOST);
    $port = parse_url("//$full_name", PHP_URL_PORT);

    // Default port
    if (!$port) {
        $port = "443";
    }

    $certificate = array();
    $certificate['success'] = false;

    // Only run if we think the domain is valid
    if (!filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        $certificate['expire'] = '';
        $certificate['issued_by'] = '';
        $certificate['public_key'] = '';
        return $certificate;
    }

    // Get SSL/TSL certificate (using verify peer false to allow for self-signed certs) for domain on default port
    $socket = "ssl://$name:$port";
    $get = stream_context_create(array("ssl" => array("capture_peer_cert" => true, "verify_peer" => false,)));
    $read = stream_socket_client($socket, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $get);

    // If the socket connected
    if ($read) {
        $cert = stream_context_get_params($read);
        $cert_public_key_obj = openssl_x509_parse($cert['options']['ssl']['peer_certificate']);
        openssl_x509_export($cert['options']['ssl']['peer_certificate'], $export);

        if ($cert_public_key_obj) {
            $certificate['success'] = true;
            $certificate['expire'] = date('Y-m-d', $cert_public_key_obj['validTo_time_t']);
            $certificate['issued_by'] = strip_tags($cert_public_key_obj['issuer']['O']);
            $certificate['public_key'] = $export;
        }
    }

    return $certificate;
}

function strtoAZaz09($string)
{
    // Gets rid of non-alphanumerics
    return preg_replace('/[^A-Za-z0-9_-]/', '', $string);
}

// Cross-Site Request Forgery check for sensitive functions
// Validates the CSRF token provided matches the one in the users session
function validateCSRFToken($token)
{
    // A missing token (rather than a wrong one) previously reached hash_equals()
    // as null, which throws a TypeError (HTTP 500) instead of the intended
    // graceful rejection below — treat it the same as any other invalid token.
    if (is_string($token) && hash_equals($token, $_SESSION['csrf_token'])) {
        return true;
    } else {
        $_SESSION['alert_type'] = "warning";
        $_SESSION['alert_message'] = "CSRF token verification failed. Try again, or log out to refresh your token.";
        header("Location: index.php");
        exit();
    }
}

/*
 * LEGACY Role validation
 * Admin - 3
 * Tech - 2
 * Accountant - 1
 */

function validateAdminRole() {
    global $session_user_role;
    if (!isset($session_user_role) || $session_user_role != 3) {
        $_SESSION['alert_type'] = "danger";
        $_SESSION['alert_message'] = WORDING_ROLECHECK_FAILED;
        header("Location: " . $_SERVER["HTTP_REFERER"]);
        exit();
    }
}

// LEGACY
// Validates a user is a tech (or admin). Stops page load and attempts to direct away from the page if not (i.e. user is an accountant)
function validateTechRole() {
    global $session_user_role;
    if (!isset($session_user_role) || $session_user_role == 1) {
        $_SESSION['alert_type'] = "danger";
        $_SESSION['alert_message'] = WORDING_ROLECHECK_FAILED;
        header("Location: " . $_SERVER["HTTP_REFERER"]);
        exit();
    }
}

// LEGACY
// Validates a user is an accountant (or admin). Stops page load and attempts to direct away from the page if not (i.e. user is a tech)
function validateAccountantRole() {
    global $session_user_role;
    if (!isset($session_user_role) || $session_user_role == 2) {
        $_SESSION['alert_type'] = "danger";
        $_SESSION['alert_message'] = WORDING_ROLECHECK_FAILED;
        header("Location: " . $_SERVER["HTTP_REFERER"]);
        exit();
    }
}

function roundUpToNearestMultiple($n, $increment = 1000)
{
    return (int) ($increment * ceil($n / $increment));
}

/**
 * Formats a duration in seconds into a short human string (e.g. "3d 4h", "2h 15m", "45m", "30s").
 * Returns "-" for null/empty so callers can echo the result directly.
 */
function secondsToTime($seconds)
{
    if ($seconds === null || $seconds === '' || $seconds < 0) {
        return '-';
    }
    $seconds = (int) round($seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $days  = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $mins  = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    return $mins . 'm';
}

// -----------------------------------------------------------------------------
// Analytics delivery (Wave 3): shared CSV export streamer + scheduled/emailed
// report rendering. Kept alongside the report helpers so the web pages
// (agent/reports/*), the ?export=csv handlers and the cron/report_scheduler.php
// worker all draw the same numbers from the same source functions.
// -----------------------------------------------------------------------------

/**
 * Streams a tabular dataset to the browser as a CSV download and exits. Must be
 * called before any page output (report pages suppress their HTML chrome when
 * ?export=csv is set, see agent/reports/includes/inc_all_reports.php).
 *
 * $filename : suggested download name (sanitised to a safe basename).
 * $header   : flat array of column titles (omitted from output if empty).
 * $rows     : array of flat arrays, one per data row.
 *
 * Numeric values are written verbatim; text values that begin with a spreadsheet
 * formula trigger (= + - @) are prefixed with an apostrophe to neutralise CSV
 * formula injection without corrupting genuine negative numbers.
 */
function report_send_csv(string $filename, array $header, array $rows)
{
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    if ($filename === '' || strtolower(substr($filename, -4)) !== '.csv') {
        $filename = ($filename === '' ? 'report' : $filename) . '.csv';
    }

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    $sanitize = static function ($v) {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        $v = (string) ($v ?? '');
        if ($v !== '' && !is_numeric($v) && in_array($v[0], ['=', '+', '-', '@'], true)) {
            $v = "'" . $v;
        }
        return $v;
    };

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens accented characters correctly.
    fwrite($out, "\xEF\xBB\xBF");
    if (!empty($header)) {
        fputcsv($out, array_map($sanitize, $header));
    }
    foreach ($rows as $row) {
        fputcsv($out, array_map($sanitize, (array) $row));
    }
    fclose($out);
    exit;
}

/**
 * Single source of truth for which reports can be scheduled/emailed. Maps the
 * schedule key (stored in report_schedules.schedule_report) to a display label.
 * Used by agent/reports/schedules.php (dropdown + validation), the scheduler cron
 * and report_render_email_html().
 */
function report_schedulable_reports()
{
    return [
        'service_desk'           => 'Service Desk & SLA',
        'technician_performance' => 'Technician Performance',
        'mrr'                    => 'MRR & Forecast',
        'income_summary'         => 'Income Summary',
        'expense_summary'        => 'Expense Summary',
        'ticket_summary'         => 'Ticket Summary',
        'clients_with_balance'   => 'Clients with a Balance (AR Aging)',
        'csat'                   => 'CSAT',
    ];
}

/**
 * Renders a headline summary of a report to an HTML email body. Returns
 * ['subject' => ..., 'html' => ...] or null for an unknown report key. Draws its
 * figures from the same getXxxReport() helpers the web pages use so an emailed
 * summary reconciles with the interactive report. Currency is formatted with the
 * global $currency_format when available (cron sets it), else plain 2dp.
 */
function report_render_email_html(mysqli $mysqli, $report_key)
{
    global $currency_format, $company_currency, $session_company_currency, $company_name, $session_company_name;

    $reports = report_schedulable_reports();
    if (!isset($reports[$report_key])) {
        return null;
    }
    $label = $reports[$report_key];
    $brand = $company_name ?? ($session_company_name ?? 'ITFlow');
    $ccy   = $session_company_currency ?? $company_currency ?? 'USD';

    $money = static function ($v) use ($currency_format, $ccy) {
        if (isset($currency_format) && $currency_format instanceof NumberFormatter) {
            return numfmt_format_currency($currency_format, (float) $v, $ccy);
        }
        return number_format((float) $v, 2);
    };

    $today = date('Y-m-d');
    $rows  = [];

    switch ($report_key) {
        case 'service_desk': {
            $from = date('Y-m-01');
            $r = getServiceDeskReport($mysqli, $from, $today);
            $rows[] = ['Window', "$from to $today"];
            $rows[] = ['Opened', intval($r['totals']['opened'])];
            $rows[] = ['Resolved', intval($r['totals']['resolved'])];
            $rows[] = ['Open now', intval($r['totals']['open_now'])];
            $rows[] = ['Response SLA', $r['sla']['response_pct'] !== null ? $r['sla']['response_pct'] . '%' : '-'];
            $rows[] = ['Resolution SLA', $r['sla']['resolution_pct'] !== null ? $r['sla']['resolution_pct'] . '%' : '-'];
            $rows[] = ['CSAT (avg rating)', $r['csat']['avg_rating'] !== null ? $r['csat']['avg_rating'] . ' / 5' : '-'];
            break;
        }
        case 'technician_performance': {
            $from = date('Y-m-01');
            $r = getTechnicianPerformanceReport($mysqli, $from, $today);
            $rows[] = ['Window', "$from to $today"];
            $rows[] = ['Billable hours', number_format($r['totals']['billable_seconds'] / 3600, 1)];
            $rows[] = ['Non-billable hours', number_format($r['totals']['nonbillable_seconds'] / 3600, 1)];
            $rows[] = ['Team utilization', $r['totals']['team_utilization_pct'] !== null ? $r['totals']['team_utilization_pct'] . '%' : '-'];
            $rows[] = ['Billable value', $money($r['totals']['billable_value'])];
            $rows[] = ['Tickets closed', intval($r['totals']['tickets_closed'])];
            $rows[] = ['Team CSAT (avg rating)', $r['totals']['csat_avg_rating'] !== null ? $r['totals']['csat_avg_rating'] . ' / 5' : '-'];
            break;
        }
        case 'mrr': {
            $r = getMrrReport($mysqli);
            $rows[] = ['Current MRR', $money($r['mrr'])];
            $rows[] = ['ARR', $money($r['arr'])];
            $rows[] = ['Active schedules', intval($r['active_count'])];
            $rows[] = ['Net new MRR (30d)', $money($r['movement']['net_new_mrr'])];
            break;
        }
        case 'clients_with_balance': {
            $r = getArAgingReport($mysqli);
            $b = $r['buckets'];
            $rows[] = ['As of', $r['as_of']];
            $rows[] = ['0-30 days', $money($b['b_0_30'])];
            $rows[] = ['31-60 days', $money($b['b_31_60'])];
            $rows[] = ['61-90 days', $money($b['b_61_90'])];
            $rows[] = ['90+ days', $money($b['b_90_plus'])];
            $rows[] = ['Total outstanding', $money($b['total'])];
            break;
        }
        case 'income_summary': {
            $year = intval(date('Y'));
            $p = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT COALESCE(SUM(payment_amount),0) AS v FROM payments p JOIN invoices i ON i.invoice_id = p.payment_invoice_id
                 WHERE YEAR(p.payment_date) = $year"));
            $rv = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT COALESCE(SUM(revenue_amount),0) AS v FROM revenues WHERE revenue_category_id > 0 AND YEAR(revenue_date) = $year"));
            $rows[] = ['Year', $year];
            $rows[] = ['Total income (YTD)', $money(floatval($p['v']) + floatval($rv['v']))];
            break;
        }
        case 'expense_summary': {
            $year = intval(date('Y'));
            $e = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT COALESCE(SUM(expense_amount),0) AS v FROM expenses WHERE expense_vendor_id > 0 AND YEAR(expense_date) = $year"));
            $rows[] = ['Year', $year];
            $rows[] = ['Total expense (YTD)', $money(floatval($e['v']))];
            break;
        }
        case 'ticket_summary': {
            $year = intval(date('Y'));
            $t = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT COUNT(ticket_id) AS c FROM tickets WHERE YEAR(ticket_created_at) = $year"));
            $rows[] = ['Year', $year];
            $rows[] = ['Tickets raised (YTD)', intval($t['c'])];
            break;
        }
        case 'csat': {
            $from = date('Y-m-01');
            $r = getCsatReport($mysqli, $from, $today);
            $rows[] = ['Window', "$from to $today"];
            $rows[] = ['Avg rating', $r['summary']['avg_rating'] !== null ? $r['summary']['avg_rating'] . ' / 5' : '-'];
            $rows[] = ['Response rate', $r['summary']['response_rate_pct'] !== null ? $r['summary']['response_rate_pct'] . '%' : '-'];
            $rows[] = ['Satisfied (' . csatFaceEmoji(4) . csatFaceEmoji(5) . ')', $r['summary']['satisfied_pct'] !== null ? $r['summary']['satisfied_pct'] . '%' : '-'];
            $rows[] = ['Needs follow-up', intval($r['summary']['needs_followup_count'])];
            break;
        }
        default:
            return null;
    }

    $subject = "$brand report: $label ($today)";

    $html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333">';
    $html .= '<h2 style="color:#2c3e50;margin-bottom:4px">' . htmlspecialchars($label) . '</h2>';
    $html .= '<p style="color:#777;margin-top:0">Automated report from ' . htmlspecialchars($brand)
           . ' &middot; generated ' . date('Y-m-d H:i') . '</p>';
    $html .= '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;min-width:340px">';
    foreach ($rows as $i => $r2) {
        $bg = ($i % 2 === 0) ? '#f7f7f7' : '#ffffff';
        $html .= '<tr style="background:' . $bg . '">'
              . '<td style="border:1px solid #e0e0e0;font-weight:bold">' . htmlspecialchars((string) $r2[0]) . '</td>'
              . '<td style="border:1px solid #e0e0e0;text-align:right">' . htmlspecialchars((string) $r2[1]) . '</td>'
              . '</tr>';
    }
    $html .= '</table>';
    $html .= '<p style="color:#999;font-size:12px;margin-top:16px">Log in to ' . htmlspecialchars($brand)
           . ' to view the full interactive report and export the underlying data as CSV.</p>';
    $html .= '</div>';

    return ['subject' => $subject, 'html' => $html];
}

/**
 * Service-desk & SLA operations report data (set-based, no per-row PHP loops for aggregates).
 * Shared by agent/reports/service_desk.php (web) and api/v1/reports/service_desk.php (JSON)
 * so both surfaces return identical numbers. $date_from/$date_to are 'Y-m-d' strings and are
 * validated here (defence in depth); metrics that are point-in-time snapshots (open-ticket
 * aging, current per-tech open workload) intentionally ignore the range.
 */
function getServiceDeskReport(mysqli $mysqli, $date_from, $date_to, ?int $client_id = null)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_from)) {
        $date_from = date('Y-01-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_to)) {
        $date_to = date('Y-m-d');
    }
    $from_dt = "$date_from 00:00:00";
    $to_dt   = "$date_to 23:59:59";
    // $client_id restricts every query below to one client - used by the API when the
    // caller is authenticated via a client-scoped legacy key. The classic web report
    // always calls this with $client_id = null (company-wide).
    $client_clause   = $client_id !== null ? " AND ticket_client_id = " . intval($client_id) : '';
    $client_clause_t = $client_id !== null ? " AND t.ticket_client_id = " . intval($client_id) : '';
    $created_range = "ticket_created_at BETWEEN '$from_dt' AND '$to_dt'$client_clause";

    // --- Ticket volume trend (opened vs resolved, grouped by month, set-based) ---
    $opened_by_month = [];
    $res = mysqli_query($mysqli,
        "SELECT DATE_FORMAT(ticket_created_at, '%Y-%m') AS ym, COUNT(ticket_id) AS c
         FROM tickets
         WHERE $created_range AND ticket_archived_at IS NULL
         GROUP BY ym");
    while ($row = mysqli_fetch_assoc($res)) {
        $opened_by_month[$row['ym']] = intval($row['c']);
    }

    $resolved_by_month = [];
    $res = mysqli_query($mysqli,
        "SELECT DATE_FORMAT(ticket_closed_at, '%Y-%m') AS ym, COUNT(ticket_id) AS c
         FROM tickets
         WHERE ticket_closed_at BETWEEN '$from_dt' AND '$to_dt' AND ticket_archived_at IS NULL$client_clause
         GROUP BY ym");
    while ($row = mysqli_fetch_assoc($res)) {
        $resolved_by_month[$row['ym']] = intval($row['c']);
    }

    // Build a contiguous month axis from the first to the last month in range (capped at 60).
    $labels = $opened_series = $resolved_series = $backlog_series = [];
    $cursor = new DateTime(substr($date_from, 0, 7) . '-01');
    $last   = new DateTime(substr($date_to, 0, 7) . '-01');
    $running = 0;
    $guard = 0;
    while ($cursor <= $last && $guard < 60) {
        $key = $cursor->format('Y-m');
        $opened   = $opened_by_month[$key] ?? 0;
        $resolved = $resolved_by_month[$key] ?? 0;
        $running += ($opened - $resolved);
        $labels[]          = $cursor->format('M Y');
        $opened_series[]   = $opened;
        $resolved_series[] = $resolved;
        $backlog_series[]  = $running;
        $cursor->modify('+1 month');
        $guard++;
    }

    // --- Open-ticket aging buckets (point-in-time snapshot of all open tickets) ---
    $open_open = "ticket_closed_at IS NULL AND ticket_archived_at IS NULL$client_clause";
    $aging = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, ticket_created_at, NOW()) < 24  THEN 1 ELSE 0 END) AS b0,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, ticket_created_at, NOW()) >= 24  AND TIMESTAMPDIFF(HOUR, ticket_created_at, NOW()) < 72  THEN 1 ELSE 0 END) AS b1,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, ticket_created_at, NOW()) >= 72  AND TIMESTAMPDIFF(HOUR, ticket_created_at, NOW()) < 168 THEN 1 ELSE 0 END) AS b2,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, ticket_created_at, NOW()) >= 168 AND TIMESTAMPDIFF(HOUR, ticket_created_at, NOW()) < 720 THEN 1 ELSE 0 END) AS b3,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, ticket_created_at, NOW()) >= 720 THEN 1 ELSE 0 END) AS b4
         FROM tickets WHERE $open_open"));
    $aging_buckets = [
        ['label' => '0-1d',  'count' => intval($aging['b0'] ?? 0)],
        ['label' => '1-3d',  'count' => intval($aging['b1'] ?? 0)],
        ['label' => '3-7d',  'count' => intval($aging['b2'] ?? 0)],
        ['label' => '7-30d', 'count' => intval($aging['b3'] ?? 0)],
        ['label' => '30d+',  'count' => intval($aging['b4'] ?? 0)],
    ];

    // --- SLA compliance (tickets created in range that carry an SLA due) ---
    $sla_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT
            SUM(CASE WHEN ticket_sla_response_due IS NOT NULL THEN 1 ELSE 0 END) AS resp_total,
            SUM(CASE WHEN ticket_sla_response_due IS NOT NULL AND ticket_first_response_at IS NOT NULL
                     AND ticket_first_response_at <= ticket_sla_response_due THEN 1 ELSE 0 END) AS resp_met,
            SUM(CASE WHEN ticket_sla_resolution_due IS NOT NULL THEN 1 ELSE 0 END) AS reso_total,
            SUM(CASE WHEN ticket_sla_resolution_due IS NOT NULL AND ticket_resolved_at IS NOT NULL
                     AND ticket_resolved_at <= ticket_sla_resolution_due THEN 1 ELSE 0 END) AS reso_met
         FROM tickets WHERE $created_range AND ticket_archived_at IS NULL"));
    $resp_total = intval($sla_row['resp_total'] ?? 0);
    $resp_met   = intval($sla_row['resp_met'] ?? 0);
    $reso_total = intval($sla_row['reso_total'] ?? 0);
    $reso_met   = intval($sla_row['reso_met'] ?? 0);
    $sla = [
        'response_total'   => $resp_total,
        'response_met'     => $resp_met,
        'response_pct'     => $resp_total > 0 ? round($resp_met / $resp_total * 100, 1) : null,
        'resolution_total' => $reso_total,
        'resolution_met'   => $reso_met,
        'resolution_pct'   => $reso_total > 0 ? round($reso_met / $reso_total * 100, 1) : null,
    ];

    // --- CSAT (1-5 ticket_csat_rating, tickets created in range) ---
    $csat_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(ticket_csat_rating) AS rated, AVG(ticket_csat_rating) AS avg_rating,
            SUM(CASE WHEN ticket_csat_rating >= 4 THEN 1 ELSE 0 END) AS satisfied
         FROM tickets WHERE $created_range AND ticket_archived_at IS NULL"));
    $csat_rated = intval($csat_row['rated'] ?? 0);
    $csat = [
        'rated'         => $csat_rated,
        'avg_rating'    => $csat_row['avg_rating'] !== null ? round(floatval($csat_row['avg_rating']), 2) : null,
        'satisfied'     => intval($csat_row['satisfied'] ?? 0),
        'satisfied_pct' => $csat_rated > 0 ? round(intval($csat_row['satisfied']) / $csat_rated * 100, 1) : null,
    ];

    // --- Avg first-response & resolution time by priority (tickets created in range) ---
    $by_priority = [];
    $res = mysqli_query($mysqli,
        "SELECT ticket_priority,
            COUNT(ticket_id) AS total,
            AVG(CASE WHEN ticket_first_response_at IS NOT NULL
                THEN TIMESTAMPDIFF(SECOND, ticket_created_at, ticket_first_response_at) END) AS avg_response_seconds,
            AVG(CASE WHEN ticket_resolved_at IS NOT NULL
                THEN TIMESTAMPDIFF(SECOND, ticket_created_at, ticket_resolved_at) END) AS avg_resolve_seconds
         FROM tickets
         WHERE $created_range AND ticket_archived_at IS NULL
           AND ticket_priority IS NOT NULL AND ticket_priority <> ''
         GROUP BY ticket_priority
         ORDER BY FIELD(ticket_priority, 'Critical', 'High', 'Medium', 'Low')");
    while ($row = mysqli_fetch_assoc($res)) {
        $by_priority[] = [
            'priority'             => $row['ticket_priority'],
            'total'                => intval($row['total']),
            'avg_response_seconds' => $row['avg_response_seconds'] !== null ? intval($row['avg_response_seconds']) : null,
            'avg_resolve_seconds'  => $row['avg_resolve_seconds'] !== null ? intval($row['avg_resolve_seconds']) : null,
        ];
    }

    // --- Per-technician workload: current open (snapshot) + resolved in range ---
    $techs = [];
    $res = mysqli_query($mysqli,
        "SELECT t.ticket_assigned_to AS uid, u.user_name, COUNT(t.ticket_id) AS c
         FROM tickets t LEFT JOIN users u ON u.user_id = t.ticket_assigned_to
         WHERE t.ticket_closed_at IS NULL AND t.ticket_archived_at IS NULL AND t.ticket_assigned_to > 0$client_clause_t
         GROUP BY t.ticket_assigned_to, u.user_name");
    while ($row = mysqli_fetch_assoc($res)) {
        $uid = intval($row['uid']);
        $techs[$uid] = [
            'user_id'             => $uid,
            'name'                => $row['user_name'] ?? 'User #' . $uid,
            'open'                => intval($row['c']),
            'resolved'            => 0,
            'avg_resolve_seconds' => null,
        ];
    }
    $res = mysqli_query($mysqli,
        "SELECT t.ticket_assigned_to AS uid, u.user_name, COUNT(t.ticket_id) AS c,
            AVG(TIMESTAMPDIFF(SECOND, t.ticket_created_at, t.ticket_closed_at)) AS avg_res
         FROM tickets t LEFT JOIN users u ON u.user_id = t.ticket_assigned_to
         WHERE t.ticket_closed_at IS NOT NULL
           AND t.ticket_closed_at BETWEEN '$from_dt' AND '$to_dt'
           AND t.ticket_archived_at IS NULL AND t.ticket_assigned_to > 0$client_clause_t
         GROUP BY t.ticket_assigned_to, u.user_name");
    while ($row = mysqli_fetch_assoc($res)) {
        $uid = intval($row['uid']);
        if (!isset($techs[$uid])) {
            $techs[$uid] = [
                'user_id'             => $uid,
                'name'                => $row['user_name'] ?? 'User #' . $uid,
                'open'                => 0,
                'resolved'            => 0,
                'avg_resolve_seconds' => null,
            ];
        }
        $techs[$uid]['resolved'] = intval($row['c']);
        $techs[$uid]['avg_resolve_seconds'] = $row['avg_res'] !== null ? intval($row['avg_res']) : null;
    }
    $by_technician = array_values($techs);
    usort($by_technician, function ($a, $b) {
        if ($b['open'] !== $a['open']) return $b['open'] - $a['open'];
        return $b['resolved'] - $a['resolved'];
    });

    // --- Headline totals ---
    $total_opened   = array_sum($opened_series);
    $total_resolved = array_sum($resolved_series);
    $open_now = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(ticket_id) AS c FROM tickets WHERE $open_open"))['c']);

    return [
        'date_from' => $date_from,
        'date_to'   => $date_to,
        'totals'    => [
            'opened'   => $total_opened,
            'resolved' => $total_resolved,
            'open_now' => $open_now,
        ],
        'volume' => [
            'labels'   => $labels,
            'opened'   => $opened_series,
            'resolved' => $resolved_series,
            'backlog'  => $backlog_series,
        ],
        'aging'         => $aging_buckets,
        'sla'           => $sla,
        'csat'          => $csat,
        'by_priority'   => $by_priority,
        'by_technician' => $by_technician,
    ];
}

// -----------------------------------------------------------------------------
// Analytics / reporting helpers (Wave 2). All set-based (no per-row PHP loops for
// aggregates) and shared between the web report pages under agent/reports/ and the
// JSON endpoints under api/v1/reports/ so both surfaces return identical numbers.
// -----------------------------------------------------------------------------

// Default technician capacity assumption used for utilization %. 8h/day x 5 days.
// TODO: make this configurable per-company (e.g. a settings column or per-user
// contracted hours) instead of a hard-coded constant. Deliberately NOT a schema
// change here — kept as a documented default so the metric is honest and tunable.
if (!defined('REPORT_CAPACITY_HOURS_PER_DAY')) {
    define('REPORT_CAPACITY_HOURS_PER_DAY', 8);
}
if (!defined('REPORT_CAPACITY_DAYS_PER_WEEK')) {
    define('REPORT_CAPACITY_DAYS_PER_WEEK', 5);
}

/**
 * Counts business days (Mon..Fri by default, controlled by REPORT_CAPACITY_DAYS_PER_WEEK)
 * inclusive between two 'Y-m-d' dates. Used to derive a capacity baseline for utilization.
 */
function report_business_days($date_from, $date_to)
{
    try {
        $start = new DateTime(substr((string) $date_from, 0, 10));
        $end   = new DateTime(substr((string) $date_to, 0, 10));
    } catch (Exception $e) {
        return 0;
    }
    if ($end < $start) {
        return 0;
    }
    $dpw = defined('REPORT_CAPACITY_DAYS_PER_WEEK') ? REPORT_CAPACITY_DAYS_PER_WEEK : 5;
    $days = 0;
    $guard = 0;
    $cursor = clone $start;
    while ($cursor <= $end && $guard < 3660) {
        if ((int) $cursor->format('N') <= $dpw) {
            $days++;
        }
        $cursor->modify('+1 day');
        $guard++;
    }
    return $days;
}

/**
 * Shared CSAT aggregation core, grouped by an arbitrary tickets column (e.g.
 * 'ticket_assigned_to' or 'ticket_client_id'). $group_column is always a hardcoded
 * literal supplied by the calling report function, never request input. Tickets are
 * scoped by ticket_closed_at (when the work finished), matching the cohort basis the
 * rest of the service-desk/technician reports already use. Returns raw sums (not
 * pre-divided) so callers can correctly roll multiple groups up into a weighted total
 * instead of averaging per-group percentages.
 *
 * @return array<int, array{rated_count:int, sum_rating:int, satisfied_count:int, comment_count:int}>
 */
function getCsatAggregateByGroup(mysqli $mysqli, string $group_column, string $from_dt, string $to_dt, ?int $client_id = null, int $satisfied_threshold = 4): array
{
    $allowed_columns = ['ticket_assigned_to', 'ticket_client_id'];
    if (!in_array($group_column, $allowed_columns, true)) {
        return [];
    }
    $client_clause = $client_id !== null ? " AND ticket_client_id = " . intval($client_id) : '';

    $out = [];
    $res = mysqli_query($mysqli,
        "SELECT $group_column AS grp,
            COUNT(ticket_csat_rating) AS rated_count,
            SUM(ticket_csat_rating) AS sum_rating,
            SUM(CASE WHEN ticket_csat_rating >= $satisfied_threshold THEN 1 ELSE 0 END) AS satisfied_count,
            SUM(CASE WHEN ticket_csat_comment IS NOT NULL AND ticket_csat_comment <> '' THEN 1 ELSE 0 END) AS comment_count
         FROM tickets
         WHERE ticket_csat_rated_at BETWEEN '$from_dt' AND '$to_dt'
           AND ticket_archived_at IS NULL AND $group_column > 0$client_clause
         GROUP BY $group_column");
    while ($row = mysqli_fetch_assoc($res)) {
        $grp = intval($row['grp']);
        $out[$grp] = [
            'rated_count'     => intval($row['rated_count']),
            'sum_rating'      => intval($row['sum_rating'] ?? 0),
            'satisfied_count' => intval($row['satisfied_count']),
            'comment_count'   => intval($row['comment_count']),
        ];
    }
    return $out;
}

/**
 * Technician performance / utilization report (set-based). Billable vs non-billable
 * hours (joined to labor_types), tickets closed, avg handle time, CSAT and utilization
 * against a business-time capacity baseline. $date_from/$date_to are 'Y-m-d' strings.
 */
function getTechnicianPerformanceReport(mysqli $mysqli, $date_from, $date_to, ?int $client_id = null)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_from)) {
        $date_from = date('Y-01-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_to)) {
        $date_to = date('Y-m-d');
    }
    $from_dt = "$date_from 00:00:00";
    $to_dt   = "$date_to 23:59:59";

    // $client_id restricts every query below to one client - used by the API when the
    // caller is authenticated via a client-scoped legacy key. The classic web report
    // always calls this with $client_id = null (company-wide). ticket_replies carries no
    // client column of its own, so it's joined to tickets only when scoping is active.
    $client_clause         = $client_id !== null ? " AND ticket_client_id = " . intval($client_id) : '';
    $client_join_ticket_id = $client_id !== null ? " JOIN tickets trt ON trt.ticket_id = tr.ticket_reply_ticket_id AND trt.ticket_client_id = " . intval($client_id) : '';

    $hpd = defined('REPORT_CAPACITY_HOURS_PER_DAY') ? REPORT_CAPACITY_HOURS_PER_DAY : 8;
    $business_days    = report_business_days($date_from, $date_to);
    $capacity_seconds = $business_days * $hpd * 3600;

    // Base agent list (active agents), keyed by user_id.
    $techs = [];
    $res = mysqli_query($mysqli,
        "SELECT user_id, user_name FROM users
         WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
         ORDER BY user_name ASC");
    while ($row = mysqli_fetch_assoc($res)) {
        $uid = intval($row['user_id']);
        $techs[$uid] = [
            'user_id'             => $uid,
            'name'                => $row['user_name'],
            'billable_seconds'    => 0,
            'nonbillable_seconds' => 0,
            'total_seconds'       => 0,
            'billable_value'      => 0.0,
            'tickets_closed'      => 0,
            'avg_handle_seconds'  => null,
            'csat_rated_count'    => 0,
            'csat_avg_rating'     => null,
            'csat_satisfied_pct' => null,
            'csat_comment_count'  => 0,
            'utilization_pct'     => null,
        ];
    }

    // Time worked in range, split billable/non-billable + billable value (join labor_types).
    // A reply is billable when its labor type carries a rate > 0; NULL/0 labor type = non-billable.
    $res = mysqli_query($mysqli,
        "SELECT tr.ticket_reply_by AS uid,
            SUM(TIME_TO_SEC(tr.ticket_reply_time_worked)) AS total_secs,
            SUM(CASE WHEN lt.labor_type_rate > 0 THEN TIME_TO_SEC(tr.ticket_reply_time_worked) ELSE 0 END) AS billable_secs,
            SUM(TIME_TO_SEC(tr.ticket_reply_time_worked) / 3600 * COALESCE(lt.labor_type_rate, 0)) AS billable_value
         FROM ticket_replies tr
         LEFT JOIN labor_types lt ON lt.labor_type_id = tr.ticket_reply_labor_type_id
         $client_join_ticket_id
         WHERE tr.ticket_reply_time_worked IS NOT NULL
           AND tr.ticket_reply_created_at BETWEEN '$from_dt' AND '$to_dt'
         GROUP BY tr.ticket_reply_by");
    while ($row = mysqli_fetch_assoc($res)) {
        $uid = intval($row['uid']);
        if (!isset($techs[$uid])) {
            continue;
        }
        $total    = intval($row['total_secs']);
        $billable = intval($row['billable_secs']);
        $techs[$uid]['total_seconds']       = $total;
        $techs[$uid]['billable_seconds']    = $billable;
        $techs[$uid]['nonbillable_seconds'] = max(0, $total - $billable);
        $techs[$uid]['billable_value']      = round(floatval($row['billable_value']), 2);
        $techs[$uid]['utilization_pct']     = $capacity_seconds > 0 ? round($total / $capacity_seconds * 100, 1) : null;
    }

    // Tickets closed in range, by assigned technician.
    $res = mysqli_query($mysqli,
        "SELECT ticket_assigned_to AS uid,
            COUNT(ticket_id) AS closed,
            AVG(TIMESTAMPDIFF(SECOND, ticket_created_at, ticket_closed_at)) AS avg_res
         FROM tickets
         WHERE ticket_closed_at BETWEEN '$from_dt' AND '$to_dt'
           AND ticket_archived_at IS NULL AND ticket_assigned_to > 0$client_clause
         GROUP BY ticket_assigned_to");
    while ($row = mysqli_fetch_assoc($res)) {
        $uid = intval($row['uid']);
        if (!isset($techs[$uid])) {
            continue;
        }
        $techs[$uid]['tickets_closed'] = intval($row['closed']);
    }

    // CSAT, by assigned technician - via the shared aggregation helper so this
    // matches the new CSAT report's per-technician numbers exactly.
    $csat_by_tech = getCsatAggregateByGroup($mysqli, 'ticket_assigned_to', $from_dt, $to_dt, $client_id);
    foreach ($csat_by_tech as $uid => $agg) {
        if (!isset($techs[$uid])) {
            continue;
        }
        $techs[$uid]['csat_rated_count']   = $agg['rated_count'];
        $techs[$uid]['csat_avg_rating']    = $agg['rated_count'] > 0 ? round($agg['sum_rating'] / $agg['rated_count'], 2) : null;
        $techs[$uid]['csat_satisfied_pct'] = $agg['rated_count'] > 0 ? round($agg['satisfied_count'] / $agg['rated_count'] * 100, 1) : null;
        $techs[$uid]['csat_comment_count'] = $agg['comment_count'];
    }

    // Avg handle time = total time worked / tickets closed (seconds per closed ticket).
    foreach ($techs as $uid => &$t) {
        $t['avg_handle_seconds'] = $t['tickets_closed'] > 0
            ? intval(round($t['total_seconds'] / $t['tickets_closed']))
            : null;
    }
    unset($t);

    $technicians = array_values($techs);
    usort($technicians, function ($a, $b) {
        return $b['total_seconds'] - $a['total_seconds'];
    });

    $num_techs   = count($technicians);
    $sum_total   = array_sum(array_column($technicians, 'total_seconds'));
    $team_cap    = $capacity_seconds * $num_techs;
    $totals = [
        'billable_seconds'    => array_sum(array_column($technicians, 'billable_seconds')),
        'nonbillable_seconds' => array_sum(array_column($technicians, 'nonbillable_seconds')),
        'total_seconds'       => $sum_total,
        'billable_value'      => round(array_sum(array_column($technicians, 'billable_value')), 2),
        'tickets_closed'      => array_sum(array_column($technicians, 'tickets_closed')),
        'csat_rated_count'    => array_sum(array_column($technicians, 'csat_rated_count')),
        'team_utilization_pct' => $team_cap > 0 ? round($sum_total / $team_cap * 100, 1) : null,
    ];
    // Roll up from the helper's raw per-tech sums (not the already-rounded per-tech
    // avg/pct display values), so this is a true weighted average rather than an
    // average-of-averages.
    $csat_sum_rating_total = array_sum(array_column($csat_by_tech, 'sum_rating'));
    $csat_satisfied_total  = array_sum(array_column($csat_by_tech, 'satisfied_count'));
    $totals['csat_avg_rating']    = $totals['csat_rated_count'] > 0 ? round($csat_sum_rating_total / $totals['csat_rated_count'], 2) : null;
    $totals['csat_satisfied_pct'] = $totals['csat_rated_count'] > 0 ? round($csat_satisfied_total / $totals['csat_rated_count'] * 100, 1) : null;

    return [
        'date_from'   => $date_from,
        'date_to'     => $date_to,
        'capacity'    => [
            'hours_per_day'          => $hpd,
            'days_per_week'          => defined('REPORT_CAPACITY_DAYS_PER_WEEK') ? REPORT_CAPACITY_DAYS_PER_WEEK : 5,
            'business_days'          => $business_days,
            'capacity_seconds'       => $capacity_seconds,
            'capacity_hours_per_tech' => $business_days * $hpd,
        ],
        'totals'      => $totals,
        'technicians' => $technicians,
    ];
}

/**
 * Customer satisfaction (CSAT) report (set-based). Headline KPIs, 1-5 rating
 * distribution, a monthly trend of when ratings were given, per-technician and
 * per-client breakdowns (via getCsatAggregateByGroup()), and a raw feedback feed.
 * $date_from/$date_to are 'Y-m-d' strings; KPI/distribution/breakdown tables are
 * scoped to tickets *closed* in range (same cohort basis as getServiceDeskReport()/
 * getTechnicianPerformanceReport()) while the trend is scoped to when the rating was
 * actually submitted (ticket_csat_rated_at) - a deliberately different lens ("when
 * sentiment was expressed" vs "of the work finished this period, how did it land").
 */
function getCsatReport(mysqli $mysqli, $date_from, $date_to, ?int $client_id = null, int $low_rating_threshold = 2): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_from)) {
        $date_from = date('Y-01-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_to)) {
        $date_to = date('Y-m-d');
    }
    $from_dt = "$date_from 00:00:00";
    $to_dt   = "$date_to 23:59:59";
    $client_clause = $client_id !== null ? " AND ticket_client_id = " . intval($client_id) : '';
    // Two distinct cohorts, because "closed in period" and "rated in period" are
    // genuinely different questions - a ticket can be rated long after it closes
    // (email reminder, or just real-world lag). $closed_range answers "of tickets
    // that closed in this window, what fraction are (by now) rated" (response
    // rate). $rated_range answers "what ratings did we actually receive in this
    // window" - used everywhere else (avg/satisfied/distribution/trend/
    // per-tech/per-client/feedback feed), since that's what a period-based CSAT
    // report is actually asking.
    $closed_range = "ticket_closed_at BETWEEN '$from_dt' AND '$to_dt' AND ticket_archived_at IS NULL$client_clause";
    $rated_range  = "ticket_csat_rated_at BETWEEN '$from_dt' AND '$to_dt' AND ticket_archived_at IS NULL$client_clause";

    // --- Headline summary: ratings received in range ---
    $sum_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(ticket_id) AS rated,
            AVG(ticket_csat_rating) AS avg_rating,
            SUM(CASE WHEN ticket_csat_rating >= 4 THEN 1 ELSE 0 END) AS satisfied
         FROM tickets WHERE $rated_range"));
    $rated = intval($sum_row['rated'] ?? 0);

    // Response rate: of tickets CLOSED in range, what fraction have (by now) been
    // rated - independent of when the rating was actually submitted, so this uses
    // the closed-ticket cohort rather than $rated_range.
    $closed_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(ticket_id) AS total_closed, COUNT(ticket_csat_rating) AS closed_rated
         FROM tickets WHERE $closed_range"));
    $total_closed = intval($closed_row['total_closed'] ?? 0);
    $closed_rated = intval($closed_row['closed_rated'] ?? 0);

    // Needs-follow-up is a current, not date-scoped, count: any ticket sitting
    // reopened/still-open after a low rating right now, regardless of when it was
    // rated - it's a work-queue indicator, not a period metric.
    $followup_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(ticket_id) AS c FROM tickets
         WHERE ticket_csat_rating IS NOT NULL AND ticket_csat_rating <= " . intval($low_rating_threshold) . "
           AND ticket_status != 5 AND ticket_archived_at IS NULL$client_clause"));

    $summary = [
        'total_closed'          => $total_closed,
        'rated'                 => $rated,
        'response_rate_pct'     => $total_closed > 0 ? round($closed_rated / $total_closed * 100, 1) : null,
        'avg_rating'            => $sum_row['avg_rating'] !== null ? round(floatval($sum_row['avg_rating']), 2) : null,
        'satisfied_pct'         => $rated > 0 ? round(intval($sum_row['satisfied']) / $rated * 100, 1) : null,
        'needs_followup_count'  => intval($followup_row['c'] ?? 0),
    ];

    // --- 1-5 distribution (ratings received in range) ---
    $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $res = mysqli_query($mysqli,
        "SELECT ticket_csat_rating AS r, COUNT(ticket_id) AS c FROM tickets
         WHERE $rated_range AND ticket_csat_rating IS NOT NULL
         GROUP BY ticket_csat_rating");
    while ($row = mysqli_fetch_assoc($res)) {
        $r = intval($row['r']);
        if (isset($distribution[$r])) {
            $distribution[$r] = intval($row['c']);
        }
    }

    // --- Monthly trend of when ratings were submitted (contiguous month axis, capped at 60) ---
    $trend_by_month = [];
    $res = mysqli_query($mysqli,
        "SELECT DATE_FORMAT(ticket_csat_rated_at, '%Y-%m') AS ym,
            COUNT(ticket_id) AS c, AVG(ticket_csat_rating) AS avg_rating
         FROM tickets
         WHERE ticket_csat_rated_at BETWEEN '$from_dt' AND '$to_dt'
           AND ticket_archived_at IS NULL$client_clause
         GROUP BY ym");
    while ($row = mysqli_fetch_assoc($res)) {
        $trend_by_month[$row['ym']] = [
            'count'      => intval($row['c']),
            'avg_rating' => $row['avg_rating'] !== null ? round(floatval($row['avg_rating']), 2) : null,
        ];
    }
    $trend = [];
    $cursor = new DateTime(substr($date_from, 0, 7) . '-01');
    $last   = new DateTime(substr($date_to, 0, 7) . '-01');
    $guard  = 0;
    while ($cursor <= $last && $guard < 60) {
        $key = $cursor->format('Y-m');
        $trend[] = [
            'label'      => $cursor->format('M Y'),
            'count'      => $trend_by_month[$key]['count'] ?? 0,
            'avg_rating' => $trend_by_month[$key]['avg_rating'] ?? null,
        ];
        $cursor->modify('+1 month');
        $guard++;
    }

    // --- Per-technician breakdown ---
    $by_technician = [];
    $csat_by_tech = getCsatAggregateByGroup($mysqli, 'ticket_assigned_to', $from_dt, $to_dt, $client_id);
    if (!empty($csat_by_tech)) {
        $uids = implode(',', array_map('intval', array_keys($csat_by_tech)));
        $res = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_id IN ($uids)");
        $names = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $names[intval($row['user_id'])] = $row['user_name'];
        }
        foreach ($csat_by_tech as $uid => $agg) {
            $by_technician[] = [
                'user_id'       => $uid,
                'name'          => $names[$uid] ?? ('User #' . $uid),
                'rated_count'   => $agg['rated_count'],
                'avg_rating'    => $agg['rated_count'] > 0 ? round($agg['sum_rating'] / $agg['rated_count'], 2) : null,
                'satisfied_pct' => $agg['rated_count'] > 0 ? round($agg['satisfied_count'] / $agg['rated_count'] * 100, 1) : null,
            ];
        }
        usort($by_technician, function ($a, $b) { return $b['rated_count'] - $a['rated_count']; });
    }

    // --- Per-client breakdown (only meaningful company-wide; skip when already client-scoped) ---
    $by_client = [];
    if ($client_id === null) {
        $csat_by_client = getCsatAggregateByGroup($mysqli, 'ticket_client_id', $from_dt, $to_dt, null);
        if (!empty($csat_by_client)) {
            $cids = implode(',', array_map('intval', array_keys($csat_by_client)));
            $res = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_id IN ($cids) AND client_archived_at IS NULL");
            $cnames = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $cnames[intval($row['client_id'])] = $row['client_name'];
            }
            foreach ($csat_by_client as $cid => $agg) {
                if (!isset($cnames[$cid])) {
                    continue;
                }
                $by_client[] = [
                    'client_id'     => $cid,
                    'name'          => $cnames[$cid],
                    'rated_count'   => $agg['rated_count'],
                    'avg_rating'    => $agg['rated_count'] > 0 ? round($agg['sum_rating'] / $agg['rated_count'], 2) : null,
                    'satisfied_pct' => $agg['rated_count'] > 0 ? round($agg['satisfied_count'] / $agg['rated_count'] * 100, 1) : null,
                ];
            }
            usort($by_client, function ($a, $b) { return $b['rated_count'] - $a['rated_count']; });
        }
    }

    // --- Raw feedback feed (capped, full range available via CSV export in the web page) ---
    $feedback = [];
    $res = mysqli_query($mysqli,
        "SELECT t.ticket_id, t.ticket_number, t.ticket_csat_rating, t.ticket_csat_comment, t.ticket_csat_rated_at,
            c.client_name, u.user_name AS tech_name
         FROM tickets t
         LEFT JOIN clients c ON c.client_id = t.ticket_client_id
         LEFT JOIN users u ON u.user_id = t.ticket_assigned_to
         WHERE $rated_range AND t.ticket_csat_rating IS NOT NULL
         ORDER BY t.ticket_csat_rated_at DESC
         LIMIT 1000");
    while ($row = mysqli_fetch_assoc($res)) {
        $feedback[] = [
            'ticket_id'   => intval($row['ticket_id']),
            'ticket_number' => intval($row['ticket_number']),
            'client_name' => $row['client_name'],
            'tech_name'   => $row['tech_name'],
            'rating'      => intval($row['ticket_csat_rating']),
            'comment'     => $row['ticket_csat_comment'],
            'rated_at'    => $row['ticket_csat_rated_at'],
        ];
    }

    return [
        'date_from'     => $date_from,
        'date_to'       => $date_to,
        'summary'       => $summary,
        'distribution'  => $distribution,
        'trend'         => $trend,
        'by_technician' => $by_technician,
        'by_client'     => $by_client,
        'feedback'      => $feedback,
    ];
}

/**
 * Included support-issues usage for a client (e.g. residential subscriptions
 * that cover a set number of support issues per month), split remote vs
 * onsite. Always computed live from tickets.ticket_delivery_method /
 * ticket_created_at for the given calendar month - no stored balance/reset
 * cron needed, so it's always correct. Each of 'remote'/'onsite' has
 * 'included' => null when that allowance isn't configured for this client
 * (clients.client_support_issues_included_remote/_onsite is NULL) - callers
 * should treat that as "feature inactive" for that allowance and not render
 * anything for it.
 */
function getClientIncludedIssuesUsage(mysqli $mysqli, int $client_id, ?int $month = null, ?int $year = null): array {
    $month = $month ?? intval(date('n'));
    $year  = $year ?? intval(date('Y'));

    $period_start = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $period_end   = (clone $period_start)->modify('last day of this month')->setTime(23, 59, 59);
    $from_dt = $period_start->format('Y-m-d H:i:s');
    $to_dt   = $period_end->format('Y-m-d H:i:s');

    $client_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT client_support_issues_included_remote, client_support_issues_included_onsite
         FROM clients WHERE client_id = " . intval($client_id)));
    $included_remote = ($client_row && $client_row['client_support_issues_included_remote'] !== null)
        ? intval($client_row['client_support_issues_included_remote']) : null;
    $included_onsite = ($client_row && $client_row['client_support_issues_included_onsite'] !== null)
        ? intval($client_row['client_support_issues_included_onsite']) : null;

    $counts_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT
            SUM(CASE WHEN ticket_delivery_method = 'Remote' THEN 1 ELSE 0 END) AS remote_count,
            SUM(CASE WHEN ticket_delivery_method = 'Onsite' THEN 1 ELSE 0 END) AS onsite_count
         FROM tickets
         WHERE ticket_client_id = " . intval($client_id) . "
           AND ticket_created_at BETWEEN '$from_dt' AND '$to_dt'"));
    $used_remote = intval($counts_row['remote_count'] ?? 0);
    $used_onsite = intval($counts_row['onsite_count'] ?? 0);

    $summarize = function (?int $included, int $used): array {
        return [
            'included'  => $included,
            'used'      => $used,
            'remaining' => $included !== null ? ($included - $used) : null,
            'pct'       => ($included !== null && $included > 0) ? round(min(100, $used / $included * 100), 1) : null,
        ];
    };

    return [
        'month'  => $month,
        'year'   => $year,
        'remote' => $summarize($included_remote, $used_remote),
        'onsite' => $summarize($included_onsite, $used_onsite),
    ];
}

/**
 * Monthly Recurring Revenue report (set-based). Current MRR + ARR, breakdown by
 * frequency, trailing-12-month trend (approx active-at-month-end), 30-day movement
 * (new/churned), a 6-month forward billing forecast from next_date, and top clients.
 * Normalizes every recurring frequency to a monthly figure.
 */
function getMrrReport(mysqli $mysqli, ?int $client_id = null)
{
    // App only exposes 'month' and 'year'; others handled defensively so a future
    // frequency doesn't silently drop out of MRR.
    $freq_expr = "CASE recurring_invoice_frequency
        WHEN 'month'   THEN recurring_invoice_amount
        WHEN 'year'    THEN recurring_invoice_amount / 12
        WHEN 'quarter' THEN recurring_invoice_amount / 3
        WHEN 'week'    THEN recurring_invoice_amount * 52 / 12
        WHEN 'day'     THEN recurring_invoice_amount * 365 / 12
        ELSE recurring_invoice_amount END";

    // $client_id restricts every query below to one client - used by the API when the
    // caller is authenticated via a client-scoped legacy key, so a key restricted to one
    // client can never see another client's (or the whole company's) recurring revenue.
    // The classic web report always calls this with $client_id = null (company-wide).
    $client_clause = $client_id !== null ? " AND recurring_invoice_client_id = " . intval($client_id) : '';
    $client_clause_ri = $client_id !== null ? " AND ri.recurring_invoice_client_id = " . intval($client_id) : '';

    $active_where = "recurring_invoice_status = 1 AND recurring_invoice_archived_at IS NULL$client_clause";

    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COALESCE(SUM($freq_expr), 0) AS mrr, COUNT(*) AS c
         FROM recurring_invoices WHERE $active_where"));
    $mrr    = round(floatval($row['mrr']), 2);
    $active = intval($row['c']);

    // Breakdown by frequency.
    $by_freq = [];
    $res = mysqli_query($mysqli,
        "SELECT recurring_invoice_frequency AS f, COUNT(*) AS c, COALESCE(SUM($freq_expr), 0) AS mrr
         FROM recurring_invoices WHERE $active_where
         GROUP BY recurring_invoice_frequency ORDER BY mrr DESC");
    while ($r = mysqli_fetch_assoc($res)) {
        $by_freq[] = ['frequency' => $r['f'], 'count' => intval($r['c']), 'mrr' => round(floatval($r['mrr']), 2)];
    }

    // Movement over the trailing 30 days (approximation — recurring_invoices has no
    // amount-change history, so expansion/contraction can't be measured precisely).
    $new_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COALESCE(SUM($freq_expr), 0) AS mrr, COUNT(*) AS c
         FROM recurring_invoices
         WHERE $active_where AND recurring_invoice_created_at >= (NOW() - INTERVAL 30 DAY)"));
    $churn_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COALESCE(SUM($freq_expr), 0) AS mrr, COUNT(*) AS c
         FROM recurring_invoices
         WHERE recurring_invoice_archived_at IS NOT NULL
           AND recurring_invoice_archived_at >= (NOW() - INTERVAL 30 DAY)$client_clause"));
    $new_mrr   = round(floatval($new_row['mrr']), 2);
    $churn_mrr = round(floatval($churn_row['mrr']), 2);

    // Trailing-12-month MRR trend. Active-at-month-end is approximated as created on/before
    // the month-end and not archived by then (past status is not retained).
    $trend_labels = $trend_mrr = [];
    for ($i = 11; $i >= 0; $i--) {
        $end = date('Y-m-t', strtotime("first day of -$i month"));
        $trend_labels[] = date('M Y', strtotime($end));
        $r = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT COALESCE(SUM($freq_expr), 0) AS mrr FROM recurring_invoices
             WHERE recurring_invoice_created_at <= '$end 23:59:59'
               AND (recurring_invoice_archived_at IS NULL OR recurring_invoice_archived_at > '$end 23:59:59')$client_clause"));
        $trend_mrr[] = round(floatval($r['mrr']), 2);
    }

    // 6-month forward billing forecast: iterate each active schedule's next_date forward.
    $forecast_labels = [];
    $months = [];
    for ($i = 0; $i < 6; $i++) {
        $k = date('Y-m', strtotime("first day of +$i month"));
        $months[$k] = 0.0;
        $forecast_labels[] = date('M Y', strtotime("first day of +$i month"));
    }
    $horizon_end = date('Y-m-t', strtotime('first day of +5 month'));
    $res = mysqli_query($mysqli,
        "SELECT recurring_invoice_frequency AS f, recurring_invoice_amount AS amt, recurring_invoice_next_date AS nd
         FROM recurring_invoices WHERE $active_where");
    while ($r = mysqli_fetch_assoc($res)) {
        $amt = floatval($r['amt']);
        $f   = $r['f'];
        $cur = $r['nd'];
        if (!$cur) {
            continue;
        }
        $guard = 0;
        while ($cur <= $horizon_end && $guard < 400) {
            $k = substr($cur, 0, 7);
            if (isset($months[$k])) {
                $months[$k] += $amt;
            }
            if ($f === 'year') {
                $cur = date('Y-m-d', strtotime("$cur +1 year"));
            } elseif ($f === 'quarter') {
                $cur = date('Y-m-d', strtotime("$cur +3 month"));
            } elseif ($f === 'week') {
                $cur = date('Y-m-d', strtotime("$cur +1 week"));
            } elseif ($f === 'day') {
                $cur = date('Y-m-d', strtotime("$cur +1 day"));
            } else {
                $cur = date('Y-m-d', strtotime("$cur +1 month"));
            }
            $guard++;
        }
    }
    $forecast_amount = [];
    foreach ($months as $v) {
        $forecast_amount[] = round($v, 2);
    }

    // Top clients by MRR.
    $top = [];
    $res = mysqli_query($mysqli,
        "SELECT c.client_id, c.client_name, COALESCE(SUM($freq_expr), 0) AS mrr
         FROM recurring_invoices ri JOIN clients c ON c.client_id = ri.recurring_invoice_client_id
         WHERE ri.recurring_invoice_status = 1 AND ri.recurring_invoice_archived_at IS NULL$client_clause_ri
         GROUP BY c.client_id, c.client_name HAVING mrr > 0 ORDER BY mrr DESC LIMIT 15");
    while ($r = mysqli_fetch_assoc($res)) {
        $top[] = ['client_id' => intval($r['client_id']), 'client_name' => $r['client_name'], 'mrr' => round(floatval($r['mrr']), 2)];
    }

    return [
        'mrr'          => $mrr,
        'arr'          => round($mrr * 12, 2),
        'active_count' => $active,
        'by_frequency' => $by_freq,
        'movement'     => [
            'new_mrr'        => $new_mrr,
            'new_count'      => intval($new_row['c']),
            'churned_mrr'    => $churn_mrr,
            'churned_count'  => intval($churn_row['c']),
            'net_new_mrr'    => round($new_mrr - $churn_mrr, 2),
        ],
        'trend'        => ['labels' => $trend_labels, 'mrr' => $trend_mrr],
        'forecast'     => ['labels' => $forecast_labels, 'amount' => $forecast_amount],
        'top_clients'  => $top,
    ];
}

/**
 * Accounts-receivable aging (set-based). Outstanding balance per billable invoice,
 * aged into 0-30 / 31-60 / 61-90 / 90+ day buckets by days past invoice_due, rolled
 * up to bucket totals and per-client. Not-yet-due balances land in the 0-30 bucket.
 */
function getArAgingReport(mysqli $mysqli)
{
    $res = mysqli_query($mysqli,
        "SELECT i.invoice_client_id AS client_id, cl.client_name,
                DATEDIFF(CURDATE(), i.invoice_due) AS days_overdue,
                (i.invoice_amount - COALESCE(p.paid, 0)) AS balance
         FROM invoices i
         JOIN clients cl ON cl.client_id = i.invoice_client_id
         LEFT JOIN (
             SELECT payment_invoice_id, SUM(payment_amount) AS paid
             FROM payments GROUP BY payment_invoice_id
         ) p ON p.payment_invoice_id = i.invoice_id
         WHERE i.invoice_status NOT IN ('Draft', 'Cancelled', 'Non-Billable')
         HAVING balance > 0.005");

    $buckets = ['b_0_30' => 0.0, 'b_31_60' => 0.0, 'b_61_90' => 0.0, 'b_90_plus' => 0.0, 'total' => 0.0];
    $clients = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $bal = floatval($r['balance']);
        if ($bal <= 0) {
            continue;
        }
        $d   = intval($r['days_overdue']);
        $cid = intval($r['client_id']);
        if (!isset($clients[$cid])) {
            $clients[$cid] = [
                'client_id' => $cid, 'client_name' => $r['client_name'],
                'b_0_30' => 0.0, 'b_31_60' => 0.0, 'b_61_90' => 0.0, 'b_90_plus' => 0.0, 'balance' => 0.0,
            ];
        }
        if ($d <= 30) {
            $k = 'b_0_30';
        } elseif ($d <= 60) {
            $k = 'b_31_60';
        } elseif ($d <= 90) {
            $k = 'b_61_90';
        } else {
            $k = 'b_90_plus';
        }
        $clients[$cid][$k]        += $bal;
        $clients[$cid]['balance'] += $bal;
        $buckets[$k]              += $bal;
        $buckets['total']        += $bal;
    }
    foreach ($buckets as $k => $v) {
        $buckets[$k] = round($v, 2);
    }
    $clients = array_values($clients);
    foreach ($clients as &$c) {
        foreach (['b_0_30', 'b_31_60', 'b_61_90', 'b_90_plus', 'balance'] as $k) {
            $c[$k] = round($c[$k], 2);
        }
    }
    unset($c);
    usort($clients, function ($a, $b) {
        return $b['balance'] <=> $a['balance'];
    });

    return ['as_of' => date('Y-m-d'), 'buckets' => $buckets, 'clients' => $clients];
}

/**
 * Per-client profitability for a year (set-based). Revenue = payments received in the
 * year (attributed to the invoice's client). Labor value = SUM(hours x labor_type_rate)
 * for time logged in the year, by ticket client. Margin = revenue - labor value.
 * NOTE: labor_type_rate is the billing rate (per the task spec's "time_worked x rate"),
 * so "labor value" here is billable value, not internal wage cost.
 */
function getClientProfitability(mysqli $mysqli, $year)
{
    $year = intval($year);

    $rev = [];
    $res = mysqli_query($mysqli,
        "SELECT i.invoice_client_id AS cid, SUM(p.payment_amount) AS rev
         FROM payments p JOIN invoices i ON i.invoice_id = p.payment_invoice_id
         WHERE YEAR(p.payment_date) = $year
         GROUP BY i.invoice_client_id");
    while ($r = mysqli_fetch_assoc($res)) {
        $rev[intval($r['cid'])] = floatval($r['rev']);
    }

    $lab = [];
    $res = mysqli_query($mysqli,
        "SELECT t.ticket_client_id AS cid,
            SUM(TIME_TO_SEC(tr.ticket_reply_time_worked) / 3600 * COALESCE(lt.labor_type_rate, 0)) AS val,
            SUM(TIME_TO_SEC(tr.ticket_reply_time_worked)) AS secs
         FROM ticket_replies tr
         JOIN tickets t ON t.ticket_id = tr.ticket_reply_ticket_id
         LEFT JOIN labor_types lt ON lt.labor_type_id = tr.ticket_reply_labor_type_id
         WHERE tr.ticket_reply_time_worked IS NOT NULL AND YEAR(tr.ticket_reply_created_at) = $year
         GROUP BY t.ticket_client_id");
    while ($r = mysqli_fetch_assoc($res)) {
        $lab[intval($r['cid'])] = ['val' => floatval($r['val']), 'secs' => intval($r['secs'])];
    }

    $cids  = array_values(array_unique(array_merge(array_keys($rev), array_keys($lab))));
    $names = [];
    if ($cids) {
        $in  = implode(',', array_map('intval', $cids));
        $res = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_id IN ($in)");
        while ($r = mysqli_fetch_assoc($res)) {
            $names[intval($r['client_id'])] = $r['client_name'];
        }
    }

    $out = [];
    foreach ($cids as $cid) {
        $cid = intval($cid);
        $r   = round($rev[$cid] ?? 0, 2);
        $lv  = round($lab[$cid]['val'] ?? 0, 2);
        $out[] = [
            'client_id'     => $cid,
            'client_name'   => $names[$cid] ?? ('Client #' . $cid),
            'revenue'       => $r,
            'labor_value'   => $lv,
            'labor_seconds' => intval($lab[$cid]['secs'] ?? 0),
            'margin'        => round($r - $lv, 2),
        ];
    }
    usort($out, function ($a, $b) {
        return $b['revenue'] <=> $a['revenue'];
    });

    return ['year' => $year, 'clients' => $out];
}

/**
 * Quarterly Profit & Loss summary for a single year (set-based). Mirrors the revenue
 * and expense definitions used by agent/reports/profit_loss.php's classic table so the
 * numbers reconcile: revenue = invoice-linked payments + categorised revenues; expenses
 * = categorised, vendor-attributed expenses. Used to drive the prior-year comparison
 * column, net margin and the year-over-year chart.
 */
function getProfitLossSummary(mysqli $mysqli, $year)
{
    $year  = intval($year);
    $rev_q = [0.0, 0.0, 0.0, 0.0];
    $exp_q = [0.0, 0.0, 0.0, 0.0];

    $res = mysqli_query($mysqli,
        "SELECT QUARTER(payment_date) AS q, SUM(payment_amount) AS amt
         FROM payments JOIN invoices ON payment_invoice_id = invoice_id
         WHERE YEAR(payment_date) = $year GROUP BY q");
    while ($r = mysqli_fetch_assoc($res)) {
        $q = intval($r['q']);
        if ($q >= 1 && $q <= 4) {
            $rev_q[$q - 1] += floatval($r['amt']);
        }
    }
    $res = mysqli_query($mysqli,
        "SELECT QUARTER(revenue_date) AS q, SUM(revenue_amount) AS amt
         FROM revenues WHERE revenue_category_id > 0 AND YEAR(revenue_date) = $year GROUP BY q");
    while ($r = mysqli_fetch_assoc($res)) {
        $q = intval($r['q']);
        if ($q >= 1 && $q <= 4) {
            $rev_q[$q - 1] += floatval($r['amt']);
        }
    }
    $res = mysqli_query($mysqli,
        "SELECT QUARTER(expense_date) AS q, SUM(expense_amount) AS amt
         FROM expenses WHERE expense_category_id > 0 AND expense_vendor_id > 0 AND YEAR(expense_date) = $year GROUP BY q");
    while ($r = mysqli_fetch_assoc($res)) {
        $q = intval($r['q']);
        if ($q >= 1 && $q <= 4) {
            $exp_q[$q - 1] += floatval($r['amt']);
        }
    }

    $net_q = [];
    for ($i = 0; $i < 4; $i++) {
        $net_q[$i] = round($rev_q[$i] - $exp_q[$i], 2);
    }
    $rev_total = array_sum($rev_q);
    $exp_total = array_sum($exp_q);
    $net_total = $rev_total - $exp_total;

    return [
        'year'          => $year,
        'revenue_q'     => array_map(function ($v) { return round($v, 2); }, $rev_q),
        'expense_q'     => array_map(function ($v) { return round($v, 2); }, $exp_q),
        'net_q'         => $net_q,
        'revenue_total' => round($rev_total, 2),
        'expense_total' => round($exp_total, 2),
        'net_total'     => round($net_total, 2),
        'margin_pct'    => $rev_total > 0 ? round($net_total / $rev_total * 100, 1) : null,
    ];
}

/**
 * RMM health report (set-based). Alert volume trend, MTTA/MTTR, severity/status mix,
 * noisiest assets & clients, and alert->ticket conversion. Guards for an empty
 * rmm_alerts table (integration not configured). $date_from/$date_to are 'Y-m-d'.
 */
function getRmmHealthReport(mysqli $mysqli, $date_from, $date_to, ?int $client_id = null)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_from)) {
        $date_from = date('Y-01-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_to)) {
        $date_to = date('Y-m-d');
    }
    $from_dt   = "$date_from 00:00:00";
    $to_dt     = "$date_to 23:59:59";
    // $client_id restricts every query below to one client - used by the API when the
    // caller is authenticated via a client-scoped legacy key. The classic web report
    // always calls this with $client_id = null (company-wide).
    $client_clause    = $client_id !== null ? " AND client_id = " . intval($client_id) : '';
    $client_clause_ra = $client_id !== null ? " AND ra.client_id = " . intval($client_id) : '';
    $range     = "created_at BETWEEN '$from_dt' AND '$to_dt'$client_clause";
    $range_ra  = "ra.created_at BETWEEN '$from_dt' AND '$to_dt'$client_clause_ra";

    $have_data = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(*) AS c FROM rmm_alerts WHERE 1=1$client_clause"))['c']) > 0;

    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(*) AS total,
            SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved,
            SUM(CASE WHEN acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) AS acknowledged,
            SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS open_alerts,
            SUM(CASE WHEN ticket_id IS NOT NULL AND ticket_id > 0 THEN 1 ELSE 0 END) AS with_ticket,
            AVG(CASE WHEN acknowledged_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, acknowledged_at) END) AS mtta,
            AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, resolved_at) END) AS mttr
         FROM rmm_alerts WHERE $range"));
    $total  = intval($row['total']);
    $totals = [
        'total'          => $total,
        'resolved'       => intval($row['resolved']),
        'acknowledged'   => intval($row['acknowledged']),
        'open'           => intval($row['open_alerts']),
        'with_ticket'    => intval($row['with_ticket']),
        'conversion_pct' => $total > 0 ? round(intval($row['with_ticket']) / $total * 100, 1) : null,
        'mtta_seconds'   => $row['mtta'] !== null ? intval($row['mtta']) : null,
        'mttr_seconds'   => $row['mttr'] !== null ? intval($row['mttr']) : null,
    ];

    $by_severity = [];
    $res = mysqli_query($mysqli,
        "SELECT COALESCE(NULLIF(severity, ''), 'unknown') AS sev, COUNT(*) AS c
         FROM rmm_alerts WHERE $range GROUP BY sev ORDER BY c DESC");
    while ($r = mysqli_fetch_assoc($res)) {
        $by_severity[] = ['severity' => $r['sev'], 'count' => intval($r['c'])];
    }

    $by_status = [];
    $res = mysqli_query($mysqli,
        "SELECT COALESCE(NULLIF(status, ''), 'unknown') AS st, COUNT(*) AS c
         FROM rmm_alerts WHERE $range GROUP BY st ORDER BY c DESC");
    while ($r = mysqli_fetch_assoc($res)) {
        $by_status[] = ['status' => $r['st'], 'count' => intval($r['c'])];
    }

    // Monthly volume trend on a contiguous month axis.
    $counts = [];
    $res = mysqli_query($mysqli,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM rmm_alerts WHERE $range GROUP BY ym");
    while ($r = mysqli_fetch_assoc($res)) {
        $counts[$r['ym']] = intval($r['c']);
    }
    $trend_labels = $trend_series = [];
    $cursor = new DateTime(substr($date_from, 0, 7) . '-01');
    $last   = new DateTime(substr($date_to, 0, 7) . '-01');
    $guard  = 0;
    while ($cursor <= $last && $guard < 60) {
        $k = $cursor->format('Y-m');
        $trend_labels[] = $cursor->format('M Y');
        $trend_series[] = $counts[$k] ?? 0;
        $cursor->modify('+1 month');
        $guard++;
    }

    $noisiest_assets = [];
    $res = mysqli_query($mysqli,
        "SELECT ra.asset_id, a.asset_name, COUNT(*) AS c,
            AVG(CASE WHEN ra.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, ra.created_at, ra.resolved_at) END) AS mttr
         FROM rmm_alerts ra LEFT JOIN assets a ON a.asset_id = ra.asset_id
         WHERE $range_ra AND ra.asset_id IS NOT NULL AND ra.asset_id > 0
         GROUP BY ra.asset_id, a.asset_name ORDER BY c DESC LIMIT 10");
    while ($r = mysqli_fetch_assoc($res)) {
        $noisiest_assets[] = [
            'asset_id'     => intval($r['asset_id']),
            'asset_name'   => $r['asset_name'] ?? ('Asset #' . intval($r['asset_id'])),
            'alerts'       => intval($r['c']),
            'mttr_seconds' => $r['mttr'] !== null ? intval($r['mttr']) : null,
        ];
    }

    $noisiest_clients = [];
    $res = mysqli_query($mysqli,
        "SELECT ra.client_id, cl.client_name, COUNT(*) AS c,
            SUM(CASE WHEN ra.ticket_id IS NOT NULL AND ra.ticket_id > 0 THEN 1 ELSE 0 END) AS with_ticket
         FROM rmm_alerts ra LEFT JOIN clients cl ON cl.client_id = ra.client_id
         WHERE $range_ra AND ra.client_id IS NOT NULL AND ra.client_id > 0
         GROUP BY ra.client_id, cl.client_name ORDER BY c DESC LIMIT 10");
    while ($r = mysqli_fetch_assoc($res)) {
        $noisiest_clients[] = [
            'client_id'   => intval($r['client_id']),
            'client_name' => $r['client_name'] ?? ('Client #' . intval($r['client_id'])),
            'alerts'      => intval($r['c']),
            'with_ticket' => intval($r['with_ticket']),
        ];
    }

    return [
        'date_from'        => $date_from,
        'date_to'          => $date_to,
        'have_data'        => $have_data,
        'totals'           => $totals,
        'by_severity'      => $by_severity,
        'by_status'        => $by_status,
        'trend'            => ['labels' => $trend_labels, 'counts' => $trend_series],
        'noisiest_assets'  => $noisiest_assets,
        'noisiest_clients' => $noisiest_clients,
    ];
}

function getAssetIcon($asset_type)
{
    if ($asset_type == 'Laptop') {
        $device_icon = "laptop";
    } elseif ($asset_type == 'Desktop') {
        $device_icon = "desktop";
    } elseif ($asset_type == 'Server') {
        $device_icon = "server";
    } elseif ($asset_type == 'Printer') {
        $device_icon = "print";
    } elseif ($asset_type == 'Camera') {
        $device_icon = "video";
    } elseif ($asset_type == 'Switch') {
        $device_icon = "network-wired";
    } elseif ($asset_type == 'Firewall/Router') {
        $device_icon = "fire-alt";
    } elseif ($asset_type == 'Access Point') {
        $device_icon = "wifi";
    } elseif ($asset_type == 'Phone') {
        $device_icon = "phone";
    } elseif ($asset_type == 'Mobile Phone') {
        $device_icon = "mobile-alt";
    } elseif ($asset_type == 'Tablet') {
        $device_icon = "tablet-alt";
    } elseif ($asset_type == 'Display') {
        $device_icon = "tv";
    } elseif ($asset_type == 'Virtual Machine') {
        $device_icon = "cloud";
    } else {
        $device_icon = "tag";
    }

    return $device_icon;
}

function getInvoiceBadgeColor($invoice_status)
{
    if ($invoice_status == "Sent") {
        $invoice_badge_color = "warning";
    } elseif ($invoice_status == "Viewed") {
        $invoice_badge_color = "info";
    } elseif ($invoice_status == "Partial") {
        $invoice_badge_color = "primary";
    } elseif ($invoice_status == "Paid") {
        $invoice_badge_color = "success";
    } elseif ($invoice_status == "Cancelled") {
        $invoice_badge_color = "danger";
    } else {
        $invoice_badge_color = "secondary";
    }

    return $invoice_badge_color;
}

// Pass $_FILE['file'] to check an uploaded file before saving it
function checkFileUpload($file, $allowed_extensions)
{
    // Variables
    $name = $file['name'];
    $tmp = $file['tmp_name'];
    $size = $file['size'];

    $extarr = explode('.', $name);
    $extension = strtolower(end($extarr));

    // Check a file is actually attached/uploaded
    if ($tmp === '') {
        // No file uploaded
        return false;
    }

    // Check the extension is allowed
    if (!in_array($extension, $allowed_extensions)) {
        // Extension not allowed
        return false;
    }

    // Check the size is under 500 MB
    $maxSizeBytes = 500 * 1024 * 1024; // 500 MB
    if ($size > $maxSizeBytes) {
        return "File size exceeds the limit.";
    }

    // Read the file content
    $fileContent = file_get_contents($tmp);

    // Hash the file content using SHA-256
    $hashedContent = hash('md5', $fileContent);

    // Generate a secure filename using the hashed content
    $secureFilename = $hashedContent . randomString(2) . '.' . $extension;

    return $secureFilename;
}

function sanitizeInput($input) {
    global $mysqli;

    if (!empty($input)) {
        // Only convert encoding if it's NOT valid UTF-8
        if (!mb_check_encoding($input, 'UTF-8')) {
            // Try converting from Windows-1252 as a safe default fallback
            $input = mb_convert_encoding($input, 'UTF-8', 'Windows-1252');
        }
    }

    // Remove HTML and PHP tags
    $input = strip_tags((string) $input);

    // Trim white space
    $input = trim($input);

    // Escape for SQL
    $input = mysqli_real_escape_string($mysqli, $input);

    return $input;
}

function cleanInput($input) {
    // Only process non-empty input
    if (!empty($input)) {
        // Normalize encoding to UTF-8 if it’s not valid
        if (!mb_check_encoding($input, 'UTF-8')) {
            // Convert from Windows-1252 as a safe fallback
            $input = mb_convert_encoding($input, 'UTF-8', 'Windows-1252');
        }
    }

    // Remove HTML and PHP tags
    $input = strip_tags((string) $input);

    // Trim whitespace
    $input = trim($input);

    return $input;
}


function sanitizeForEmail($data)
{
    $sanitized = htmlspecialchars($data);
    $sanitized = strip_tags($sanitized);
    $sanitized = trim($sanitized);
    return $sanitized;
}

function timeAgo($datetime)
{
    if (is_null($datetime)) {
        return "-";
    }

    $time = strtotime($datetime);
    $difference = $time - time(); // Changed to handle future dates

    if ($difference == 0) {
        return 'right now';
    }

    $isFuture = $difference > 0; // Check if the date is in the future
    $difference = abs($difference); // Absolute value for calculation

    $timeRules = array(
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    );

    foreach ($timeRules as $secs => $str) {
        $div = $difference / $secs;
        if ($div >= 1) {
            $t = round($div);
            $timeStr = $t . ' ' . $str . ($t > 1 ? 's' : '');
            return $isFuture ? 'in ' . $timeStr : $timeStr . ' ago';
        }
    }
}

// Function to remove Emojis in messages, this seems to break the mail queue
function removeEmoji($text)
{
    return preg_replace('/\x{1F3F4}\x{E0067}\x{E0062}(?:\x{E0077}\x{E006C}\x{E0073}|\x{E0073}\x{E0063}\x{E0074}|\x{E0065}\x{E006E}\x{E0067})\x{E007F}|(?:\x{1F9D1}\x{1F3FF}\x{200D}\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D})?|\x{200D}(?:\x{1F48B}\x{200D})?)\x{1F9D1}|\x{1F469}\x{1F3FF}\x{200D}\x{1F91D}\x{200D}[\x{1F468}\x{1F469}]|\x{1FAF1}\x{1F3FF}\x{200D}\x{1FAF2})[\x{1F3FB}-\x{1F3FE}]|(?:\x{1F9D1}\x{1F3FE}\x{200D}\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D})?|\x{200D}(?:\x{1F48B}\x{200D})?)\x{1F9D1}|\x{1F469}\x{1F3FE}\x{200D}\x{1F91D}\x{200D}[\x{1F468}\x{1F469}]|\x{1FAF1}\x{1F3FE}\x{200D}\x{1FAF2})[\x{1F3FB}-\x{1F3FD}\x{1F3FF}]|(?:\x{1F9D1}\x{1F3FD}\x{200D}\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D})?|\x{200D}(?:\x{1F48B}\x{200D})?)\x{1F9D1}|\x{1F469}\x{1F3FD}\x{200D}\x{1F91D}\x{200D}[\x{1F468}\x{1F469}]|\x{1FAF1}\x{1F3FD}\x{200D}\x{1FAF2})[\x{1F3FB}\x{1F3FC}\x{1F3FE}\x{1F3FF}]|(?:\x{1F9D1}\x{1F3FC}\x{200D}\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D})?|\x{200D}(?:\x{1F48B}\x{200D})?)\x{1F9D1}|\x{1F469}\x{1F3FC}\x{200D}\x{1F91D}\x{200D}[\x{1F468}\x{1F469}]|\x{1FAF1}\x{1F3FC}\x{200D}\x{1FAF2})[\x{1F3FB}\x{1F3FD}-\x{1F3FF}]|(?:\x{1F9D1}\x{1F3FB}\x{200D}\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D})?|\x{200D}(?:\x{1F48B}\x{200D})?)\x{1F9D1}|\x{1F469}\x{1F3FB}\x{200D}\x{1F91D}\x{200D}[\x{1F468}\x{1F469}]|\x{1FAF1}\x{1F3FB}\x{200D}\x{1FAF2})[\x{1F3FC}-\x{1F3FF}]|\x{1F468}(?:\x{1F3FB}(?:\x{200D}(?:\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D}\x{1F468}[\x{1F3FB}-\x{1F3FF}]|\x{1F468}[\x{1F3FB}-\x{1F3FF}])|\x{200D}(?:\x{1F48B}\x{200D}\x{1F468}[\x{1F3FB}-\x{1F3FF}]|\x{1F468}[\x{1F3FB}-\x{1F3FF}]))|\x{1F91D}\x{200D}\x{1F468}[\x{1F3FC}-\x{1F3FF}]|[\x{2695}\x{2696}\x{2708}]\x{FE0F}|[\x{2695}\x{2696}\x{2708}]|[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}]))?|[\x{1F3FC}-\x{1F3FF}]\x{200D}\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D}\x{1F468}[\x{1F3FB}-\x{1F3FF}]|\x{1F468}[\x{1F3FB}-\x{1F3FF}])|\x{200D}(?:\x{1F48B}\x{200D}\x{1F468}[\x{1F3FB}-\x{1F3FF}]|\x{1F468}[\x{1F3FB}-\x{1F3FF}]))|\x{200D}(?:\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D})?|\x{200D}(?:\x{1F48B}\x{200D})?)\x{1F468}|[\x{1F468}\x{1F469}]\x{200D}(?:\x{1F466}\x{200D}\x{1F466}|\x{1F467}\x{200D}[\x{1F466}\x{1F467}])|\x{1F466}\x{200D}\x{1F466}|\x{1F467}\x{200D}[\x{1F466}\x{1F467}]|[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}])|\x{1F3FF}\x{200D}(?:\x{1F91D}\x{200D}\x{1F468}[\x{1F3FB}-\x{1F3FE}]|[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}])|\x{1F3FE}\x{200D}(?:\x{1F91D}\x{200D}\x{1F468}[\x{1F3FB}-\x{1F3FD}\x{1F3FF}]|[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}])|\x{1F3FD}\x{200D}(?:\x{1F91D}\x{200D}\x{1F468}[\x{1F3FB}\x{1F3FC}\x{1F3FE}\x{1F3FF}]|[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}])|\x{1F3FC}\x{200D}(?:\x{1F91D}\x{200D}\x{1F468}[\x{1F3FB}\x{1F3FD}-\x{1F3FF}]|[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}])|(?:\x{1F3FF}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FE}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FD}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FC}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{200D}[\x{2695}\x{2696}\x{2708}])\x{FE0F}|\x{200D}(?:[\x{1F468}\x{1F469}]\x{200D}[\x{1F466}\x{1F467}]|[\x{1F466}\x{1F467}])|\x{1F3FF}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FE}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FD}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FC}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FF}|\x{1F3FE}|\x{1F3FD}|\x{1F3FC}|\x{200D}[\x{2695}\x{2696}\x{2708}])?|(?:\x{1F469}(?:\x{1F3FB}\x{200D}\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D}[\x{1F468}\x{1F469}]|[\x{1F468}\x{1F469}])|\x{200D}(?:\x{1F48B}\x{200D}[\x{1F468}\x{1F469}]|[\x{1F468}\x{1F469}]))|[\x{1F3FC}-\x{1F3FF}]\x{200D}\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D}[\x{1F468}\x{1F469}]|[\x{1F468}\x{1F469}])|\x{200D}(?:\x{1F48B}\x{200D}[\x{1F468}\x{1F469}]|[\x{1F468}\x{1F469}])))|\x{1F9D1}[\x{1F3FB}-\x{1F3FF}]\x{200D}\x{1F91D}\x{200D}\x{1F9D1})[\x{1F3FB}-\x{1F3FF}]|\x{1F469}\x{200D}\x{1F469}\x{200D}(?:\x{1F466}\x{200D}\x{1F466}|\x{1F467}\x{200D}[\x{1F466}\x{1F467}])|\x{1F469}(?:\x{200D}(?:\x{2764}(?:\x{FE0F}\x{200D}(?:\x{1F48B}\x{200D}[\x{1F468}\x{1F469}]|[\x{1F468}\x{1F469}])|\x{200D}(?:\x{1F48B}\x{200D}[\x{1F468}\x{1F469}]|[\x{1F468}\x{1F469}]))|[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}])|\x{1F3FF}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}]|\x{1F3FE}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}]|\x{1F3FD}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}]|\x{1F3FC}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}]|\x{1F3FB}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}])|\x{1F9D1}(?:\x{200D}(?:\x{1F91D}\x{200D}\x{1F9D1}|[\x{1F33E}\x{1F373}\x{1F37C}\x{1F384}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}])|\x{1F3FF}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F384}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}]|\x{1F3FE}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F384}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}]|\x{1F3FD}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F384}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}]|\x{1F3FC}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F384}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}]|\x{1F3FB}\x{200D}[\x{1F33E}\x{1F373}\x{1F37C}\x{1F384}\x{1F393}\x{1F3A4}\x{1F3A8}\x{1F3EB}\x{1F3ED}\x{1F4BB}\x{1F4BC}\x{1F527}\x{1F52C}\x{1F680}\x{1F692}\x{1F9AF}-\x{1F9B3}\x{1F9BC}\x{1F9BD}])|\x{1F469}\x{200D}\x{1F466}\x{200D}\x{1F466}|\x{1F469}\x{200D}\x{1F469}\x{200D}[\x{1F466}\x{1F467}]|\x{1F469}\x{200D}\x{1F467}\x{200D}[\x{1F466}\x{1F467}]|(?:\x{1F441}\x{FE0F}?\x{200D}\x{1F5E8}|\x{1F9D1}(?:\x{1F3FF}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FE}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FD}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FC}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FB}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{200D}[\x{2695}\x{2696}\x{2708}])|\x{1F469}(?:\x{1F3FF}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FE}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FD}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FC}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FB}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{200D}[\x{2695}\x{2696}\x{2708}])|\x{1F636}\x{200D}\x{1F32B}|\x{1F3F3}\x{FE0F}?\x{200D}\x{26A7}|\x{1F43B}\x{200D}\x{2744}|(?:[\x{1F3C3}\x{1F3C4}\x{1F3CA}\x{1F46E}\x{1F470}\x{1F471}\x{1F473}\x{1F477}\x{1F481}\x{1F482}\x{1F486}\x{1F487}\x{1F645}-\x{1F647}\x{1F64B}\x{1F64D}\x{1F64E}\x{1F6A3}\x{1F6B4}-\x{1F6B6}\x{1F926}\x{1F935}\x{1F937}-\x{1F939}\x{1F93D}\x{1F93E}\x{1F9B8}\x{1F9B9}\x{1F9CD}-\x{1F9CF}\x{1F9D4}\x{1F9D6}-\x{1F9DD}][\x{1F3FB}-\x{1F3FF}]|[\x{1F46F}\x{1F9DE}\x{1F9DF}])\x{200D}[\x{2640}\x{2642}]|[\x{26F9}\x{1F3CB}\x{1F3CC}\x{1F575}](?:[\x{FE0F}\x{1F3FB}-\x{1F3FF}]\x{200D}[\x{2640}\x{2642}]|\x{200D}[\x{2640}\x{2642}])|\x{1F3F4}\x{200D}\x{2620}|[\x{1F3C3}\x{1F3C4}\x{1F3CA}\x{1F46E}\x{1F470}\x{1F471}\x{1F473}\x{1F477}\x{1F481}\x{1F482}\x{1F486}\x{1F487}\x{1F645}-\x{1F647}\x{1F64B}\x{1F64D}\x{1F64E}\x{1F6A3}\x{1F6B4}-\x{1F6B6}\x{1F926}\x{1F935}\x{1F937}-\x{1F939}\x{1F93C}-\x{1F93E}\x{1F9B8}\x{1F9B9}\x{1F9CD}-\x{1F9CF}\x{1F9D4}\x{1F9D6}-\x{1F9DD}]\x{200D}[\x{2640}\x{2642}]|[\xA9\xAE\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{2199}\x{21A9}\x{21AA}\x{231A}\x{231B}\x{2328}\x{23CF}\x{23ED}-\x{23EF}\x{23F1}\x{23F2}\x{23F8}-\x{23FA}\x{24C2}\x{25AA}\x{25AB}\x{25B6}\x{25C0}\x{25FB}\x{25FC}\x{25FE}\x{2600}-\x{2604}\x{260E}\x{2611}\x{2614}\x{2615}\x{2618}\x{2620}\x{2622}\x{2623}\x{2626}\x{262A}\x{262E}\x{262F}\x{2638}-\x{263A}\x{2640}\x{2642}\x{2648}-\x{2653}\x{265F}\x{2660}\x{2663}\x{2665}\x{2666}\x{2668}\x{267B}\x{267E}\x{267F}\x{2692}\x{2694}-\x{2697}\x{2699}\x{269B}\x{269C}\x{26A0}\x{26A7}\x{26AA}\x{26B0}\x{26B1}\x{26BD}\x{26BE}\x{26C4}\x{26C8}\x{26CF}\x{26D1}\x{26D3}\x{26E9}\x{26F0}-\x{26F5}\x{26F7}\x{26F8}\x{26FA}\x{2702}\x{2708}\x{2709}\x{270F}\x{2712}\x{2714}\x{2716}\x{271D}\x{2721}\x{2733}\x{2734}\x{2744}\x{2747}\x{2763}\x{27A1}\x{2934}\x{2935}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F004}\x{1F170}\x{1F171}\x{1F17E}\x{1F17F}\x{1F202}\x{1F237}\x{1F321}\x{1F324}-\x{1F32C}\x{1F336}\x{1F37D}\x{1F396}\x{1F397}\x{1F399}-\x{1F39B}\x{1F39E}\x{1F39F}\x{1F3CD}\x{1F3CE}\x{1F3D4}-\x{1F3DF}\x{1F3F5}\x{1F3F7}\x{1F43F}\x{1F4FD}\x{1F549}\x{1F54A}\x{1F56F}\x{1F570}\x{1F573}\x{1F576}-\x{1F579}\x{1F587}\x{1F58A}-\x{1F58D}\x{1F5A5}\x{1F5A8}\x{1F5B1}\x{1F5B2}\x{1F5BC}\x{1F5C2}-\x{1F5C4}\x{1F5D1}-\x{1F5D3}\x{1F5DC}-\x{1F5DE}\x{1F5E1}\x{1F5E3}\x{1F5E8}\x{1F5EF}\x{1F5F3}\x{1F5FA}\x{1F6CB}\x{1F6CD}-\x{1F6CF}\x{1F6E0}-\x{1F6E5}\x{1F6E9}\x{1F6F0}\x{1F6F3}])\x{FE0F}|\x{1F441}\x{FE0F}?\x{200D}\x{1F5E8}|\x{1F9D1}(?:\x{1F3FF}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FE}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FD}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FC}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FB}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{200D}[\x{2695}\x{2696}\x{2708}])|\x{1F469}(?:\x{1F3FF}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FE}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FD}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FC}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{1F3FB}\x{200D}[\x{2695}\x{2696}\x{2708}]|\x{200D}[\x{2695}\x{2696}\x{2708}])|\x{1F3F3}\x{FE0F}?\x{200D}\x{1F308}|\x{1F469}\x{200D}\x{1F467}|\x{1F469}\x{200D}\x{1F466}|\x{1F636}\x{200D}\x{1F32B}|\x{1F3F3}\x{FE0F}?\x{200D}\x{26A7}|\x{1F635}\x{200D}\x{1F4AB}|\x{1F62E}\x{200D}\x{1F4A8}|\x{1F415}\x{200D}\x{1F9BA}|\x{1FAF1}(?:\x{1F3FF}|\x{1F3FE}|\x{1F3FD}|\x{1F3FC}|\x{1F3FB})?|\x{1F9D1}(?:\x{1F3FF}|\x{1F3FE}|\x{1F3FD}|\x{1F3FC}|\x{1F3FB})?|\x{1F469}(?:\x{1F3FF}|\x{1F3FE}|\x{1F3FD}|\x{1F3FC}|\x{1F3FB})?|\x{1F43B}\x{200D}\x{2744}|(?:[\x{1F3C3}\x{1F3C4}\x{1F3CA}\x{1F46E}\x{1F470}\x{1F471}\x{1F473}\x{1F477}\x{1F481}\x{1F482}\x{1F486}\x{1F487}\x{1F645}-\x{1F647}\x{1F64B}\x{1F64D}\x{1F64E}\x{1F6A3}\x{1F6B4}-\x{1F6B6}\x{1F926}\x{1F935}\x{1F937}-\x{1F939}\x{1F93D}\x{1F93E}\x{1F9B8}\x{1F9B9}\x{1F9CD}-\x{1F9CF}\x{1F9D4}\x{1F9D6}-\x{1F9DD}][\x{1F3FB}-\x{1F3FF}]|[\x{1F46F}\x{1F9DE}\x{1F9DF}])\x{200D}[\x{2640}\x{2642}]|[\x{26F9}\x{1F3CB}\x{1F3CC}\x{1F575}](?:[\x{FE0F}\x{1F3FB}-\x{1F3FF}]\x{200D}[\x{2640}\x{2642}]|\x{200D}[\x{2640}\x{2642}])|\x{1F3F4}\x{200D}\x{2620}|\x{1F1FD}\x{1F1F0}|\x{1F1F6}\x{1F1E6}|\x{1F1F4}\x{1F1F2}|\x{1F408}\x{200D}\x{2B1B}|\x{2764}(?:\x{FE0F}\x{200D}[\x{1F525}\x{1FA79}]|\x{200D}[\x{1F525}\x{1FA79}])|\x{1F441}\x{FE0F}?|\x{1F3F3}\x{FE0F}?|[\x{1F3C3}\x{1F3C4}\x{1F3CA}\x{1F46E}\x{1F470}\x{1F471}\x{1F473}\x{1F477}\x{1F481}\x{1F482}\x{1F486}\x{1F487}\x{1F645}-\x{1F647}\x{1F64B}\x{1F64D}\x{1F64E}\x{1F6A3}\x{1F6B4}-\x{1F6B6}\x{1F926}\x{1F935}\x{1F937}-\x{1F939}\x{1F93C}-\x{1F93E}\x{1F9B8}\x{1F9B9}\x{1F9CD}-\x{1F9CF}\x{1F9D4}\x{1F9D6}-\x{1F9DD}]\x{200D}[\x{2640}\x{2642}]|\x{1F1FF}[\x{1F1E6}\x{1F1F2}\x{1F1FC}]|\x{1F1FE}[\x{1F1EA}\x{1F1F9}]|\x{1F1FC}[\x{1F1EB}\x{1F1F8}]|\x{1F1FB}[\x{1F1E6}\x{1F1E8}\x{1F1EA}\x{1F1EC}\x{1F1EE}\x{1F1F3}\x{1F1FA}]|\x{1F1FA}[\x{1F1E6}\x{1F1EC}\x{1F1F2}\x{1F1F3}\x{1F1F8}\x{1F1FE}\x{1F1FF}]|\x{1F1F9}[\x{1F1E6}\x{1F1E8}\x{1F1E9}\x{1F1EB}-\x{1F1ED}\x{1F1EF}-\x{1F1F4}\x{1F1F7}\x{1F1F9}\x{1F1FB}\x{1F1FC}\x{1F1FF}]|\x{1F1F8}[\x{1F1E6}-\x{1F1EA}\x{1F1EC}-\x{1F1F4}\x{1F1F7}-\x{1F1F9}\x{1F1FB}\x{1F1FD}-\x{1F1FF}]|\x{1F1F7}[\x{1F1EA}\x{1F1F4}\x{1F1F8}\x{1F1FA}\x{1F1FC}]|\x{1F1F5}[\x{1F1E6}\x{1F1EA}-\x{1F1ED}\x{1F1F0}-\x{1F1F3}\x{1F1F7}-\x{1F1F9}\x{1F1FC}\x{1F1FE}]|\x{1F1F3}[\x{1F1E6}\x{1F1E8}\x{1F1EA}-\x{1F1EC}\x{1F1EE}\x{1F1F1}\x{1F1F4}\x{1F1F5}\x{1F1F7}\x{1F1FA}\x{1F1FF}]|\x{1F1F2}[\x{1F1E6}\x{1F1E8}-\x{1F1ED}\x{1F1F0}-\x{1F1FF}]|\x{1F1F1}[\x{1F1E6}-\x{1F1E8}\x{1F1EE}\x{1F1F0}\x{1F1F7}-\x{1F1FB}\x{1F1FE}]|\x{1F1F0}[\x{1F1EA}\x{1F1EC}-\x{1F1EE}\x{1F1F2}\x{1F1F3}\x{1F1F5}\x{1F1F7}\x{1F1FC}\x{1F1FE}\x{1F1FF}]|\x{1F1EF}[\x{1F1EA}\x{1F1F2}\x{1F1F4}\x{1F1F5}]|\x{1F1EE}[\x{1F1E8}-\x{1F1EA}\x{1F1F1}-\x{1F1F4}\x{1F1F6}-\x{1F1F9}]|\x{1F1ED}[\x{1F1F0}\x{1F1F2}\x{1F1F3}\x{1F1F7}\x{1F1F9}\x{1F1FA}]|\x{1F1EC}[\x{1F1E6}\x{1F1E7}\x{1F1E9}-\x{1F1EE}\x{1F1F1}-\x{1F1F3}\x{1F1F5}-\x{1F1FA}\x{1F1FC}\x{1F1FE}]|\x{1F1EB}[\x{1F1EE}-\x{1F1F0}\x{1F1F2}\x{1F1F4}\x{1F1F7}]|\x{1F1EA}[\x{1F1E6}\x{1F1E8}\x{1F1EA}\x{1F1EC}\x{1F1ED}\x{1F1F7}-\x{1F1FA}]|\x{1F1E9}[\x{1F1EA}\x{1F1EC}\x{1F1EF}\x{1F1F0}\x{1F1F2}\x{1F1F4}\x{1F1FF}]|\x{1F1E8}[\x{1F1E6}\x{1F1E8}\x{1F1E9}\x{1F1EB}-\x{1F1EE}\x{1F1F0}-\x{1F1F5}\x{1F1F7}\x{1F1FA}-\x{1F1FF}]|\x{1F1E7}[\x{1F1E6}\x{1F1E7}\x{1F1E9}-\x{1F1EF}\x{1F1F1}-\x{1F1F4}\x{1F1F6}-\x{1F1F9}\x{1F1FB}\x{1F1FC}\x{1F1FE}\x{1F1FF}]|\x{1F1E6}[\x{1F1E8}-\x{1F1EC}\x{1F1EE}\x{1F1F1}\x{1F1F2}\x{1F1F4}\x{1F1F6}-\x{1F1FA}\x{1F1FC}\x{1F1FD}\x{1F1FF}]|[#\*0-9]\x{FE0F}?\x{20E3}|\x{1F93C}[\x{1F3FB}-\x{1F3FF}]|\x{2764}\x{FE0F}?|[\x{1F3C3}\x{1F3C4}\x{1F3CA}\x{1F46E}\x{1F470}\x{1F471}\x{1F473}\x{1F477}\x{1F481}\x{1F482}\x{1F486}\x{1F487}\x{1F645}-\x{1F647}\x{1F64B}\x{1F64D}\x{1F64E}\x{1F6A3}\x{1F6B4}-\x{1F6B6}\x{1F926}\x{1F935}\x{1F937}-\x{1F939}\x{1F93D}\x{1F93E}\x{1F9B8}\x{1F9B9}\x{1F9CD}-\x{1F9CF}\x{1F9D4}\x{1F9D6}-\x{1F9DD}][\x{1F3FB}-\x{1F3FF}]|[\x{26F9}\x{1F3CB}\x{1F3CC}\x{1F575}][\x{FE0F}\x{1F3FB}-\x{1F3FF}]?|\x{1F3F4}|[\x{270A}\x{270B}\x{1F385}\x{1F3C2}\x{1F3C7}\x{1F442}\x{1F443}\x{1F446}-\x{1F450}\x{1F466}\x{1F467}\x{1F46B}-\x{1F46D}\x{1F472}\x{1F474}-\x{1F476}\x{1F478}\x{1F47C}\x{1F483}\x{1F485}\x{1F48F}\x{1F491}\x{1F4AA}\x{1F57A}\x{1F595}\x{1F596}\x{1F64C}\x{1F64F}\x{1F6C0}\x{1F6CC}\x{1F90C}\x{1F90F}\x{1F918}-\x{1F91F}\x{1F930}-\x{1F934}\x{1F936}\x{1F977}\x{1F9B5}\x{1F9B6}\x{1F9BB}\x{1F9D2}\x{1F9D3}\x{1F9D5}\x{1FAC3}-\x{1FAC5}\x{1FAF0}\x{1FAF2}-\x{1FAF6}][\x{1F3FB}-\x{1F3FF}]|[\x{261D}\x{270C}\x{270D}\x{1F574}\x{1F590}][\x{FE0F}\x{1F3FB}-\x{1F3FF}]|[\x{261D}\x{270A}-\x{270D}\x{1F385}\x{1F3C2}\x{1F3C7}\x{1F408}\x{1F415}\x{1F43B}\x{1F442}\x{1F443}\x{1F446}-\x{1F450}\x{1F466}\x{1F467}\x{1F46B}-\x{1F46D}\x{1F472}\x{1F474}-\x{1F476}\x{1F478}\x{1F47C}\x{1F483}\x{1F485}\x{1F48F}\x{1F491}\x{1F4AA}\x{1F574}\x{1F57A}\x{1F590}\x{1F595}\x{1F596}\x{1F62E}\x{1F635}\x{1F636}\x{1F64C}\x{1F64F}\x{1F6C0}\x{1F6CC}\x{1F90C}\x{1F90F}\x{1F918}-\x{1F91F}\x{1F930}-\x{1F934}\x{1F936}\x{1F93C}\x{1F977}\x{1F9B5}\x{1F9B6}\x{1F9BB}\x{1F9D2}\x{1F9D3}\x{1F9D5}\x{1FAC3}-\x{1FAC5}\x{1FAF0}\x{1FAF2}-\x{1FAF6}]|[\x{1F3C3}\x{1F3C4}\x{1F3CA}\x{1F46E}\x{1F470}\x{1F471}\x{1F473}\x{1F477}\x{1F481}\x{1F482}\x{1F486}\x{1F487}\x{1F645}-\x{1F647}\x{1F64B}\x{1F64D}\x{1F64E}\x{1F6A3}\x{1F6B4}-\x{1F6B6}\x{1F926}\x{1F935}\x{1F937}-\x{1F939}\x{1F93D}\x{1F93E}\x{1F9B8}\x{1F9B9}\x{1F9CD}-\x{1F9CF}\x{1F9D4}\x{1F9D6}-\x{1F9DD}]|[\x{1F46F}\x{1F9DE}\x{1F9DF}]|[\xA9\xAE\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{2199}\x{21A9}\x{21AA}\x{231A}\x{231B}\x{2328}\x{23CF}\x{23ED}-\x{23EF}\x{23F1}\x{23F2}\x{23F8}-\x{23FA}\x{24C2}\x{25AA}\x{25AB}\x{25B6}\x{25C0}\x{25FB}\x{25FC}\x{25FE}\x{2600}-\x{2604}\x{260E}\x{2611}\x{2614}\x{2615}\x{2618}\x{2620}\x{2622}\x{2623}\x{2626}\x{262A}\x{262E}\x{262F}\x{2638}-\x{263A}\x{2640}\x{2642}\x{2648}-\x{2653}\x{265F}\x{2660}\x{2663}\x{2665}\x{2666}\x{2668}\x{267B}\x{267E}\x{267F}\x{2692}\x{2694}-\x{2697}\x{2699}\x{269B}\x{269C}\x{26A0}\x{26A7}\x{26AA}\x{26B0}\x{26B1}\x{26BD}\x{26BE}\x{26C4}\x{26C8}\x{26CF}\x{26D1}\x{26D3}\x{26E9}\x{26F0}-\x{26F5}\x{26F7}\x{26F8}\x{26FA}\x{2702}\x{2708}\x{2709}\x{270F}\x{2712}\x{2714}\x{2716}\x{271D}\x{2721}\x{2733}\x{2734}\x{2744}\x{2747}\x{2763}\x{27A1}\x{2934}\x{2935}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F004}\x{1F170}\x{1F171}\x{1F17E}\x{1F17F}\x{1F202}\x{1F237}\x{1F321}\x{1F324}-\x{1F32C}\x{1F336}\x{1F37D}\x{1F396}\x{1F397}\x{1F399}-\x{1F39B}\x{1F39E}\x{1F39F}\x{1F3CD}\x{1F3CE}\x{1F3D4}-\x{1F3DF}\x{1F3F5}\x{1F3F7}\x{1F43F}\x{1F4FD}\x{1F549}\x{1F54A}\x{1F56F}\x{1F570}\x{1F573}\x{1F576}-\x{1F579}\x{1F587}\x{1F58A}-\x{1F58D}\x{1F5A5}\x{1F5A8}\x{1F5B1}\x{1F5B2}\x{1F5BC}\x{1F5C2}-\x{1F5C4}\x{1F5D1}-\x{1F5D3}\x{1F5DC}-\x{1F5DE}\x{1F5E1}\x{1F5E3}\x{1F5E8}\x{1F5EF}\x{1F5F3}\x{1F5FA}\x{1F6CB}\x{1F6CD}-\x{1F6CF}\x{1F6E0}-\x{1F6E5}\x{1F6E9}\x{1F6F0}\x{1F6F3}]|[\x{23E9}-\x{23EC}\x{23F0}\x{23F3}\x{25FD}\x{2693}\x{26A1}\x{26AB}\x{26C5}\x{26CE}\x{26D4}\x{26EA}\x{26FD}\x{2705}\x{2728}\x{274C}\x{274E}\x{2753}-\x{2755}\x{2757}\x{2795}-\x{2797}\x{27B0}\x{27BF}\x{2B50}\x{1F0CF}\x{1F18E}\x{1F191}-\x{1F19A}\x{1F201}\x{1F21A}\x{1F22F}\x{1F232}-\x{1F236}\x{1F238}-\x{1F23A}\x{1F250}\x{1F251}\x{1F300}-\x{1F320}\x{1F32D}-\x{1F335}\x{1F337}-\x{1F37C}\x{1F37E}-\x{1F384}\x{1F386}-\x{1F393}\x{1F3A0}-\x{1F3C1}\x{1F3C5}\x{1F3C6}\x{1F3C8}\x{1F3C9}\x{1F3CF}-\x{1F3D3}\x{1F3E0}-\x{1F3F0}\x{1F3F8}-\x{1F407}\x{1F409}-\x{1F414}\x{1F416}-\x{1F43A}\x{1F43C}-\x{1F43E}\x{1F440}\x{1F444}\x{1F445}\x{1F451}-\x{1F465}\x{1F46A}\x{1F479}-\x{1F47B}\x{1F47D}-\x{1F480}\x{1F484}\x{1F488}-\x{1F48E}\x{1F490}\x{1F492}-\x{1F4A9}\x{1F4AB}-\x{1F4FC}\x{1F4FF}-\x{1F53D}\x{1F54B}-\x{1F54E}\x{1F550}-\x{1F567}\x{1F5A4}\x{1F5FB}-\x{1F62D}\x{1F62F}-\x{1F634}\x{1F637}-\x{1F644}\x{1F648}-\x{1F64A}\x{1F680}-\x{1F6A2}\x{1F6A4}-\x{1F6B3}\x{1F6B7}-\x{1F6BF}\x{1F6C1}-\x{1F6C5}\x{1F6D0}-\x{1F6D2}\x{1F6D5}-\x{1F6D7}\x{1F6DD}-\x{1F6DF}\x{1F6EB}\x{1F6EC}\x{1F6F4}-\x{1F6FC}\x{1F7E0}-\x{1F7EB}\x{1F7F0}\x{1F90D}\x{1F90E}\x{1F910}-\x{1F917}\x{1F920}-\x{1F925}\x{1F927}-\x{1F92F}\x{1F93A}\x{1F93F}-\x{1F945}\x{1F947}-\x{1F976}\x{1F978}-\x{1F9B4}\x{1F9B7}\x{1F9BA}\x{1F9BC}-\x{1F9CC}\x{1F9D0}\x{1F9E0}-\x{1F9FF}\x{1FA70}-\x{1FA74}\x{1FA78}-\x{1FA7C}\x{1FA80}-\x{1FA86}\x{1FA90}-\x{1FAAC}\x{1FAB0}-\x{1FABA}\x{1FAC0}-\x{1FAC2}\x{1FAD0}-\x{1FAD9}\x{1FAE0}-\x{1FAE7}]/u', '', $text);
}

function shortenClient($client)
{
    // Pre-process by removing any non-alphanumeric characters except for certain punctuations.
    $client = html_entity_decode($client); // Decode any HTML entities
    $client = str_replace("'", "", $client); // Removing all occurrences of '
    $cleaned = preg_replace('/[^a-zA-Z0-9&]+/', ' ', $client);

    // Break into words.
    $words = explode(' ', trim($cleaned));

    $shortened = '';

    // If there's only one word.
    if (count($words) == 1) {
        $word = $words[0];

        if (strlen($word) <= 3) {
            return strtoupper($word);
        }

        // Prefer starting and ending characters.
        $shortened = $word[0] . substr($word, -2);
    } else {
        // Less weightage to common words.
        $commonWords = ['the', 'of', 'and'];

        foreach ($words as $word) {
            if (!in_array(strtolower($word), $commonWords) || strlen($shortened) < 2) {
                $shortened .= $word[0];
            }
        }

        // If there are still not enough characters, take from the last word.
        while (strlen($shortened) < 3 && !empty($word)) {
            $shortened .= substr($word, 1, 1);
            $word = substr($word, 1);
        }
    }

    return strtoupper(substr($shortened, 0, 3));
}

function roundToNearest15($time)
{
    // Validate the input time format
    if (!preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $time, $matches)) {
        return false; // or throw an exception
    }

    // Extract hours, minutes, and seconds from the matched time string
    list(, $hours, $minutes, $seconds) = $matches;

    // Convert everything to seconds for easier calculation
    $totalSeconds = ($hours * 3600) + ($minutes * 60) + $seconds;

    // Calculate the remainder when divided by 900 seconds (15 minutes)
    $remainder = $totalSeconds % 900;

    if ($remainder > 450) {  // If remainder is more than 7.5 minutes (450 seconds), round up
        $totalSeconds += (900 - $remainder);
    } else {  // Else round down
        $totalSeconds -= $remainder;
    }

    // Convert total seconds to decimal hours
    $decimalHours = $totalSeconds / 3600;

    // Return the decimal hours
    return number_format($decimalHours, 2);
}

function getMonthlyTax($tax_name, $month, $year, $mysqli)
{
    // Prorate each item's tax across its invoice's payments by amount, rather
    // than SUM(item_tax) over the invoice_items x payments join - an invoice
    // with N payments in the period would otherwise count its tax N times.
    $sql = "SELECT SUM(item_tax * payments.payment_amount / invoices.invoice_amount) AS monthly_tax FROM invoice_items
            LEFT JOIN invoices ON invoice_items.item_invoice_id = invoices.invoice_id
            LEFT JOIN payments ON invoices.invoice_id = payments.payment_invoice_id
            WHERE YEAR(payments.payment_date) = $year AND MONTH(payments.payment_date) = $month
            AND invoices.invoice_amount > 0
            AND invoice_items.item_tax_id = (SELECT tax_id FROM taxes WHERE tax_name = '$tax_name')";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['monthly_tax'] ?? 0;
}

function getQuarterlyTax($tax_name, $quarter, $year, $mysqli)
{
    // Calculate start and end months for the quarter
    $start_month = ($quarter - 1) * 3 + 1;
    $end_month = $start_month + 2;

    // Prorate each item's tax across its invoice's payments by amount - see
    // getMonthlyTax() for why a flat SUM(item_tax) double-counts split payments.
    $sql = "SELECT SUM(item_tax * payments.payment_amount / invoices.invoice_amount) AS quarterly_tax FROM invoice_items
            LEFT JOIN invoices ON invoice_items.item_invoice_id = invoices.invoice_id
            LEFT JOIN payments ON invoices.invoice_id = payments.payment_invoice_id
            WHERE YEAR(payments.payment_date) = $year AND MONTH(payments.payment_date) BETWEEN $start_month AND $end_month
            AND invoices.invoice_amount > 0
            AND invoice_items.item_tax_id = (SELECT tax_id FROM taxes WHERE tax_name = '$tax_name')";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['quarterly_tax'] ?? 0;
}

function getTotalTax($tax_name, $year, $mysqli)
{
    // Prorate each item's tax across its invoice's payments by amount - see
    // getMonthlyTax() for why a flat SUM(item_tax) double-counts split payments.
    $sql = "SELECT SUM(item_tax * payments.payment_amount / invoices.invoice_amount) AS total_tax FROM invoice_items
            LEFT JOIN invoices ON invoice_items.item_invoice_id = invoices.invoice_id
            LEFT JOIN payments ON invoices.invoice_id = payments.payment_invoice_id
            WHERE YEAR(payments.payment_date) = $year
            AND invoices.invoice_amount > 0
            AND invoice_items.item_tax_id = (SELECT tax_id FROM taxes WHERE tax_name = '$tax_name')";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['total_tax'] ?? 0;
}

function generateReadablePassword($security_level)
{
    // Cap security level at 5
    $security_level = intval($security_level);
    $security_level = min($security_level, 5);

    // Arrays of words
    $articles = ['The', 'A'];
    $adjectives = ['Smart', 'Swift', 'Secure', 'Stable', 'Digital', 'Virtual', 'Active', 'Dynamic', 'Innovative', 'Efficient', 'Portable', 'Wireless', 'Rapid', 'Intuitive', 'Automated', 'Robust', 'Reliable', 'Sleek', 'Modern', 'Happy', 'Funny', 'Quick', 'Bright', 'Clever', 'Gentle', 'Brave', 'Calm', 'Eager', 'Fierce', 'Kind', 'Lucky', 'Proud', 'Silly', 'Witty', 'Bold', 'Curious', 'Elated', 'Gracious', 'Honest', 'Jolly', 'Merry', 'Noble', 'Optimistic', 'Playful', 'Quirky', 'Rustic', 'Steady', 'Tranquil', 'Upbeat'];
    $nouns = ['Computer', 'Laptop', 'Tablet', 'Server', 'Router', 'Software', 'Hardware', 'Pixel', 'Byte', 'App', 'Network', 'Cloud', 'Firewall', 'Email', 'Database', 'Folder', 'Document', 'Interface', 'Program', 'Gadget', 'Dinosaur', 'Tiger', 'Elephant', 'Kangaroo', 'Monkey', 'Unicorn', 'Dragon', 'Puppy', 'Kitten', 'Parrot', 'Lion', 'Bear', 'Fox', 'Wolf', 'Rabbit', 'Deer', 'Owl', 'Hedgehog', 'Turtle', 'Frog', 'Butterfly', 'Panda', 'Giraffe', 'Zebra', 'Peacock', 'Koala', 'Raccoon', 'Squirrel', 'Hippo', 'Rhino', 'Book', "Monitor"];
    $verbs = ['Connects', 'Runs', 'Processes', 'Secures', 'Encrypts', 'Saves', 'Updates', 'Boots', 'Scans', 'Compiles', 'Executes', 'Restores', 'Installs', 'Configures', 'Downloads', 'Streams', 'BacksUp', 'Syncs', 'Browses', 'Navigates', 'Runs', 'Jumps', 'Flies', 'Swims', 'Dances', 'Sings', 'Hops', 'Skips', 'Races', 'Climbs', 'Crawls', 'Glides', 'Twirls', 'Swings', 'Sprints', 'Gallops', 'Trots', 'Wanders', 'Strolls', 'Marches'];
    $adverbs = ['Quickly', 'Slowly', 'Gracefully', 'Wildly', 'Loudly', 'Silently', 'Cheerfully', 'Eagerly', 'Gently', 'Happily', 'Jovially', 'Kindly', 'Lazily', 'Merrily', 'Neatly', 'Politely', 'Quietly', 'Rapidly', 'Smoothly', 'Tightly', 'Swiftly', 'Securely', 'Efficiently', 'Rapidly', 'Smoothly', 'Reliably', 'Safely', 'Wirelessly', 'Instantly', 'Silently', 'Automatically', 'Seamlessly', 'Digitally', 'Virtually', 'Continuously', 'Regularly', 'Intelligently', 'Logically'];

    // Randomly select words from arrays
    $adj = $adjectives[array_rand($adjectives)];
    $noun = $nouns[array_rand($nouns)];
    $verb = $verbs[array_rand($verbs)];
    $adv = $adverbs[array_rand($adverbs)];

    // Combine to create a base password
    $password = $adj . $noun . $verb . $adv;

    // Select an article randomly
    $article = $articles[array_rand($articles)];

    // Determine if we should use 'An' instead of 'A'
    if ($article == 'A' && preg_match('/^[aeiouAEIOU]/', $adj)) {
        $article = 'An';
    }

    // Add the article to the password
    $password = $article . $password;

    // Mapping of letters to special characters and numbers
    $mappings = [
        'A' => '@', 'a' => '@',
        'E' => '3', 'e' => '3',
        'I' => '!', 'i' => '!',
        'O' => '0', 'o' => '0',
        'S' => '$', 's' => '$',
        'T' => '+', 't' => '+',
        'B' => '8', 'b' => '8'
    ];

    // Generate an array of indices based on the password length
    $indices = range(0, strlen($password) - 1);
    // Randomly shuffle the indices
    shuffle($indices);

    // Iterate through the shuffled indices and replace characters based on the security level
    for ($i = 0; $i < min($security_level, strlen($password)); $i++) {
        $index = $indices[$i]; // Get a random index
        $currentChar = $password[$index]; // Get the character at this index
        // Check if the current character has a mapping and replace it
        if (array_key_exists($currentChar, $mappings)) {
            $password[$index] = $mappings[$currentChar];
        }
    }

    // Add as many random numbers as the security level
    $password .= rand(pow(10, $security_level - 1), pow(10, $security_level) - 1);

    return $password;
}

function addToMailQueue($data) {

    global $mysqli;

    foreach ($data as $email) {
        $from = strval($email['from']);
        $from_name = strval($email['from_name']);
        $recipient = strval($email['recipient']);
        $recipient_name = strval($email['recipient_name']);
        $subject = strval($email['subject']);
        $body = strval($email['body']);
        $cal_str = isset($email['cal_str']) ? strval($email['cal_str']) : '';

        // Bound parameters (not raw string interpolation) since email bodies
        // routinely contain unescaped HTML attribute quotes (e.g. <a href='...'>)
        // and names/subjects routinely contain apostrophes (e.g. "O'Brien") -
        // either used to silently break this INSERT. A prepared statement sidesteps
        // the whole escaping question rather than risking double-escaping content
        // some callers already ran through sanitizeInput() before embedding it.
        if (isset($email['queued_at']) && !empty($email['queued_at'])) {
            $queued_at = sanitizeInput($email['queued_at']);
            $stmt = mysqli_prepare($mysqli, "INSERT INTO email_queue
                (email_recipient, email_recipient_name, email_from, email_from_name, email_subject, email_content, email_queued_at, email_cal_str)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssssssss', $recipient, $recipient_name, $from, $from_name, $subject, $body, $queued_at, $cal_str);
        } else {
            // No explicit queued_at - use the DB server's own clock (CURRENT_TIMESTAMP(),
            // a literal SQL function call, not user data - safe to inline) rather than
            // PHP's, since the two can run in different timezones and a PHP-side
            // timestamp risks silently delaying delivery until the DB clock catches up.
            $stmt = mysqli_prepare($mysqli, "INSERT INTO email_queue
                (email_recipient, email_recipient_name, email_from, email_from_name, email_subject, email_content, email_queued_at, email_cal_str)
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(), ?)");
            mysqli_stmt_bind_param($stmt, 'sssssss', $recipient, $recipient_name, $from, $from_name, $subject, $body, $cal_str);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    return true;
}

function createiCalStr($datetime, $title, $description, $location, $datetime_end = null)
{
    require_once "plugins/zapcal/zapcallib.php";

    $cal_event = new ZCiCal();
    $event = new ZCiCalNode("VEVENT", $cal_event->curnode);

    $end = $datetime_end ?: $datetime;

    $event->addNode(new ZCiCalDataNode("METHOD:REQUEST"));
    $event->addNode(new ZCiCalDataNode("SUMMARY:" . $title));
    $event->addNode(new ZCiCalDataNode("DTSTART:" . ZCiCal::fromSqlDateTime($datetime)));
    $event->addNode(new ZCiCalDataNode("DTEND:" . ZCiCal::fromSqlDateTime($end)));
    $event->addNode(new ZCiCalDataNode("DTSTAMP:" . ZCiCal::fromSqlDateTime()));
    $uid = date('Y-m-d-H-i-s') . "@" . $_SERVER['SERVER_NAME'];
    $event->addNode(new ZCiCalDataNode("UID:" . $uid));
    $event->addNode(new ZCiCalDataNode("LOCATION:" . $location));
    $event->addNode(new ZCiCalDataNode("DESCRIPTION:" . $description));

    return $cal_event->export();
}

function isMobile()
{
    // Check if the user agent is a mobile device
    return preg_match('/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|opera mini|palm|phone|pie|tablet|up.browser|up.link|webos|wos)/i', $_SERVER['HTTP_USER_AGENT']);
}

function createiCalStrCancel($originaliCalStr) {
    require_once "plugins/zapcal/zapcallib.php";

    // Import the original iCal string
    $cal_event = new ZCiCal($originaliCalStr);

    // Iterate through the iCalendar object to find VEVENT nodes
    foreach($cal_event->tree->child as $node) {
        if($node->getName() == "VEVENT") {
            // Check if STATUS node exists, update it, or add a new one
            $statusFound = false;
            foreach($node->data as $key => $value) {
                if($key == "STATUS") {
                    $value->setValue("CANCELLED");
                    $statusFound = true;
                    break; // Exit the loop once the STATUS is updated
                }
            }
            // If STATUS node is not found, add a new STATUS node
            if (!$statusFound) {
                $node->addNode(new ZCiCalDataNode("STATUS:CANCELLED"));
            }
        }
    }

    // Return the modified iCal string
    return $cal_event->export();
}

// ── Outlook Calendar Graph API ─────────────────────────────────────────────

function getOutlookAccessToken($user_id) {
    global $mysqli, $config_outlook_cal_tenant_id, $config_outlook_cal_client_id, $config_outlook_cal_client_secret;

    if (!$config_outlook_cal_tenant_id || !$config_outlook_cal_client_id || !$config_outlook_cal_client_secret) {
        return null;
    }

    $user_id = intval($user_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT user_outlook_refresh_token, user_outlook_access_token, user_outlook_token_expires FROM users WHERE user_id = $user_id"
    ));

    if (!$row || empty($row['user_outlook_refresh_token'])) {
        return null;
    }

    // Tokens are encrypted at rest under the site's canonical vault key (see
    // outlook_calendar_callback.php) since they're read on behalf of whichever
    // user owns the ticket appointment, not necessarily the current session's user.
    $canonical_key = getCanonicalVaultKey($mysqli);
    $stored_refresh_token = decryptCredentialEntryWithKey($row['user_outlook_refresh_token'], $canonical_key);
    $stored_access_token  = !empty($row['user_outlook_access_token'])
        ? decryptCredentialEntryWithKey($row['user_outlook_access_token'], $canonical_key)
        : null;

    // Return cached access token if still valid
    if (!empty($stored_access_token) && !empty($row['user_outlook_token_expires'])
        && strtotime($row['user_outlook_token_expires']) > time() + 60) {
        return $stored_access_token;
    }

    // Refresh the access token
    $ch = curl_init("https://login.microsoftonline.com/" . rawurlencode($config_outlook_cal_tenant_id) . "/oauth2/v2.0/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $config_outlook_cal_client_id,
            'client_secret' => $config_outlook_cal_client_secret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $stored_refresh_token,
            'scope'         => 'Calendars.ReadWrite offline_access',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($data['access_token'])) {
        // If the refresh token was revoked (password change, sign-out-all, etc.),
        // clear it from the DB so user_integrations.php shows "not connected"
        // and the user knows to reconnect rather than silently failing indefinitely.
        if (isset($data['error']) && in_array($data['error'], ['invalid_grant', 'interaction_required'])) {
            mysqli_query($mysqli, "UPDATE users SET
                user_outlook_access_token  = NULL,
                user_outlook_refresh_token = NULL,
                user_outlook_token_expires = NULL
                WHERE user_id = $user_id");
            error_log("ITFlow: Outlook token for user $user_id revoked ({$data['error']}). User must reconnect at /agent/user/user_integrations.php");

            // The refresh token is cleared above, so this only fires once per
            // revocation (the next call short-circuits before reaching Microsoft)
            // rather than notifying on every scheduling action.
            notifyUser($user_id, 'Integration', 'Your Outlook Calendar connection has expired or was revoked. Appointments will stop syncing until you reconnect it.', '/agent/user/user_integrations.php');
        }
        return null;
    }

    $access_token  = mysqli_real_escape_string($mysqli, encryptCredentialEntryWithKey($data['access_token'], $canonical_key));
    $expires_at    = date('Y-m-d H:i:s', time() + intval($data['expires_in'] ?? 3600));
    $refresh_extra = '';
    if (!empty($data['refresh_token'])) {
        $new_refresh   = mysqli_real_escape_string($mysqli, encryptCredentialEntryWithKey($data['refresh_token'], $canonical_key));
        $refresh_extra = ", user_outlook_refresh_token = '$new_refresh'";
    }

    mysqli_query($mysqli, "UPDATE users SET user_outlook_access_token = '$access_token', user_outlook_token_expires = '$expires_at'$refresh_extra WHERE user_id = $user_id");

    return $data['access_token'];
}

function syncTicketToOutlook($ticket_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $sql = mysqli_query($mysqli, "SELECT t.*, c.client_name, l.location_address
        FROM tickets t
        LEFT JOIN clients c ON t.ticket_client_id = c.client_id
        LEFT JOIN contacts ct ON t.ticket_contact_id = ct.contact_id
        LEFT JOIN locations l ON ct.contact_location_id = l.location_id
        WHERE t.ticket_id = $ticket_id");
    $t = mysqli_fetch_assoc($sql);

    if (!$t || !$t['ticket_schedule'] || !$t['ticket_assigned_to']) {
        return;
    }

    $access_token = getOutlookAccessToken($t['ticket_assigned_to']);
    if (!$access_token) {
        return;
    }

    $tz_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_timezone FROM settings WHERE company_id = 1 LIMIT 1"));
    $tz = ($tz_row && $tz_row['config_timezone']) ? $tz_row['config_timezone'] : 'America/Chicago';

    $subject   = ($t['ticket_number'] ? '#' . $t['ticket_number'] . ': ' : '') . ($t['client_name'] ?? '') . ' — ' . $t['ticket_subject'];
    $start_dt  = date('Y-m-d\TH:i:s', strtotime($t['ticket_schedule']));
    $end_dt    = $t['ticket_schedule_end']
        ? date('Y-m-d\TH:i:s', strtotime($t['ticket_schedule_end']))
        : date('Y-m-d\TH:i:s', strtotime($t['ticket_schedule']) + 3600);
    $location  = $t['ticket_onsite'] ? 'Onsite' : 'Remote';
    if (!empty($t['location_address'])) {
        $location .= ' — ' . $t['location_address'];
    }
    $body_text = strip_tags($t['ticket_details'] ?? '');
    if ($t['ticket_appointment_notes']) {
        $body_text .= "\n\nAppointment Notes: " . $t['ticket_appointment_notes'];
    }

    $payload = json_encode([
        'subject'  => $subject,
        'body'     => ['contentType' => 'text', 'content' => $body_text],
        'start'    => ['dateTime' => $start_dt, 'timeZone' => $tz],
        'end'      => ['dateTime' => $end_dt,   'timeZone' => $tz],
        'location' => ['displayName' => $location],
    ]);

    $existing_event_id = $t['ticket_outlook_event_id'] ?? null;
    if ($existing_event_id) {
        $url    = "https://graph.microsoft.com/v1.0/me/events/" . rawurlencode($existing_event_id);
        $method = 'PATCH';
    } else {
        $url    = "https://graph.microsoft.com/v1.0/me/events";
        $method = 'POST';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json",
        ],
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if ($method === 'POST' && !empty($response['id'])) {
        $event_id = mysqli_real_escape_string($mysqli, $response['id']);
        mysqli_query($mysqli, "UPDATE tickets SET ticket_outlook_event_id = '$event_id' WHERE ticket_id = $ticket_id");
    }
}

// Returns 'synced', 'skipped' (no tech/token), or 'failed' (API error).
function syncScheduleEntryToOutlook($schedule_id) {
    global $mysqli;

    $schedule_id = intval($schedule_id);
    $sql = mysqli_query($mysqli,
        "SELECT ts.*, t.ticket_number, t.ticket_subject, t.ticket_details, t.ticket_onsite,
                c.client_name, l.location_address
         FROM ticket_schedules ts
         LEFT JOIN tickets t ON t.ticket_id = ts.schedule_ticket_id
         LEFT JOIN clients c ON t.ticket_client_id = c.client_id
         LEFT JOIN contacts ct ON t.ticket_contact_id = ct.contact_id
         LEFT JOIN locations l ON ct.contact_location_id = l.location_id
         WHERE ts.schedule_id = $schedule_id LIMIT 1");
    $s = mysqli_fetch_assoc($sql);

    if (!$s || !$s['schedule_start'] || !$s['schedule_tech_id']) return 'skipped';

    $access_token = getOutlookAccessToken($s['schedule_tech_id']);
    if (!$access_token) return 'skipped';

    $tz_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_timezone FROM settings WHERE company_id = 1 LIMIT 1"));
    $tz = ($tz_row && $tz_row['config_timezone']) ? $tz_row['config_timezone'] : 'America/Chicago';

    $subject   = ($s['ticket_number'] ? '#' . $s['ticket_number'] . ': ' : '') . ($s['client_name'] ?? '') . ' — ' . $s['ticket_subject'];
    $start_dt  = date('Y-m-d\TH:i:s', strtotime($s['schedule_start']));
    $end_dt    = $s['schedule_end']
        ? date('Y-m-d\TH:i:s', strtotime($s['schedule_end']))
        : date('Y-m-d\TH:i:s', strtotime($s['schedule_start']) + 3600);
    $location  = $s['schedule_onsite'] ? 'Onsite' : 'Remote';
    if (!empty($s['location_address'])) $location .= ' — ' . $s['location_address'];
    $body_text = strip_tags($s['ticket_details'] ?? '');
    if ($s['schedule_notes']) $body_text .= "\n\nAppointment Notes: " . $s['schedule_notes'];

    $payload = json_encode([
        'subject'  => $subject,
        'body'     => ['contentType' => 'text', 'content' => $body_text],
        'start'    => ['dateTime' => $start_dt, 'timeZone' => $tz],
        'end'      => ['dateTime' => $end_dt,   'timeZone' => $tz],
        'location' => ['displayName' => $location],
    ]);

    $existing_event_id = $s['schedule_outlook_event_id'] ?? null;
    if ($existing_event_id) {
        $url    = "https://graph.microsoft.com/v1.0/me/events/" . rawurlencode($existing_event_id);
        $method = 'PATCH';
    } else {
        $url    = "https://graph.microsoft.com/v1.0/me/events";
        $method = 'POST';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json",
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if ($method === 'PATCH' && empty($response['error'])) {
        return 'synced';
    }

    if ($method === 'POST' && !empty($response['id'])) {
        $event_id = mysqli_real_escape_string($mysqli, $response['id']);
        mysqli_query($mysqli, "UPDATE ticket_schedules SET schedule_outlook_event_id = '$event_id' WHERE schedule_id = $schedule_id");
        return 'synced';
    }

    error_log("ITFlow: Outlook event sync failed for schedule $schedule_id: " . json_encode($response['error'] ?? 'no response'));
    return 'failed';
}

function deleteOutlookCalendarEvent($ticket_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ticket_outlook_event_id, ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id"
    ));

    if (!$row || empty($row['ticket_outlook_event_id'])) {
        return;
    }

    $access_token = getOutlookAccessToken($row['ticket_assigned_to']);
    if ($access_token) {
        $ch = curl_init("https://graph.microsoft.com/v1.0/me/events/" . rawurlencode($row['ticket_outlook_event_id']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $access_token"],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_outlook_event_id = NULL WHERE ticket_id = $ticket_id");
}

// Modern multi-appointment counterpart to deleteOutlookCalendarEvent() above - operates
// on ticket_schedules.schedule_outlook_event_id (one row per appointment) rather than the
// legacy tickets.ticket_outlook_event_id (one event per ticket). Mirrors it exactly
// otherwise: same Graph DELETE call shape, same silent-failure convention (a failed
// Outlook-side delete should never block the ITFlow-side archive of the appointment).
function deleteOutlookScheduleEvent($schedule_id) {
    global $mysqli;

    $schedule_id = intval($schedule_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT schedule_outlook_event_id, schedule_tech_id FROM ticket_schedules WHERE schedule_id = $schedule_id"
    ));

    if (!$row || empty($row['schedule_outlook_event_id'])) {
        return;
    }

    $access_token = getOutlookAccessToken($row['schedule_tech_id']);
    if ($access_token) {
        $ch = curl_init("https://graph.microsoft.com/v1.0/me/events/" . rawurlencode($row['schedule_outlook_event_id']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $access_token"],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    mysqli_query($mysqli, "UPDATE ticket_schedules SET schedule_outlook_event_id = NULL WHERE schedule_id = $schedule_id");
}

// ── End Outlook Calendar ────────────────────────────────────────────────────

/**
 * Generic authenticated Microsoft Graph request. Used for mailbox polling
 * (Admin > Mailboxes, Microsoft 365 provider) instead of raw IMAP.
 * Returns ['ok' => bool, 'code' => int, 'json' => array|null, 'raw' => string|false, 'error' => string]
 */
function microsoftGraphRequest(string $method, string $url, string $access_token, ?array $body = null): array {
    $ch = curl_init($url);
    $headers = ["Authorization: Bearer $access_token", "Content-Type: application/json"];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;

    return [
        'ok'    => ($raw !== false && $code >= 200 && $code < 300),
        'code'  => $code,
        'json'  => $json,
        'raw'   => $raw,
        'error' => $err,
    ];
}

function getTicketStatusName($ticket_status) {

    global $mysqli;

    $status_id = intval($ticket_status);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM ticket_statuses WHERE ticket_status_id = $status_id LIMIT 1"));

    if ($row) {
        return nullable_htmlentities($row['ticket_status_name']);
    }

    // Default return
    return "Unknown";

}


function fetchUpdates() {

    global $repo_branch;

    // Fetch the latest code changes but don't apply them
    exec("git fetch fork", $output, $result);
    $latest_version  = exec("git rev-parse fork/$repo_branch");
    $current_version = exec("git rev-parse HEAD");

    // Human-readable tag-based versions (e.g. v2.6.0)
    $current_version_tag = exec("git describe --tags --abbrev=0 HEAD 2>/dev/null") ?: $current_version;
    $latest_version_tag  = exec("git describe --tags --abbrev=0 fork/$repo_branch 2>/dev/null") ?: $latest_version;

    if ($current_version == $latest_version) {
        $update_message = "No Updates available";
    } else {
        $update_message = "New Updates are Available [$latest_version_tag]";
    }


    $updates = new stdClass();
    $updates->output = $output;
    $updates->result = $result;
    $updates->current_version = $current_version;
    $updates->latest_version = $latest_version;
    $updates->current_version_tag = $current_version_tag;
    $updates->latest_version_tag  = $latest_version_tag;
    $updates->update_message = $update_message;


    return $updates;

}

function getDomainExpirationDate($domain) {
    // Execute the whois command
    $result = shell_exec("whois " . escapeshellarg($domain));
    if (!$result || !checkdnsrr($domain, 'SOA')) {
        return null; // Return null if WHOIS query fails
    }

    $expireDate = '';

    // Regular expressions to match different date formats
    $patterns = [
        '/Expiration Date: (.+)/',
        '/Registry Expiry Date: (.+)/',
        '/expires: (.+)/',
        '/Expiry Date: (.+)/',
        '/renewal date: (.+)/',
        '/Expires On: (.+)/',
        '/paid-till: (.+)/',
        '/Expiration Time: (.+)/',
        '/\[Expires on\]\s+(.+)/',
        '/expire: (.+)/',
        '/validity: (.+)/',
        '/Expires on.*: (.+)/i',
        '/Expiry on.*: (.+)/i',
        '/renewal: (.+)/i',
        '/Expir\w+ Date: (.+)/i',
        '/Valid Until: (.+)/i',
        '/Valid until: (.+)/i',
        '/expire-date: (.+)/i',
        '/Expiration Date: (.+)/i',
        '/Registry Expiry Date: (.+)/i',
        '/Expire Date: (.+)/i',
        '/expiry: (.+)/i',
        '/expires: (.+)/i',
        '/Registry Expiry Date: (.+)/i',
        '/Expiration Time: (.+)/i',
        '/validity: (.+)/i',
        '/expires: (.+)/i',
        '/paid-till: (.+)/i',
        '/Expire Date: (.+)/i',
        '/Expiration Date: (.+)/i',
        '/expire: (.+)/i',
        '/expiry: (.+)/i',
        '/renewal date: (.+)/i',
        '/Expiration Date: (.+)/i',
        '/Expiration Time: (.+)/i',
        '/Expires: (.+)/i',
    ];

    // Known date formats
    $knownFormats = [
        "d-M-Y",
        "d-F-Y",
        "d-m-Y",
        "Y-m-d",
        "d.m.Y",
        "Y.m.d",
        "Y/m/d",
        "Y/m/d H:i:s",
        "Ymd",
        "Ymd H:i:s",
        "d/m/Y",
        "Y. m. d.",
        "Y.m.d H:i:s",
        "d-M-Y H:i:s",
        "D M d H:i:s T Y",
        "D M d Y",
        "Y-m-d\TH:i:s",
        "Y-m-d\TH:i:s\Z",
        "Y-m-d H:i:s\Z",
        "Y-m-d H:i:s",
        "d M Y H:i:s",
        "d/m/Y H:i:s",
        "d/m/Y H:i:s T",
        "B d Y",
        "d.m.Y H:i:s",
        "before M-Y",
        "before Y-m-d",
        "before Ymd",
        "Y-m-d H:i:s (\T\Z\Z)",
        "Y-M-d.",
    ];

    // Check each pattern to find a match
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $result, $matches)) {
            $expireDate = trim($matches[1]);
            break;
        }
    }

    if ($expireDate) {
        // Try parsing with known formats
        foreach ($knownFormats as $format) {
            $parsedDate = DateTime::createFromFormat($format, $expireDate);
            if ($parsedDate && $parsedDate->format($format) === $expireDate) {
                return $parsedDate->format('Y-m-d');
            }
        }

        // If none of the formats matched, try to parse it directly
        $parsedDate = date_create($expireDate);
        if ($parsedDate) {
            return $parsedDate->format('Y-m-d');
        }
    }

    return null; // Return null if expiration date is not found
}

// When provided a module name (e.g. module_support), returns the associated permission level (false=none, 1=read, 2=write, 3=full)
function lookupUserPermission($module) {
    global $mysqli, $session_is_admin, $session_user_role;

    if (isset($session_is_admin) && $session_is_admin === true) {
        return 3;
    }

    $module = sanitizeInput($module);

    $sql = mysqli_query(
        $mysqli,
        "SELECT
			user_role_permissions.user_role_permission_level
		FROM
			modules
		JOIN
			user_role_permissions
		ON
			modules.module_id = user_role_permissions.module_id
		WHERE
			module_name = '$module' AND user_role_permissions.user_role_id = $session_user_role"
    );

    $row = mysqli_fetch_assoc($sql);

    if (isset($row['user_role_permission_level'])) {
        return intval($row['user_role_permission_level']);
    }

    // Default return for no module permission
    return false;
}

// Single source of truth for "what does resolving a ticket actually mean" - a
// "Resolved" (4) selection is treated as an immediate alias for "Closed" (5)
// almost everywhere in this app. Several call sites independently duplicated
// this remap and a few of them missed it, leaving tickets stuck at status 4
// relying on the slow cron-based auto-close instead - route every status
// write through this instead of hand-rolling the check again.
function resolveTicketStatusId(int $status_id): int {
    return $status_id == 4 ? 5 : $status_id;
}

// Ensures a user has access to a module (e.g. module_support) with at least the required permission level provided (defaults to read)
function enforceUserPermission($module, $check_access_level = 1) {
    $permitted_access_level = lookupUserPermission($module);

    if (!$permitted_access_level || $permitted_access_level < $check_access_level) {
        $_SESSION['alert_type'] = "danger";
        $_SESSION['alert_message'] = WORDING_ROLECHECK_FAILED;
        $map = [
            "1" => "read",
            "2" => "write",
            "3" => "full"
        ];
        exit(WORDING_ROLECHECK_FAILED . "<br>Tell your admin: $map[$check_access_level] access to $module is not permitted for your role.");
    }
}

function enforceClientAccess($client_id = null) {
    global $mysqli, $session_user_id, $session_is_admin, $session_name;

    // Use global $client_id if none passed
    if ($client_id === null) {
        global $client_id;
    }

    if ($session_is_admin) {
        return true;
    }

    $client_id = (int) $client_id;
    $session_user_id = (int) $session_user_id;

    if (empty($client_id) || empty($session_user_id)) {
        flash_alert('Access Denied.', 'error');
        redirect('clients.php');
    }

    // Check if this user has any client permissions set
    $permissions_sql = "SELECT client_id
                        FROM user_client_permissions
                        WHERE user_id = $session_user_id
                        LIMIT 1";

    $permissions_result = mysqli_query($mysqli, $permissions_sql);

    // If no permission rows exist for this user, allow access by default
    if ($permissions_result && mysqli_num_rows($permissions_result) == 0) {
        return true;
    }

    // If permission rows exist, require this client
    $access_sql = "SELECT client_id
                   FROM user_client_permissions
                   WHERE user_id = $session_user_id
                   AND client_id = $client_id
                   LIMIT 1";

    $access_result = mysqli_query($mysqli, $access_sql);

    if ($access_result && mysqli_num_rows($access_result) > 0) {
        return true;
    }

    logAction(
        'Client',
        'Access',
        "$session_name was denied permission from accessing client",
        $client_id,
        $client_id
    );

    flash_alert('Access Denied - You do not have permission to access that client!', 'error');
    redirect('clients.php');
}

// TODO: Probably remove this
function enforceAdminPermission() {
    global $session_is_admin;
    if (!isset($session_is_admin) || !$session_is_admin) {
        exit(WORDING_ROLECHECK_FAILED . "<br>Tell your admin: Your role does not have admin access.");
    }
    return true;
}

function customAction($trigger, $entity) {
    $original_dir = getcwd(); // Save

    chdir(dirname(__FILE__));
    if (file_exists(__DIR__ . "/custom/custom_action_handler.php")) {
        include_once __DIR__ . "/custom/custom_action_handler.php";
    }

    chdir($original_dir); // Restore original working directory
}

function appNotify($type, $details, $action = null, $client_id = 0, $entity_id = 0, $push = true) {
    global $mysqli;

    $push_action = $action;

    $type    = substr($type, 0, 200);
    $details = substr($details, 0, 1000);
    $type_esc    = mysqli_real_escape_string($mysqli, $type);
    $details_esc = mysqli_real_escape_string($mysqli, $details);
    $client_id = intval($client_id);
    $entity_id = intval($entity_id);

    $action_sql = is_null($action)
        ? "NULL"
        : "'" . mysqli_real_escape_string($mysqli, substr($action, 0, 250)) . "'";

    $sql = mysqli_query($mysqli, "SELECT user_id FROM users
        WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
    ");

    while ($row = mysqli_fetch_assoc($sql)) {
        $user_id = intval($row['user_id']);

        mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = '$type_esc', notification = '$details_esc', notification_action = $action_sql, notification_client_id = $client_id, notification_entity_id = $entity_id, notification_user_id = $user_id");

        publishUserNotification($user_id, [
            'id'        => mysqli_insert_id($mysqli),
            'type'      => $type,
            'title'     => $type,
            'body'      => $details,
            'action'    => $push_action,
            'client_id' => $client_id,
            'entity_id' => $entity_id,
            'push'      => $push,
        ]);

        if ($push && push_allowed_for_user($user_id, $type)) {
            firebase_send_push_to_user($user_id, $type, $details, ['type' => strtolower($type), 'action' => $push_action ?? '']);
        }
    }
}

// Insert a targeted in-app notification for a specific user and publish it to
// their open real-time notification stream (api/v1/notifications_stream.php).
function notifyUser($user_id, $type, $details, $action = null, $client_id = 0, $entity_id = 0, $push = true) {
    global $mysqli;

    $user_id = intval($user_id);
    if (!$user_id) {
        return;
    }

    $client_id = intval($client_id);
    $entity_id = intval($entity_id);

    $action_sql = is_null($action) ? "NULL" : "'" . mysqli_real_escape_string($mysqli, substr($action, 0, 250)) . "'";
    $type_esc = mysqli_real_escape_string($mysqli, substr($type, 0, 200));
    $details_esc = mysqli_real_escape_string($mysqli, substr($details, 0, 1000));

    mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = '$type_esc', notification = '$details_esc', notification_action = $action_sql, notification_client_id = $client_id, notification_entity_id = $entity_id, notification_user_id = $user_id");

    publishUserNotification($user_id, [
        'id'        => mysqli_insert_id($mysqli),
        'type'      => $type,
        'title'     => $type,
        'body'      => $details,
        'action'    => $action,
        'client_id' => $client_id,
        'entity_id' => $entity_id,
        'push'      => $push,
    ]);

    if ($push && push_allowed_for_user($user_id, $type)) {
        firebase_send_push_to_user($user_id, $type, $details, ['type' => strtolower($type), 'action' => $action ?? '']);
    }
}

function logAction($type, $action, $description, $client_id = 0, $entity_id = 0) {
    global $mysqli, $session_user_agent, $session_ip, $session_user_id;

    $client_id = intval($client_id);
    $entity_id = intval($entity_id);
    $session_user_id = intval($session_user_id);

    if (empty($session_user_id)) {
        $session_user_id = 0;
    }

    // Only 1 of ~900 call sites across the app pre-escapes its $description (most
    // just interpolate a client/product/ticket name straight in) - any dynamic
    // value containing a single quote (e.g. "O'Brien", an item name with an
    // apostrophe) previously broke the INSERT with a SQL syntax fatal. Escaping
    // here, once, covers every caller instead of requiring each of the ~900 to
    // remember to do it themselves.
    $type = mysqli_real_escape_string($mysqli, substr($type, 0, 200));
    $action = mysqli_real_escape_string($mysqli, substr($action, 0, 255));
    $description = mysqli_real_escape_string($mysqli, substr($description, 0, 1000));
    $session_ip_esc = mysqli_real_escape_string($mysqli, (string) $session_ip);
    $session_user_agent_esc = mysqli_real_escape_string($mysqli, (string) $session_user_agent);

    mysqli_query($mysqli, "INSERT INTO logs SET log_type = '$type', log_action = '$action', log_description = '$description', log_ip = '$session_ip_esc', log_user_agent = '$session_user_agent_esc', log_client_id = $client_id, log_user_id = $session_user_id, log_entity_id = $entity_id");
}

/**
 * Records a CSAT rating for a ticket. Idempotent - a ticket can only be rated
 * once (WHERE ticket_csat_rating IS NULL), so a double-submit, a repeat visit to
 * a one-click email link, or an email-security-scanner prefetching multiple star
 * links only ever lets the first request win; every later one is a silent no-op.
 * Returns true if THIS call was the one that set the rating (and therefore ran
 * the low-rating side effects below), false if the ticket was already rated.
 * $rater_label is used in the internal note/notification wording (e.g. "guest",
 * a contact's name).
 */
function applyCsatRating(mysqli $mysqli, int $ticket_id, int $rating, string $comment, string $rater_label, int $low_rating_threshold): bool
{
    $ticket_id = intval($ticket_id);
    $rating = max(1, min(5, $rating));
    $comment = substr(trim($comment), 0, 1000);
    $comment_esc = mysqli_real_escape_string($mysqli, $comment);
    $comment_sql = $comment !== '' ? "'$comment_esc'" : 'NULL';

    mysqli_query($mysqli, "UPDATE tickets SET ticket_csat_rating = $rating, ticket_csat_comment = $comment_sql, ticket_csat_rated_at = NOW() WHERE ticket_id = $ticket_id AND ticket_csat_rating IS NULL");

    if (mysqli_affected_rows($mysqli) === 0) {
        return false;
    }

    if ($rating <= $low_rating_threshold) {
        $ticket_details = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
        $ticket_prefix = (string) ($ticket_details['ticket_prefix'] ?? '');
        $ticket_number = intval($ticket_details['ticket_number'] ?? 0);
        $ticket_client_id = intval($ticket_details['ticket_client_id'] ?? 0);

        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 2, ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $ticket_id");

        $rater_label_esc = mysqli_real_escape_string($mysqli, $rater_label);
        $note = "Automatically reopened — $rater_label_esc rated this ticket $rating/5" . ($comment_esc !== '' ? ": \"$comment_esc\"" : '.');
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$note', ticket_reply_type = 'Internal', ticket_reply_by = 0, ticket_reply_ticket_id = $ticket_id");

        logAction("Ticket", "Reopened", "Auto-reopened after a low CSAT rating ($rating/5) from $rater_label", $ticket_client_id, $ticket_id);
        appNotify("Feedback", ucfirst($rater_label) . " rated ticket " . nullable_htmlentities($ticket_prefix) . "$ticket_number $rating/5 — ticket automatically reopened for follow-up (ID: $ticket_id)", "/agent/ticket.php?ticket_id=$ticket_id", $ticket_client_id, $ticket_id);
    }

    return true;
}

/**
 * The 1-5 CSAT scale's face-emoji representation and short label, shared by
 * every place a rating is rendered (widgets, single-rating displays, emails,
 * reports) so the mapping only lives in one place. The underlying stored scale
 * is still a plain 1-5 tinyint (ticket_csat_rating) - faces are presentation only.
 */
function csatFaceEmoji(int $rating): string
{
    $faces = [1 => '😡', 2 => '🙁', 3 => '😐', 4 => '🙂', 5 => '😄'];
    return $faces[$rating] ?? '😐';
}

function csatFaceLabel(int $rating): string
{
    $labels = [1 => 'Very unhappy', 2 => 'Unhappy', 3 => 'Neutral', 4 => 'Happy', 5 => 'Very happy'];
    return $labels[$rating] ?? '';
}

/**
 * Renders the 1-5 face-emoji clickable row used in CSAT request/reminder
 * emails. Industry-standard pattern (Delighted/Zendesk/etc. all use a face
 * scale, not stars, specifically because it reads faster than counting icons)
 * - each face links directly to guest_rate.php's one-click GET rating endpoint
 * so the recipient can rate without leaving their inbox (see guest_rate.php for
 * why GET is used here specifically, and how it stays safe: url_key-scoped +
 * idempotent first-click-wins). Inline-styled table for email client
 * compatibility - no external stylesheet, no JS. Emoji are standard Unicode
 * (not custom images), so they render natively in virtually every mail client
 * without needing images-enabled.
 */
function csatEmailRatingLinksHtml(string $base_url, int $ticket_id, string $url_key): string
{
    $cells = '';
    for ($n = 1; $n <= 5; $n++) {
        $link = htmlspecialchars("https://$base_url/guest/guest_rate.php?ticket_id=$ticket_id&url_key=$url_key&rating=$n");
        $emoji = csatFaceEmoji($n);
        $cells .= '<td style="text-align:center;padding:0 10px;">'
            . "<a href=\"$link\" style=\"text-decoration:none;font-size:32px;display:block;\">$emoji</a>"
            . '</td>';
    }
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:12px 0;"><tr>' . $cells . '</tr></table>';
}

function logApp($category, $type, $details) {
    global $mysqli;

    $category = mysqli_real_escape_string($mysqli, substr($category, 0, 200));
    // 20000 (not the old 1000) so a full mobile-app crash stack trace + device/app
    // metadata (api/v1/crash_reports.php) fits without truncation; app_log_details
    // is TEXT as of DB 2.6.41 so the column itself no longer caps this either.
    $details = mysqli_real_escape_string($mysqli, substr($details, 0, 20000));

    mysqli_query($mysqli, "INSERT INTO app_logs SET app_log_category = '$category', app_log_type = '$type', app_log_details = '$details'");
}

function logAuth($status, $details) {
    global $mysqli, $session_user_agent, $session_ip, $session_user_id;

    if (empty($session_user_id)) {
        $session_user_id = 0;
    }

    mysqli_query($mysqli, "INSERT INTO auth_logs SET auth_log_status = $status, auth_log_details = '$details', auth_log_ip = '$session_ip', auth_log_user_agent = '$session_user_agent', auth_log_user_id = $session_user_id");
}

// One row per inbound email the mailbox poller touches - see processInboundMessage() in
// cron/ticket_email_parser.php and Admin > Maintenance > Email Log. Distinct from
// logAction()/logApp(): those are the general audit/app trails, this is specifically
// "what happened to this email" (including messages that matched nothing and were left
// alone), so admins can see incoming mail activity without digging through other logs.
function logMailEvent(?int $mailbox_id, ?string $from_email, ?string $from_name, ?string $subject, string $outcome, ?string $detail = null, ?int $ticket_id = null, ?int $mail_request_id = null): void {
    global $mysqli;

    $mailbox_id_sql = $mailbox_id ? intval($mailbox_id) : 'NULL';
    $from_email_sql = $from_email !== null ? "'" . mysqli_real_escape_string($mysqli, $from_email) . "'" : 'NULL';
    $from_name_sql = $from_name !== null ? "'" . mysqli_real_escape_string($mysqli, $from_name) . "'" : 'NULL';
    $subject_sql = $subject !== null ? "'" . mysqli_real_escape_string($mysqli, mb_substr($subject, 0, 500)) . "'" : 'NULL';
    $outcome_esc = mysqli_real_escape_string($mysqli, $outcome);
    $detail_sql = $detail !== null ? "'" . mysqli_real_escape_string($mysqli, mb_substr($detail, 0, 500)) . "'" : 'NULL';
    $ticket_id_sql = $ticket_id ? intval($ticket_id) : 'NULL';
    $mail_request_id_sql = $mail_request_id ? intval($mail_request_id) : 'NULL';

    mysqli_query($mysqli, "INSERT INTO mail_log SET
        mail_log_mailbox_id = $mailbox_id_sql,
        mail_log_from_email = $from_email_sql,
        mail_log_from_name = $from_name_sql,
        mail_log_subject = $subject_sql,
        mail_log_outcome = '$outcome_esc',
        mail_log_detail = $detail_sql,
        mail_log_ticket_id = $ticket_id_sql,
        mail_log_mail_request_id = $mail_request_id_sql
    ");
}

/** ------------------------------------------------------------------
 * Ticket creation from inbound email
 *
 * addTicket()/addReply() were originally cron-only (cron/ticket_email_parser.php).
 * Relocated here so both the mailbox poller and the admin-triggered "Convert to
 * Ticket" action (mail_requests, see admin/post/mail_requests.php) can call the
 * exact same ticket-creation logic instead of two copies drifting apart.
 * ------------------------------------------------------------------ */

// Allow-list for saved ticket/mail-request attachments. Anything else is blocked
// and logged rather than saved to disk.
function ticketAttachmentAllowedExtensions(): array {
    return ['jpg', 'jpeg', 'gif', 'png', 'webp', 'svg', 'pdf', 'txt', 'md', 'doc', 'docx', 'csv', 'xls', 'xlsx', 'xlsm', 'zip', 'tar', 'gz'];
}

// Cached per-request; used in ticket notification emails.
function getCompanyNameAndPhone(): array {
    global $mysqli;
    static $cached = null;

    if ($cached === null) {
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $cached = [
            'name'  => sanitizeInput($row['company_name']),
            'phone' => sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code'])),
        ];
    }

    return $cached;
}

// Resolves what ticket_assigned_to should be at ticket-creation time: an
// explicit assignee (a form field, a recurring-ticket template's stored
// assignee, an API request body value, etc.) always wins; otherwise falls
// back to the admin-configured default technician (Admin > Settings >
// Tickets), or stays unassigned (0) if neither is set. Post-creation
// automation rules (assign_to/escalate actions, see
// includes/ticket_automation_dispatch.php) still run after the insert on the
// sites that have them wired up and can reassign from here - this is only
// the fallback used at INSERT time, one tier below an explicit pick and one
// tier above staying unassigned.
function resolveTicketAssignee(int $explicit_assigned_to): int {
    global $config_ticket_default_technician_id;
    if ($explicit_assigned_to > 0) {
        return $explicit_assigned_to;
    }
    return intval($config_ticket_default_technician_id ?? 0);
}

function addTicket($contact_id, $contact_name, $contact_email, $client_id, $date, $subject, $message, $attachments, $original_message_file, $ccs, $mailbox_id = null) {
    global $mysqli, $config_app_name, $config_ticket_prefix, $config_ticket_client_general_notifications, $config_ticket_new_ticket_notification_email, $config_base_url, $config_ticket_from_name, $config_ticket_from_email, $config_ticket_default_billable;
    $company = getCompanyNameAndPhone();
    $company_name = $company['name'];
    $company_phone = $company['phone'];
    $allowed_extensions = ticketAttachmentAllowedExtensions();
    $bad_pattern = "/do[\W_]*not[\W_]*reply|no[\W_]*reply/i"; // Email addresses to ignore

    // Reply/notify as the mailbox this ticket actually came through (needs its own
    // Send-As permission), falling back to the global Ticket identity for tickets
    // created outside the mailbox flow.
    $from_email = $config_ticket_from_email;
    $from_name  = $config_ticket_from_name;
    if ($mailbox_id) {
        $mb = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT mailbox_email, mailbox_from_name FROM mailboxes WHERE mailbox_id = " . intval($mailbox_id) . " AND mailbox_archived_at IS NULL LIMIT 1"));
        if ($mb && !empty($mb['mailbox_email'])) {
            $from_email = $mb['mailbox_email'];
            $from_name  = $mb['mailbox_from_name'] ?: $config_ticket_from_name;
        }
    }

    // Atomically increment and get the new ticket number
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
            config_ticket_next_number = config_ticket_next_number + 1
        WHERE company_id = 1
    ");

    $ticket_number = mysqli_insert_id($mysqli);

    // Clean up the message
    $message = trim($message);
    // Remove DOCTYPE and meta tags
    $message = preg_replace('/<!DOCTYPE[^>]*>/i', '', $message);
    $message = preg_replace('/<meta[^>]*>/i', '', $message);
    // Remove <html>, <head>, <body> and their closing tags
    $message = preg_replace('/<\/?(html|head|body)[^>]*>/i', '', $message);
    // Collapse excess whitespace
    $message = preg_replace('/\s+/', ' ', $message);
    // Convert newlines to <br>
    $message = nl2br($message);
    // Wrap final formatted message
    $message = "<i>Email from: <b>$contact_name</b> &lt;$contact_email&gt; at $date:-</i> <br><br><div style='line-height:1.5;'>$message</div>";

    $ticket_prefix_esc = mysqli_real_escape_string($mysqli, $config_ticket_prefix);
    $message_esc = mysqli_real_escape_string($mysqli, $message);
    $contact_email_esc = mysqli_real_escape_string($mysqli, $contact_email);
    $subject_esc = mysqli_real_escape_string($mysqli, $subject);
    $client_id = intval($client_id);

    $url_key = randomString(32);
    $ticket_mailbox_id_sql = ($mailbox_id !== null && $mailbox_id !== '') ? intval($mailbox_id) : 'NULL';
    $resolved_assigned_to = resolveTicketAssignee(0);
    $ticket_status = $resolved_assigned_to > 0 ? 2 : 1;

    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = '$ticket_prefix_esc', ticket_number = $ticket_number, ticket_source = 'Email', ticket_subject = '$subject_esc', ticket_details = '$message_esc', ticket_priority = 'Low', ticket_status = $ticket_status, ticket_billable = $config_ticket_default_billable, ticket_created_by = 0, ticket_contact_id = $contact_id, ticket_url_key = '$url_key', ticket_client_id = $client_id, ticket_mailbox_id = $ticket_mailbox_id_sql, ticket_assigned_to = $resolved_assigned_to");
    $id = mysqli_insert_id($mysqli);

    // Logging
    logAction("Ticket", "Create", "Email parser: Client contact $contact_email_esc created ticket $ticket_prefix_esc$ticket_number ($subject) ($id)", $client_id, $id);

    // Broadcast to active agents so the mobile app gets a push for it too,
    // even when the default-technician setting (Admin > Settings > Tickets)
    // assigned it - that setting only sets the fallback assignee, it doesn't
    // change who gets notified about new tickets.
    appNotify("Ticket", "Email parser: $contact_email_esc raised a new ticket $ticket_prefix_esc$ticket_number - $subject", "/agent/ticket.php?ticket_id=$id" . ($client_id ? "&client_id=$client_id" : ''), $client_id, $id);

    mkdirMissing(__DIR__ . '/uploads/tickets/');
    $att_dir = __DIR__ . '/uploads/tickets/' . $id . '/';
    mkdirMissing($att_dir);

    // Move original .eml into the ticket folder, if present. Always true for the cron
    // poller (it just wrote the file); guarded here because convertMailRequestToTicket()
    // rehydrates it from a holding dir first and that file can legitimately be missing -
    // without this check a dead ticket_attachments row (linking to a file that was never
    // moved) would get created instead of silently skipping it.
    $original_eml_path = __DIR__ . "/uploads/tmp/{$original_message_file}";
    if (!empty($original_message_file) && file_exists($original_eml_path)) {
        rename($original_eml_path, "{$att_dir}{$original_message_file}");
        $original_message_file_esc = mysqli_real_escape_string($mysqli, $original_message_file);
        mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = 'Original-parsed-email.eml', ticket_attachment_reference_name = '$original_message_file_esc', ticket_attachment_ticket_id = $id");
    }

    // Save non-inline attachments
    foreach ($attachments as $attachment) {
        $att_name = $attachment['name'];
        $att_extension = strtolower(pathinfo($att_name, PATHINFO_EXTENSION));

        if (in_array($att_extension, $allowed_extensions)) {
            $att_saved_filename = md5(uniqid(rand(), true)) . '.' . $att_extension;
            $att_saved_path = $att_dir . $att_saved_filename;
            file_put_contents($att_saved_path, $attachment['content']);

            $ticket_attachment_name = sanitizeInput($att_name);
            $ticket_attachment_reference_name = sanitizeInput($att_saved_filename);

            $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_name);
            $ticket_attachment_reference_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_reference_name);
            mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = '$ticket_attachment_name_esc', ticket_attachment_reference_name = '$ticket_attachment_reference_name_esc', ticket_attachment_ticket_id = $id");
        } else {
            $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $att_name);
            logAction("Ticket", "Edit", "Email parser: Blocked attachment $ticket_attachment_name_esc from Client contact $contact_email_esc for ticket $ticket_prefix_esc$ticket_number", $client_id, $id);
        }
    }

    // Add unknown guests as ticket watcher
    if ($client_id == 0 && !preg_match($bad_pattern, $contact_email_esc)) {
        mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = '$contact_email_esc', watcher_ticket_id = $id");
    }

    // Add CCs as ticket watchers
    foreach ($ccs as $cc) {
        if (filter_var($cc, FILTER_VALIDATE_EMAIL) && !preg_match($bad_pattern, $cc)) {
            $cc_esc = mysqli_real_escape_string($mysqli, $cc);
            mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = '$cc_esc', watcher_ticket_id = $id");
        }
    }

    // External email
    $data = [];
    if ($config_ticket_client_general_notifications == 1 && !preg_match($bad_pattern, $contact_email)) {
        $subject_email = "Ticket created - [$config_ticket_prefix$ticket_number] - $subject";
        $body = "<i style='color: #808080'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Thank you for your email. A ticket regarding \"$subject\" has been automatically created for you.<br><br>Ticket: $config_ticket_prefix$ticket_number<br>Subject: $subject<br>Status: New<br>Portal: <a href='https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$id&url_key=$url_key'>View ticket</a><br><br>--<br>$company_name - Support<br>$from_email<br>$company_phone";
        $data[] = [
            'from' => $from_email,
            'from_name' => $from_name,
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
            'subject' => $subject_email,
            'body' => mysqli_real_escape_string($mysqli, $body)
        ];
    }

    // Internal email
    if ($config_ticket_new_ticket_notification_email) {
        if ($client_id == 0) {
            $client_name = "Guest";
            $client_uri = '';
        } else {
            $client_sql = mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = $client_id");
            $client_row = mysqli_fetch_assoc($client_sql);
            $client_name = sanitizeInput($client_row['client_name']);
            $client_uri = "&client_id=$client_id";
        }
        $email_subject = "$config_app_name - New Ticket - $client_name: $subject";
        $email_body = "Hello, <br><br>This is a notification that a new ticket has been raised in ITFlow. <br>Client: $client_name<br>Priority: Low (email parsed)<br>Link: https://$config_base_url/agent/ticket.php?ticket_id=$id$client_uri <br><br>--------------------------------<br><br><b>$subject</b><br>$message";

        $data[] = [
            'from' => $from_email,
            'from_name' => $from_name,
            'recipient' => $config_ticket_new_ticket_notification_email,
            'recipient_name' => $from_name,
            'subject' => $email_subject,
            'body' => mysqli_real_escape_string($mysqli, $email_body)
        ];
    }

    addToMailQueue($data);
    customAction('ticket_create', $id);

    // Run ticket_created automation rules (fire-and-forget; never blocks/fails insert)
    require_once __DIR__ . '/includes/ticket_automation_dispatch.php';
    runTicketCreatedAutomation($mysqli, $id);

    return $id;
}

function addReply($from_email, $date, $subject, $ticket_number, $message, $attachments, $mailbox_id = null, $from_name = null, $ccs = [], $original_message_file = '') {
    global $mysqli, $config_app_name, $config_ticket_prefix, $config_base_url, $config_ticket_from_name, $config_ticket_from_email;
    $company = getCompanyNameAndPhone();
    $company_name = $company['name'];
    $company_phone = $company['phone'];
    $allowed_extensions = ticketAttachmentAllowedExtensions();

    // Reply/notify as the mailbox this ticket actually came through (needs its own
    // Send-As permission) — named "outbound_" here since $from_email is already
    // taken by the incoming client sender's address.
    $outbound_from_email = $config_ticket_from_email;
    $outbound_from_name  = $config_ticket_from_name;
    if ($mailbox_id) {
        $mb = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT mailbox_email, mailbox_from_name FROM mailboxes WHERE mailbox_id = " . intval($mailbox_id) . " AND mailbox_archived_at IS NULL LIMIT 1"));
        if ($mb && !empty($mb['mailbox_email'])) {
            $outbound_from_email = $mb['mailbox_email'];
            $outbound_from_name  = $mb['mailbox_from_name'] ?: $config_ticket_from_name;
        }
    }

    $ticket_reply_type = 'Client';
    // $message contains the raw HTML body from IMAP

    // 1) Remove the reply separator and everything below it (HTML-aware)
    // This matches: <i ...>##- Please type your reply above this line -##</i> and EVERYTHING after it
    $message = preg_replace(
        '/<i[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>.*$/is',
        '',
        $message
    );

    // 2) Clean up the remaining message

    // Remove DOCTYPE and meta tags
    $message = preg_replace('/<!DOCTYPE[^>]*>/i', '', $message);
    $message = preg_replace('/<meta[^>]*>/i', '', $message);

    // Remove <html>, <head>, <body> and their closing tags
    $message = preg_replace('/<\/?(html|head|body)[^>]*>/i', '', $message);

    // Trim leading/trailing whitespace
    $message = trim($message);

    // Normalize line breaks to spaces
    $message = preg_replace('/\r\n|\r|\n/', ' ', $message);

    // Convert to <br> for HTML display
    $message = nl2br($message);

    // 3) Final wrapper
    $message = "<i>Email from: $from_email at $date:-</i><br><br><div style='line-height:1.5;'>$message</div>";

    $ticket_number_esc = intval($ticket_number);
    $message_esc = mysqli_real_escape_string($mysqli, $message);
    $from_email_esc = mysqli_real_escape_string($mysqli, $from_email);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_subject, ticket_status, ticket_contact_id, ticket_client_id, contact_email, client_name
        FROM tickets
        LEFT JOIN contacts on tickets.ticket_contact_id = contacts.contact_id
        LEFT JOIN clients on tickets.ticket_client_id = clients.client_id
        WHERE ticket_number = $ticket_number_esc LIMIT 1"));

    if ($row) {
        $ticket_id = intval($row['ticket_id']);
        $ticket_subject = sanitizeInput($row['ticket_subject']);
        $ticket_status = sanitizeInput($row['ticket_status']);
        $ticket_reply_contact = intval($row['ticket_contact_id']);
        $ticket_contact_email = sanitizeInput($row['contact_email']);
        $client_id = intval($row['ticket_client_id']);
        if ($client_id) {
            $client_uri = "&client_id=$client_id";
        } else {
            $client_uri = '';
        }
        $client_name = sanitizeInput($row['client_name']);

        if ($ticket_status == 5) {
            $config_ticket_prefix_esc = mysqli_real_escape_string($mysqli, $config_ticket_prefix);
            $ticket_number_esc2 = mysqli_real_escape_string($mysqli, $ticket_number);

            appNotify("Ticket", "Email parser: $from_email attempted to re-open ticket $config_ticket_prefix_esc$ticket_number_esc2 (ID $ticket_id) - check inbox manually to see email", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id);

            $email_subject = "Action required: This ticket is already closed";
            $email_body = "Hi there, <br><br>You've tried to reply to a ticket that is closed - we won't see your response. <br><br>Please raise a new ticket by sending a new e-mail to our support address below. <br><br>--<br>$company_name - Support<br>$outbound_from_email<br>$company_phone";

            $data = [
                [
                    'from' => $outbound_from_email,
                    'from_name' => $outbound_from_name,
                    'recipient' => $from_email,
                    'recipient_name' => $from_email,
                    'subject' => $email_subject,
                    'body' => mysqli_real_escape_string($mysqli, $email_body)
                ]
            ];

            addToMailQueue($data);
            return true;
        }

        if (empty($ticket_contact_email) || $ticket_contact_email !== $from_email) {
            $from_email_esc2 = mysqli_real_escape_string($mysqli, $from_email);
            $row2 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id FROM contacts WHERE contact_email = '$from_email_esc2' AND contact_client_id = $client_id LIMIT 1"));
            if ($row2) {
                $ticket_reply_contact = intval($row2['contact_id']);
            } else {
                // The sender does not match this ticket's registered contact and isn't any
                // other contact of the same client either - ticket numbers are sequential
                // and routinely disclosed in notification subjects, so anyone (including a
                // spoofed mailer-daemon NDR) can target another client's ticket just by
                // putting a guessed [PREFIX-N] in their subject. Never insert their content
                // straight into the ticket - queue it for manual review like an unmatched
                // sender, the same as a message that didn't match any ticket at all.
                createMailRequestFromInbound(
                    intval($mailbox_id),
                    $from_email,
                    $from_name ?: $from_email,
                    $subject,
                    is_array($ccs) ? $ccs : [],
                    $date,
                    $message,
                    $attachments,
                    $original_message_file ?: ''
                );
                return false;
            }
        }

        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$message_esc', ticket_reply_type = '$ticket_reply_type', ticket_reply_time_worked = '00:00:00', ticket_reply_by = $ticket_reply_contact, ticket_reply_ticket_id = $ticket_id");
        $reply_id = mysqli_insert_id($mysqli);

        // New client email reply invalidates any cached AI summary for this ticket
        require_once __DIR__ . '/includes/ai_functions.php';
        markTicketSummaryStale($mysqli, $ticket_id);

        $ticket_dir = __DIR__ . '/uploads/tickets/' . $ticket_id . '/';
        mkdirMissing($ticket_dir);

        foreach ($attachments as $attachment) {
            $att_name = $attachment['name'];
            $att_extension = strtolower(pathinfo($att_name, PATHINFO_EXTENSION));

            if (in_array($att_extension, $allowed_extensions)) {
                $att_saved_filename = md5(uniqid(rand(), true)) . '.' . $att_extension;
                $att_saved_path = $ticket_dir . $att_saved_filename;
                file_put_contents($att_saved_path, $attachment['content']);

                $ticket_attachment_name = sanitizeInput($att_name);
                $ticket_attachment_reference_name = sanitizeInput($att_saved_filename);

                $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_name);
                $ticket_attachment_reference_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_reference_name);
                mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = '$ticket_attachment_name_esc', ticket_attachment_reference_name = '$ticket_attachment_reference_name_esc', ticket_attachment_reply_id = $reply_id, ticket_attachment_ticket_id = $ticket_id");
            } else {
                $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $att_name);
                logAction("Ticket", "Edit", "Email parser: Blocked attachment $ticket_attachment_name_esc from Client contact $from_email_esc for ticket $config_ticket_prefix$ticket_number_esc", $client_id, $ticket_id);
            }
        }

        $ticket_assigned_to_sql = mysqli_query($mysqli, "SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
        if ($ticket_assigned_to_sql) {
            $row3 = mysqli_fetch_assoc($ticket_assigned_to_sql);
            $ticket_assigned_to = intval($row3['ticket_assigned_to']);

            if ($ticket_assigned_to) {
                $tech_sql = mysqli_query($mysqli, "SELECT user_email, user_name FROM users WHERE user_id = $ticket_assigned_to LIMIT 1");
                $tech_row = mysqli_fetch_assoc($tech_sql);
                $tech_email = sanitizeInput($tech_row['user_email']);
                $tech_name = sanitizeInput($tech_row['user_name']);

                $email_subject = "$config_app_name - Ticket updated - [$config_ticket_prefix$ticket_number] $ticket_subject";
                $email_body    = "Hello $tech_name,<br><br>A new reply has been added to the below ticket.<br><br>Client: $client_name<br>Ticket: $config_ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Link: https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri<br><br>--------------------------------<br>$message_esc";

                $data = [
                    [
                        'from' => $outbound_from_email,
                        'from_name' => $outbound_from_name,
                        'recipient' => $tech_email,
                        'recipient_name' => $tech_name,
                        'subject' => mysqli_real_escape_string($mysqli, $email_subject),
                        'body' => mysqli_real_escape_string($mysqli, $email_body)
                    ]
                ];
                addToMailQueue($data);
            }
        }

        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 2, ticket_resolved_at = NULL WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id LIMIT 1");

        logAction("Ticket", "Edit", "Email parser: Client contact $from_email_esc updated ticket $config_ticket_prefix$ticket_number_esc ($subject)", $client_id, $ticket_id);
        customAction('ticket_reply_client', $ticket_id);
        return true;
    } else {
        return false;
    }
}

/** ------------------------------------------------------------------
 * Mail Requests - review queue for unknown-sender email
 *
 * When a mailbox has mailbox_parse_unknown_senders enabled, an email from a sender
 * that doesn't match any known contact/domain no longer becomes a ticket immediately
 * - it's held here for an admin to review on admin/mail_requests.php, then either
 * converted into a ticket (picking the client) or dismissed. Bounce/NDR handling in
 * processInboundMessage() is unrelated and unaffected.
 * ------------------------------------------------------------------ */

// Called from processInboundMessage() step 5 instead of addTicket(). Always succeeds
// (returns the new mail_request_id) so the poller marks the source message read/moved -
// otherwise it gets re-fetched and re-queued every single cron run.
function createMailRequestFromInbound(int $mailbox_id, string $from_email, string $from_name, string $subject, array $ccs, string $received_at, string $message_body, array $attachments, string $original_message_file): int {
    global $mysqli;

    $from_email_esc = mysqli_real_escape_string($mysqli, $from_email);
    $from_name_esc = mysqli_real_escape_string($mysqli, $from_name);
    $subject_esc = mysqli_real_escape_string($mysqli, mb_substr($subject, 0, 500));
    $body_esc = mysqli_real_escape_string($mysqli, $message_body);
    $ccs_esc = mysqli_real_escape_string($mysqli, implode(',', $ccs));
    $received_at_esc = mysqli_real_escape_string($mysqli, $received_at);
    $eml_esc = mysqli_real_escape_string($mysqli, $original_message_file);

    mysqli_query($mysqli, "INSERT INTO mail_requests SET
        mail_request_mailbox_id = " . intval($mailbox_id) . ",
        mail_request_from_email = '$from_email_esc',
        mail_request_from_name = '$from_name_esc',
        mail_request_subject = '$subject_esc',
        mail_request_body = '$body_esc',
        mail_request_ccs = '$ccs_esc',
        mail_request_received_at = '$received_at_esc',
        mail_request_eml_reference_name = '$eml_esc'
    ");
    $mail_request_id = mysqli_insert_id($mysqli);

    mkdirMissing(__DIR__ . '/uploads/mail_requests/');
    $holding_dir = __DIR__ . '/uploads/mail_requests/' . $mail_request_id . '/';
    mkdirMissing($holding_dir);

    $tmp_eml_path = __DIR__ . "/uploads/tmp/{$original_message_file}";
    if (file_exists($tmp_eml_path)) {
        rename($tmp_eml_path, $holding_dir . $original_message_file);
    }

    // No extension allow-list filtering here - that happens once, inside addTicket(),
    // at conversion time. Everything is kept so nothing is lost before a human looks at it -
    // but the holding file itself is always saved as .bin (regardless of the sender-supplied
    // extension) since admin/modals/mail_request/mail_request_view.php links straight to this
    // file on disk. An inbound .html/.svg attachment served with its original extension would
    // render inline at this app's own origin when an admin clicks it (stored XSS in an admin
    // session); .bin is never rendered inline by a browser. The real filename/extension is
    // preserved separately in mail_request_attachment_name for display and is what addTicket()
    // re-applies its own allow-list against at conversion time.
    foreach ($attachments as $attachment) {
        $att_name = $attachment['name'];
        $att_saved_filename = md5(uniqid(rand(), true)) . '.bin';
        file_put_contents($holding_dir . $att_saved_filename, $attachment['content']);

        $att_name_esc = mysqli_real_escape_string($mysqli, sanitizeInput($att_name));
        $att_saved_filename_esc = mysqli_real_escape_string($mysqli, $att_saved_filename);
        mysqli_query($mysqli, "INSERT INTO mail_request_attachments SET
            mail_request_attachment_name = '$att_name_esc',
            mail_request_attachment_reference_name = '$att_saved_filename_esc',
            mail_request_attachment_mail_request_id = $mail_request_id
        ");
    }

    logAction("Mail Request", "Create", "Email parser: unknown sender $from_email_esc queued as a request ($subject_esc)", 0, $mail_request_id);
    appNotify("Ticket", "Email parser: unknown sender $from_email_esc sent an email - review it on the Requests page", "/admin/mail_requests.php", 0);

    return $mail_request_id;
}

// Converts a pending mail request into a real ticket via addTicket(), resolving/creating
// a contact under $client_id first (skipped entirely for Guest, client_id = 0 - matches
// today's unknown-sender ticket behavior). Returns the new ticket id, or null if the
// request was already converted/dismissed or no longer exists.
function convertMailRequestToTicket(int $mail_request_id, int $client_id): ?int {
    global $mysqli, $session_name;

    $mail_request_id = intval($mail_request_id);
    $client_id = intval($client_id);

    $req = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mail_requests WHERE mail_request_id = $mail_request_id AND mail_request_archived_at IS NULL LIMIT 1"));
    if (!$req) {
        return null;
    }

    $mailbox_id = intval($req['mail_request_mailbox_id']);
    $from_email = $req['mail_request_from_email'];
    $contact_name = !empty($req['mail_request_from_name']) ? $req['mail_request_from_name'] : $from_email;
    $contact_email = $from_email;
    $subject = $req['mail_request_subject'];
    $message_body = (string) $req['mail_request_body'];
    $ccs = !empty($req['mail_request_ccs']) ? explode(',', $req['mail_request_ccs']) : [];
    $received_at = $req['mail_request_received_at'];
    $eml_filename = $req['mail_request_eml_reference_name'];

    $contact_id = 0;
    if ($client_id > 0) {
        $from_email_esc = mysqli_real_escape_string($mysqli, $from_email);
        $existing_contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_email FROM contacts WHERE contact_email = '$from_email_esc' AND contact_client_id = $client_id AND contact_archived_at IS NULL LIMIT 1"));

        if ($existing_contact) {
            $contact_id = intval($existing_contact['contact_id']);
            $contact_name = $existing_contact['contact_name'];
            $contact_email = $existing_contact['contact_email'];
        } else {
            $contact_name_esc = mysqli_real_escape_string($mysqli, $contact_name);
            mysqli_query($mysqli, "INSERT INTO contacts SET contact_name = '$contact_name_esc', contact_email = '$from_email_esc', contact_notes = 'Added automatically via email parsing.', contact_client_id = $client_id");
            $contact_id = mysqli_insert_id($mysqli);

            logAction("Contact", "Create", "$session_name created contact $contact_name_esc", $client_id, $contact_id);
            customAction('contact_create', $contact_id);
        }
    }

    $holding_dir = __DIR__ . '/uploads/mail_requests/' . $mail_request_id . '/';

    // Rehydrate the .eml back into uploads/tmp/ under its original name so addTicket()'s
    // own internal rename() finds it exactly where it always expects it.
    if (!empty($eml_filename) && file_exists($holding_dir . $eml_filename)) {
        mkdirMissing(__DIR__ . '/uploads/tmp/');
        copy($holding_dir . $eml_filename, __DIR__ . "/uploads/tmp/{$eml_filename}");
    }

    $attachments = [];
    $att_rows = mysqli_query($mysqli, "SELECT mail_request_attachment_name, mail_request_attachment_reference_name FROM mail_request_attachments WHERE mail_request_attachment_mail_request_id = $mail_request_id");
    while ($att_row = mysqli_fetch_assoc($att_rows)) {
        $att_path = $holding_dir . $att_row['mail_request_attachment_reference_name'];
        if (file_exists($att_path)) {
            $attachments[] = ['name' => $att_row['mail_request_attachment_name'], 'content' => file_get_contents($att_path)];
        }
    }

    $ticket_id = addTicket($contact_id, $contact_name, $contact_email, $client_id, $received_at, $subject, $message_body, $attachments, $eml_filename, $ccs, $mailbox_id);

    mysqli_query($mysqli, "UPDATE mail_requests SET mail_request_archived_at = NOW(), mail_request_converted_ticket_id = " . intval($ticket_id) . " WHERE mail_request_id = $mail_request_id");

    removeDirectory($holding_dir);

    logAction("Mail Request", "Convert", "$session_name converted a mail request into ticket #$ticket_id", $client_id, $ticket_id);

    return $ticket_id;
}

// Dismisses (soft-deletes) a pending mail request and removes its holding directory.
function dismissMailRequest(int $mail_request_id): bool {
    global $mysqli, $session_name;

    $mail_request_id = intval($mail_request_id);
    $req = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT mail_request_id FROM mail_requests WHERE mail_request_id = $mail_request_id AND mail_request_archived_at IS NULL LIMIT 1"));
    if (!$req) {
        return false;
    }

    removeDirectory(__DIR__ . '/uploads/mail_requests/' . $mail_request_id . '/');

    mysqli_query($mysqli, "UPDATE mail_requests SET mail_request_archived_at = NOW() WHERE mail_request_id = $mail_request_id");

    logAction("Mail Request", "Delete", "$session_name dismissed a mail request", 0, $mail_request_id);

    return true;
}

// Helper function for missing data fallback
function getFallback($data) {
    return !empty($data) ? $data : '-';
}

/**
 * Retrieves a specified field's value from a table based on the record's id.
 * It validates the table and field names, automatically determines the primary key (or uses the first column as fallback),
 * and returns the field value with an appropriate escaping method.
 *
 * @param string $table         The name of the table.
 * @param int    $id            The record's id.
 * @param string $field         The field (column) to retrieve.
 * @param string $escape_method The escape method: 'sql' (default, auto-detects int), 'html', 'json', or 'int'.
 *
 * @return mixed The escaped field value, or null if not found or invalid input.
 */
function getFieldById($table, $id, $field, $escape_method = 'sql') {
    global $mysqli;  // Use the global MySQLi connection

    // Validate table and field names to allow only letters, numbers, and underscores
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
        return null; // Invalid table or field name
    }

    // Sanitize id as an integer
    $id = (int)$id;

    // Get the list of columns and their details from the table
    $columns_result = mysqli_query($mysqli, "SHOW COLUMNS FROM `$table`");
    if (!$columns_result || mysqli_num_rows($columns_result) == 0) {
        return null; // Table not found or has no columns
    }

    // Build an associative array with column details
    $columns = [];
    while ($row = mysqli_fetch_assoc($columns_result)) {
        $columns[$row['Field']] = [
            'type' => $row['Type'],
            'key'  => $row['Key']
        ];
    }

    // Find the primary key field if available
    $id_field = null;
    foreach ($columns as $col => $details) {
        if ($details['key'] === 'PRI') {
            $id_field = $col;
            break;
        }
    }
    // Fallback: if no primary key is found, use the first column
    if (!$id_field) {
        reset($columns);
        $id_field = key($columns);
    }

    // Ensure the requested field exists; if not, default to the id field
    if (!array_key_exists($field, $columns)) {
        $field = $id_field;
    }

    // Build and execute the query to fetch the specified field value
    $query = "SELECT `$field` FROM `$table` WHERE `$id_field` = $id";
    $sql = mysqli_query($mysqli, $query);

    if ($sql && mysqli_num_rows($sql) > 0) {
        $row = mysqli_fetch_assoc($sql);
        $value = $row[$field];

        // Apply the desired escaping method or auto-detect integer type if using SQL escaping
        switch ($escape_method) {
            case 'raw':
                return $value; // Return as-is from the database
            case 'html':
                return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); // Escape for HTML
            case 'json':
                return json_encode($value); // Escape for JSON
            case 'int':
                return (int)$value; // Explicitly cast value to integer
            case 'sql':
            default:
                // Auto-detect if the field type is integer
                if (stripos($columns[$field]['type'], 'int') !== false) {
                    return (int)$value;
                } else {
                    return sanitizeInput($value); // Escape for SQL using a custom function
                }
        }
    }

    return null; // Return null if no record was found
}

/**
 * Resolves the "from" email/name identity that should be used when sending mail
 * related to a specific ticket. If the ticket was created from (or is otherwise
 * associated with) an active, non-archived mailbox, that mailbox's identity is
 * used; otherwise falls back to the global config_ticket_from_email/name.
 *
 * @param int $ticket_id The ticket's id.
 *
 * @return array{email: string, name: string}
 */
function resolveTicketFromIdentity($ticket_id) {
    global $mysqli, $config_ticket_from_email, $config_ticket_from_name;
    $ticket_id = intval($ticket_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT m.mailbox_email, m.mailbox_from_name FROM tickets t
         JOIN mailboxes m ON m.mailbox_id = t.ticket_mailbox_id
         WHERE t.ticket_id = $ticket_id AND m.mailbox_archived_at IS NULL LIMIT 1"));
    if ($row && !empty($row['mailbox_email'])) {
        return ['email' => $row['mailbox_email'], 'name' => $row['mailbox_from_name'] ?: $config_ticket_from_name];
    }
    return ['email' => $config_ticket_from_email, 'name' => $config_ticket_from_name];
}

// Recursive function to display folder options - Used in folders files and documents
function display_folder_options($parent_folder_id, $client_id, $indent = 0) {
    global $mysqli;

    $sql_folders = mysqli_query($mysqli, "SELECT * FROM folders WHERE parent_folder = $parent_folder_id AND folder_client_id = $client_id AND folder_location != 2 ORDER BY folder_name ASC");
    while ($row = mysqli_fetch_assoc($sql_folders)) {
        $folder_id = intval($row['folder_id']);
        $folder_name = nullable_htmlentities($row['folder_name']);

        // Indentation for subfolders
        $indentation = str_repeat('&nbsp;', $indent * 4);

        // Check if this folder is selected
        $selected = '';
        if ((isset($_GET['folder_id']) && intval($_GET['folder_id']) === $folder_id) ||
            (isset($_POST['folder']) && intval($_POST['folder']) === $folder_id)) {
            $selected = 'selected';
        }

        echo "<option value=\"$folder_id\" $selected>$indentation$folder_name</option>";

        // Recursively display subfolders
        display_folder_options($folder_id, $client_id, $indent + 1);
    }
}

function sanitize_url($url) {
    $allowed = ['http', 'https', 'file', 'ftp', 'ftps', 'sftp', 'dav', 'webdav', 'caldav', 'carddav',  'ssh', 'telnet', 'smb', 'rdp', 'vnc', 'rustdesk', 'anydesk', 'connectwise', 'splashtop', 'sip', 'sips', 'ldap', 'ldaps'];
    $parts = parse_url($url ?? '');
    if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), $allowed)) {
        // Remove the scheme and colon
        $pos = strpos($url, ':');
        $without_scheme = $url;
        if ($pos !== false) {
            $without_scheme = substr($url, $pos + 1); // This keeps slashes (e.g. //pizza.com)
        }
        // Prepend 'unsupported://' (strip any leading slashes from $without_scheme to avoid triple slashes)
        $unsupported = 'unsupported://' . ltrim($without_scheme, '/');
        return htmlspecialchars($unsupported, ENT_QUOTES, 'UTF-8');
    }

    // Safe schemes: return escaped original URL
    return htmlspecialchars($url ?? '', ENT_QUOTES, 'UTF-8');
}

// Redirect Function
function redirect($url = null, $permanent = false) {
    // Use referer if no URL is provided
    if (!$url) {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        // Guard against a self-referential referrer (e.g. a plain GET action
        // link whose own URL gets reported back as HTTP_REFERER) - using it
        // as-is would send the browser right back to this same request,
        // forever, instead of somewhere it can actually land.
        if ($referer !== '' && parse_url($referer, PHP_URL_PATH) === ($_SERVER['SCRIPT_NAME'] ?? null)) {
            $referer = '';
        }
        $url = $referer !== '' ? $referer : 'index.php';
    }

    if (!headers_sent()) {
        header('Location: ' . $url, true, $permanent ? 301 : 302);
        exit;
    } else {
        // Fallback for headers already sent
        global $csp_nonce;
        echo "<script nonce=\"" . htmlspecialchars($csp_nonce ?? '') . "\">window.location.href = '" . addslashes($url) . "';</script>";
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '"></noscript>';
        exit;
    }
}

//Flash Alert Function
function flash_alert(string $message, string $type = 'success'): void {
    $_SESSION['alert_type'] = $type;
    $_SESSION['alert_message'] = $message;
}

// Sanitize File Names
function sanitize_filename($filename, $strict = false) {
    // Remove path information and dots around the filename
    $filename = basename($filename);

    // Replace spaces and underscores with dashes
    $filename = str_replace([' ', '_'], '-', $filename);

    // Remove anything which isn't a word, number, dot, or dash
    $filename = preg_replace('/[^A-Za-z0-9\.\-]/', '', $filename);

    // Optionally make filename strict alphanumeric (keep dot and dash)
    if ($strict) {
        $filename = preg_replace('/[^A-Za-z0-9\.\-]/', '', $filename);
    }

    // Avoid multiple consecutive dashes
    $filename = preg_replace('/-+/', '-', $filename);

    // Remove leading/trailing dots and dashes
    $filename = trim($filename, '.-');

    // Ensure it’s not empty
    if (empty($filename)) {
        $filename = 'file';
    }

    return $filename;
}

function saveBase64Images(string $html, string $baseFsPath, string $baseWebPath, int $ownerId): string {
    // Normalize paths
    $baseFsPath  = rtrim($baseFsPath, '/\\') . '/';
    $baseWebPath = rtrim($baseWebPath, '/\\') . '/';

    $targetDir = $baseFsPath . $ownerId . "/";

    $folderCreated = false;   // <-- NEW FLAG
    $savedAny      = false;   // <-- Track if ANY images processed

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $imgs = $dom->getElementsByTagName('img');

    foreach ($imgs as $img) {
        $src = $img->getAttribute('src');

        // Match base64 images
        if (preg_match('/^data:image\/([a-zA-Z0-9+]+);base64,(.*)$/s', $src, $matches)) {

            $savedAny = true;  // <-- We are actually saving at least 1 image

            // Create folder ONLY when needed
            if (!$folderCreated) {
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0775, true);
                }
                $folderCreated = true;
            }

            $mimeType = strtolower($matches[1]);
            $base64   = $matches[2];

            $binary = base64_decode($base64);
            if ($binary === false) {
                continue;
            }

            // Extension mapping
            switch ($mimeType) {
                case 'jpeg':
                case 'jpg': $ext = 'jpg'; break;
                case 'png': $ext = 'png'; break;
                case 'gif': $ext = 'gif'; break;
                case 'webp': $ext = 'webp'; break;
                default: $ext = 'png';
            }

            // Secure random filename
            $uid = bin2hex(random_bytes(16));
            $filename = "img_{$uid}.{$ext}";

            $filePath = $targetDir . $filename;

            if (file_put_contents($filePath, $binary) !== false) {
                $webPath = "/" . $baseWebPath . $ownerId . "/" . $filename;
                $img->setAttribute('src', $webPath);
            }
        }
    }

    // If no images were processed, return original HTML immediately
    if (!$savedAny) {
        return $html;
    }

    // Extract body content only
    $body = $dom->getElementsByTagName('body')->item(0);

    if ($body) {
        $innerHTML = '';
        foreach ($body->childNodes as $child) {
            $innerHTML .= $dom->saveHTML($child);
        }
        return $innerHTML;
    }

    return $html;
}

function cleanupUnusedImages(string $html, string $folderFsPath, string $folderWebPath) {

    $folderFsPath  = rtrim($folderFsPath, '/\\') . '/';
    $folderWebPath = rtrim($folderWebPath, '/\\') . '/';

    if (!is_dir($folderFsPath)) {
        return; // no folder = nothing to delete
    }

    // 1. Get all files currently on disk
    $filesOnDisk = glob($folderFsPath . "*");

    // 2. Find all <img src="">
    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches);
    $htmlImagePaths = $matches[1] ?? [];

    // Normalize paths: keep only filenames belonging to this template folder
    $referencedFiles = [];

    foreach ($htmlImagePaths as $src) {
        if (strpos($src, $folderWebPath) !== false) {
            $filename = basename($src);
            $referencedFiles[] = $filename;
        }
    }

    // 3. Delete any physical file not referenced in the HTML
    foreach ($filesOnDisk as $filePath) {
        $filename = basename($filePath);

        if (!in_array($filename, $referencedFiles)) {
            unlink($filePath);
        }
    }
}

/**
 * Simple mysqli helper functions
 * - Prepared statements under the hood
 * - "Old style" INSERT/UPDATE SET feeling
 */

/**
 * Core executor: prepares, binds, executes.
 *
 * @throws Exception on error
 */
function dbExecute(mysqli $mysqli, string $sql, array $params = []): mysqli_stmt
{
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('MySQLi prepare error: ' . $mysqli->error . ' | SQL: ' . $sql);
    }

    if (!empty($params)) {
        $types  = '';
        $values = [];

        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_bool($param)) {
                $types .= 'i';
                $param  = $param ? 1 : 0;
            } elseif (is_null($param)) {
                $types .= 's';
                $param  = null;
            } else {
                $types .= 's';
            }
            $values[] = $param;
        }

        if (!$stmt->bind_param($types, ...$values)) {
            throw new Exception('MySQLi bind_param error: ' . $stmt->error . ' | SQL: ' . $sql);
        }
    }

    if (!$stmt->execute()) {
        throw new Exception('MySQLi execute error: ' . $stmt->error . ' | SQL: ' . $sql);
    }

    return $stmt;
}

/**
 * Fetch all rows as associative arrays.
 */
function dbFetchAll(mysqli $mysqli, string $sql, array $params = []): array
{
    $stmt   = dbExecute($mysqli, $sql, $params);
    $result = $stmt->get_result();
    if ($result === false) {
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Fetch a single row (assoc) or null if none.
 */
function dbFetchOne(mysqli $mysqli, string $sql, array $params = []): ?array
{
    $stmt   = dbExecute($mysqli, $sql, $params);
    $result = $stmt->get_result();
    if ($result === false) {
        return null;
    }
    $row = $result->fetch_assoc();
    return $row !== null ? $row : null;
}

/**
 * Fetch a single scalar value (first column of first row) or null.
 */
function dbFetchValue(mysqli $mysqli, string $sql, array $params = [])
{
    $row = dbFetchOne($mysqli, $sql, $params);
    if ($row === null) {
        return null;
    }
    return reset($row);
}

/**
 * INSERT using "SET" style.
 * Example:
 *   $id = dbInsert($mysqli, 'clients', [
 *       'client_name' => $name,
 *       'client_type' => $type,
 *   ]);
 *
 * @return int insert_id
 *
 * @throws InvalidArgumentException
 * @throws Exception
 */
function dbInsert(mysqli $mysqli, string $table, array $data): int
{
    if (empty($data)) {
        throw new InvalidArgumentException('dbInsert called with empty $data');
    }

    $setParts = [];
    foreach ($data as $column => $_) {
        $setParts[] = "$column = ?";
    }

    $sql    = "INSERT INTO $table SET " . implode(', ', $setParts);
    $params = array_values($data);

    dbExecute($mysqli, $sql, $params);

    return $mysqli->insert_id;
}

function dbUpdate(
    mysqli $mysqli,
    string $table,
    array $data,
    $where,
    array $whereParams = []
): int {
    if (empty($data)) {
        throw new InvalidArgumentException('dbUpdate called with empty $data');
    }
    if (empty($where)) {
        throw new InvalidArgumentException('dbUpdate requires a WHERE clause');
    }

    $setParts = [];
    foreach ($data as $column => $_) {
        $setParts[] = "$column = ?";
    }

    if (is_array($where)) {
        $whereParts  = [];
        $whereParams = [];
        foreach ($where as $column => $value) {
            $whereParts[]  = "$column = ?";
            $whereParams[] = $value;
        }
        $whereSql = implode(' AND ', $whereParts);
    } else {
        $whereSql = $where;
    }

    $sql    = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE $whereSql";
    $params = array_merge(array_values($data), $whereParams);

    $stmt = dbExecute($mysqli, $sql, $params);
    return $stmt->affected_rows;
}

/**
 * DELETE helper.
 *
 * WHERE can be:
 *   - array: ['client_id' => $id] (auto "client_id = ?")
 *   - string: 'client_id = ?' (use with $whereParams)
 *
 * @return int affected_rows
 *
 * @throws InvalidArgumentException
 * @throws Exception
 */
function dbDelete(
    mysqli $mysqli,
    string $table,
    $where,
    array $whereParams = []
): int {
    if (empty($where)) {
        throw new InvalidArgumentException('dbDelete requires a WHERE clause');
    }

    if (is_array($where)) {
        $whereParts  = [];
        $whereParams = [];
        foreach ($where as $column => $value) {
            $whereParts[]  = "$column = ?";
            $whereParams[] = $value;
        }
        $whereSql = implode(' AND ', $whereParts);
    } else {
        $whereSql = $where;
    }

    $sql  = "DELETE FROM $table WHERE $whereSql";
    $stmt = dbExecute($mysqli, $sql, $whereParams);
    return $stmt->affected_rows;
}

/**
 * Transaction helpers (optional sugar).
 */
function dbBegin(mysqli $mysqli): void
{
    $mysqli->begin_transaction();
}

function dbCommit(mysqli $mysqli): void
{
    $mysqli->commit();
}

function dbRollback(mysqli $mysqli): void
{
    $mysqli->rollback();
}

function formatDuration($time) {
    // expects "HH:MM:SS"
    $parts_in = array_map('intval', explode(':', (string) $time));
    $parts_in = array_pad($parts_in, 3, 0);
    [$h, $m, $s] = $parts_in;

    $parts = [];

    if ($h > 0) $parts[] = $h . 'h';
    if ($m > 0) $parts[] = $m . 'm';

    // show seconds only if under 1 minute total OR if nothing else exists
    if ($h == 0 && $m == 0) {
        $parts[] = $s . 's';
    }

    return implode(' ', $parts);
}

function validateDate($date) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    return date('Y-m-d'); // Fallback
}

function ticketCategoryOptions($mysqli, $selected = 0) {
    $out = '';
    $sql_groups = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL ORDER BY category_name ASC");
    $groups = [];
    while ($g = mysqli_fetch_assoc($sql_groups)) $groups[] = $g;

    $sql_subs = mysqli_query($mysqli, "SELECT category_id, category_name, category_parent FROM categories WHERE category_type = 'Ticket' AND category_parent > 0 AND category_archived_at IS NULL ORDER BY category_name ASC");
    $subs = [];
    while ($s = mysqli_fetch_assoc($sql_subs)) $subs[intval($s['category_parent'])][] = $s;

    foreach ($groups as $g) {
        $gid = intval($g['category_id']);
        $gname = nullable_htmlentities($g['category_name']);
        if (isset($subs[$gid])) {
            $out .= "<optgroup label=\"$gname\">";
            foreach ($subs[$gid] as $s) {
                $sel = intval($s['category_id']) == $selected ? ' selected' : '';
                $out .= "<option value=\"{$s['category_id']}\"$sel>" . nullable_htmlentities($s['category_name']) . "</option>";
            }
            $out .= "</optgroup>";
        } else {
            $sel = $gid == $selected ? ' selected' : '';
            $out .= "<option value=\"$gid\"$sel>$gname</option>";
        }
    }
    // Orphaned sub-categories (parent archived/missing)
    $all_group_ids = array_column($groups, 'category_id');
    foreach ($subs as $pid => $children) {
        if (!in_array($pid, $all_group_ids)) {
            foreach ($children as $s) {
                $sel = intval($s['category_id']) == $selected ? ' selected' : '';
                $out .= "<option value=\"{$s['category_id']}\"$sel>" . nullable_htmlentities($s['category_name']) . "</option>";
            }
        }
    }
    return $out;
}

// ─── Outbound Webhooks ────────────────────────────────────────────────────────

function getWebhookTicketPayload($ticket_id) {
    global $mysqli;
    $tid = intval($ticket_id);
    $sql = mysqli_query($mysqli,
        "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject,
                t.ticket_priority, t.ticket_client_id, t.ticket_assigned_to,
                t.ticket_contact_id, ts.ticket_status_name,
                c.client_name, co.contact_name, u.user_name AS assigned_user_name
         FROM tickets t
         LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
         LEFT JOIN clients c ON t.ticket_client_id = c.client_id
         LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id
         LEFT JOIN users u ON t.ticket_assigned_to = u.user_id
         WHERE t.ticket_id = $tid LIMIT 1"
    );
    if (!$sql || !($row = mysqli_fetch_assoc($sql))) {
        return ['ticket_id' => $tid];
    }
    return [
        'ticket_id'            => intval($row['ticket_id']),
        'ticket_number'        => $row['ticket_prefix'] . $row['ticket_number'],
        'ticket_subject'       => $row['ticket_subject'],
        'ticket_priority'      => $row['ticket_priority'],
        'ticket_status'        => $row['ticket_status_name'],
        'client_id'            => intval($row['ticket_client_id']),
        'client_name'          => $row['client_name'],
        'contact_id'           => intval($row['ticket_contact_id']),
        'contact_name'         => $row['contact_name'],
        'assigned_to_user_id'  => intval($row['ticket_assigned_to']),
        'assigned_to_user_name'=> $row['assigned_user_name'],
    ];
}

function queueWebhookEvent($event, $data) {
    global $mysqli;
    $event_safe = mysqli_real_escape_string($mysqli, $event);
    $payload    = json_encode([
        'event'     => $event,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'data'      => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $payload_safe = mysqli_real_escape_string($mysqli, $payload);
    $sql = mysqli_query($mysqli,
        "SELECT webhook_id FROM webhooks
         WHERE webhook_enabled = 1
           AND FIND_IN_SET('$event_safe', REPLACE(webhook_events, ', ', ','))"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $wid = intval($row['webhook_id']);
        mysqli_query($mysqli,
            "INSERT INTO webhook_queue SET queue_webhook_id = $wid, queue_event = '$event_safe', queue_payload = '$payload_safe'"
        );
    }
}

// Encrypts a sensitive settings value (SMTP password, OAuth secret, etc.) using
// a per-installation key from config.php. Values are prefixed with "ENC:" so
// legacy plaintext can still be read transparently (backward-compatible).
function encryptSetting(string $plaintext): string {
    global $config_settings_enc_key;
    if (empty($plaintext) || empty($config_settings_enc_key)) return $plaintext;
    $key = substr(hash('sha256', $config_settings_enc_key, true), 0, 16);
    $iv  = random_bytes(16);
    $ct  = openssl_encrypt($plaintext, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return 'ENC:' . base64_encode($iv . $ct);
}

function decryptSetting(string $ciphertext): string {
    global $config_settings_enc_key;
    if (empty($ciphertext) || empty($config_settings_enc_key)) return $ciphertext;
    if (!str_starts_with($ciphertext, 'ENC:')) return $ciphertext; // legacy plaintext
    $data = base64_decode(substr($ciphertext, 4));
    if (strlen($data) <= 16) return '';
    $key = substr(hash('sha256', $config_settings_enc_key, true), 0, 16);
    $iv  = substr($data, 0, 16);
    $ct  = substr($data, 16);
    return openssl_decrypt($ct, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv) ?: '';
}

// ── CRM / Sales helpers ────────────────────────────────────────────────────

// Ordered list of pipeline stages with their suggested default win probability.
// Won/Lost are terminal stages that also drive opportunity_status.
function getOpportunityStages() {
    return array(
        'Qualification' => 20,
        'Proposal'      => 40,
        'Negotiation'   => 60,
        'Won'           => 100,
        'Lost'          => 0,
    );
}

// Returns the Bootstrap contextual colour used for a given pipeline stage badge.
function opportunityStageColor($stage) {
    switch ($stage) {
        case 'Won':          return 'success';
        case 'Lost':         return 'danger';
        case 'Negotiation':  return 'warning';
        case 'Proposal':     return 'info';
        case 'Qualification':
        default:             return 'secondary';
    }
}

// Maps a pipeline stage to the opportunity_status it should set.
// Won/Lost are terminal; everything else keeps the deal open.
function opportunityStatusForStage($stage) {
    if ($stage === 'Won') {
        return 'won';
    }
    if ($stage === 'Lost') {
        return 'lost';
    }
    return 'open';
}

