<?php

/*
 * API - validate_api_key.php
 * Called by API endpoint to validate API key is valid
 * Allows execution to continue or exits returning errors to the user
 */

// Includes
require_once __DIR__ . '../../../functions.php';
require_once __DIR__ . "../../../config.php";

// JSON header
header('Content-Type: application/json');

// POST data
$_POST = json_decode(file_get_contents('php://input'), true);

// Get IP & UA
$ip = sanitizeInput(getIP());
$user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT']);

// Temp Added this to work with the new logAction function
$session_ip = $ip;
$session_user_agent = $user_agent;

// Setup return array
$return_arr = array();

// Unauthorised wording
DEFINE("WORDING_UNAUTHORIZED", "HTTP/1.1 401 Unauthorized");

/*
 * API Notes:
 *
 * To avoid over-complicating the app by using PUT and DELETE methods, only going to allow the use of GET and POST methods.
 * GET - Retrieving (READ) data
 * POST - Inserting (CREATE), Updating (UPDATE) or Deleting (DELETE) data
 *
 * Data returned as json encoded $return_arr:-
     * Success - True/False
     * Message - Brief info about a request / failure
     * Count - Count of rows affected/returned
     * Data - Requested data
 *
 */

// Decline methods other than GET/POST
if ($_SERVER['REQUEST_METHOD'] !== "GET" && $_SERVER['REQUEST_METHOD'] !== "POST") {
    header("HTTP/1.1 405 Method Not Allowed");
    echo json_encode(['success' => 'False', 'message' => 'Method not allowed. Only GET and POST are supported.']);
    exit();
}

// Check API key is provided (header preferred; query param kept for backward compat)
if (empty($_SERVER['HTTP_X_API_KEY']) && !isset($_GET['api_key']) && !isset($_POST['api_key'])) {
    header(WORDING_UNAUTHORIZED);
    exit();
}

// Set API key variable — X-Api-Key header takes priority so the key stays out of server logs
if (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $api_key = sanitizeInput($_SERVER['HTTP_X_API_KEY']);
} elseif (isset($_GET['api_key'])) {
    $api_key = sanitizeInput($_GET['api_key']);
} elseif (isset($_POST['api_key'])) {
    $api_key = sanitizeInput($_POST['api_key']);
}

// Rate limiting: this legacy endpoint is reachable directly (nginx serves it as a
// standalone script via try_files, bypassing api/v1/index.php's router entirely)
// and had no throttling at all against a 64-char sha256-hashed-key guessing surface.
// Mirrors login.php's IP-based lockout: 15 failures in 10 minutes -> 429.
$rl_ip = mysqli_real_escape_string($mysqli, $ip);
$rl_failed = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(log_id) AS c FROM logs
     WHERE log_type = 'API' AND log_action = 'Failed' AND log_ip = '$rl_ip'
       AND log_created_at > (NOW() - INTERVAL 10 MINUTE)"
));
if (intval($rl_failed['c'] ?? 0) >= 15) {
    header("HTTP/1.1 429 Too Many Requests");
    echo json_encode(['success' => 'False', 'message' => 'Too many failed attempts. Please try again later.']);
    exit();
}

// Validate API key
if (isset($api_key)) {
    $api_key = sanitizeInput($api_key);

    $api_key_hash = hash('sha256', $api_key);
    $api_key_hash = mysqli_real_escape_string($mysqli, $api_key_hash);
    $sql = mysqli_query($mysqli, "SELECT * FROM api_keys WHERE api_key_secret = '$api_key_hash' AND api_key_expire > NOW() LIMIT 1");

    // Failed
    if (mysqli_num_rows($sql) !== 1) {
        // Invalid Key

        $url_path = sanitizeInput(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH));
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'API', log_action = 'Failed', log_description = 'Incorrect or expired key (endpoint: $url_path)', log_ip = '$ip', log_user_agent = '$user_agent'");

        $return_arr['success'] = "False";
        $return_arr['message'] = "Authentication failed. API key is invalid or has expired.";

        header(WORDING_UNAUTHORIZED);
        echo json_encode($return_arr);
        exit();

    } else {

        // SUCCESS

        // General per-request throttle for authenticated calls, mirroring
        // api/v1/index.php's Redis-backed cap (300 req/60s), keyed by the
        // hashed API key so one caller can't starve others or scrape data
        // unbounded now that they've passed authentication.
        if (!defined('FROM_API')) {
            define('FROM_API', true);
        }
        require_once __DIR__ . '/includes/api_ratelimit.php';
        if (!api_rate_limit('key:' . substr($api_key_hash, 0, 40), 300, 60)) {
            header("HTTP/1.1 429 Too Many Requests");
            echo json_encode(['success' => 'False', 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }

        // Set client ID, company ID & key name
        $row = mysqli_fetch_assoc($sql);
        $api_key_name = htmlentities($row['api_key_name']);
        $api_key_decrypt_hash = $row['api_key_decrypt_hash']; // No sanitization
        $client_id = intval($row['api_key_client_id']);

        // Set limit & offset for queries
        if (isset($_GET['limit'])) {
            $limit = intval($_GET['limit']);
        } elseif (isset($_POST['limit'])) {
            $limit = intval($_POST['limit']);
        } else {
            $limit = 50;
        }

        if (isset($_GET['offset'])) {
            $offset = intval($_GET['offset']);
        } elseif (isset($_POST['offset'])) {
            $offset = intval($_POST['offset']);
        } else {
            $offset = 0;
        }

    }
}
